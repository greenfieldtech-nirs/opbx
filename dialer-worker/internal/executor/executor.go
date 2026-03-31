package executor

import (
	"context"
	"fmt"
	"sync"
	"time"

	"github.com/nirsolutions/opbx-dialer-worker/internal/api"
	"github.com/nirsolutions/opbx-dialer-worker/internal/circuitbreaker"
	"github.com/nirsolutions/opbx-dialer-worker/internal/cloudonix"
	"github.com/nirsolutions/opbx-dialer-worker/internal/metrics"
	"github.com/nirsolutions/opbx-dialer-worker/internal/retry"
	"github.com/nirsolutions/opbx-dialer-worker/pkg/models"
	"github.com/rs/zerolog/log"
	"golang.org/x/time/rate"
)

// Executor handles the execution of outbound calls for campaigns
type Executor struct {
	laravelClient *api.Client
	retryQueue    *retry.Queue
	breaker       *circuitbreaker.Breaker
	metrics       *metrics.Collector

	// Concurrency control
	globalLimiter *rate.Limiter
	activeCalls   map[string]*CallContext
	campaignCalls map[int64]map[string]bool // campaignID -> set of callIDs
	mu            sync.RWMutex
	maxConcurrent int
}

// CallContext holds context for an active call
type CallContext struct {
	CampaignID      int64
	DestinationID   int64
	SessionID       string
	CallID          string
	StartedAt       time.Time
	CloudonixDomain string // Domain for hangup (from campaign credentials)
	CloudonixAPIKey string // API key for hangup (from campaign credentials)
}

// Config holds executor configuration
type Config struct {
	MaxConcurrentGlobal int
	DefaultCallTimeout  int
	RateLimitPerSecond  int
}

// NewExecutor creates a new call executor
func NewExecutor(
	laravelClient *api.Client,
	retryQueue *retry.Queue,
	breaker *circuitbreaker.Breaker,
	metrics *metrics.Collector,
	cfg Config,
) *Executor {
	return &Executor{
		laravelClient: laravelClient,
		retryQueue:    retryQueue,
		breaker:       breaker,
		metrics:       metrics,
		globalLimiter: rate.NewLimiter(rate.Limit(cfg.RateLimitPerSecond), cfg.RateLimitPerSecond),
		activeCalls:   make(map[string]*CallContext),
		campaignCalls: make(map[int64]map[string]bool),
		maxConcurrent: cfg.MaxConcurrentGlobal,
	}
}

// createCloudonixClient creates a Cloudonix client for the given campaign
func (e *Executor) createCloudonixClient(campaign *models.Campaign) *cloudonix.Client {
	// Use campaign-specific credentials from Laravel backend
	apiURL := campaign.CloudonixAPIURL
	if apiURL == "" {
		apiURL = "https://api.cloudonix.io"
	}
	return cloudonix.NewClient(apiURL, campaign.CloudonixAPIKey, campaign.CloudonixDomain)
}

// ExecuteCampaign begins executing calls for a campaign
func (e *Executor) ExecuteCampaign(ctx context.Context, campaign *models.Campaign) {
	log.Info().
		Int64("campaign_id", campaign.ID).
		Str("campaign_name", campaign.Name).
		Msg("Starting campaign execution")

	// Get pending destinations
	destinations, err := e.laravelClient.GetPendingDestinations(ctx, campaign.ID, e.maxConcurrent)
	if err != nil {
		log.Error().
			Err(err).
			Int64("campaign_id", campaign.ID).
			Msg("Failed to fetch pending destinations")
		return
	}

	log.Info().
		Int64("campaign_id", campaign.ID).
		Int("destination_count", len(destinations)).
		Msg("Fetched pending destinations")

	// Execute calls for each destination
	for _, dest := range destinations {
		// Check circuit breaker
		if e.breaker.IsOpen() {
			log.Warn().Msg("Circuit breaker is open, pausing campaign execution")
			e.pauseCampaign(ctx, campaign.ID, "ai_agent_errors")
			return
		}

		// Wait for rate limiter
		if err := e.globalLimiter.Wait(ctx); err != nil {
			log.Error().Err(err).Msg("Rate limiter wait failed")
			continue
		}

		// Check concurrency limit
		if !e.canStartNewCall() {
			log.Debug().Msg("At max concurrent calls, waiting")
			time.Sleep(time.Second)
			continue
		}

		go e.executeCall(ctx, campaign, &dest)
	}
}

// StopCampaign stops all active calls for a campaign
func (e *Executor) StopCampaign(campaignID int64) {
	e.mu.Lock()
	callIDs, exists := e.campaignCalls[campaignID]
	if !exists {
		e.mu.Unlock()
		return
	}
	delete(e.campaignCalls, campaignID)

	// Copy call IDs to avoid holding lock during API calls
	callsToStop := make([]string, 0, len(callIDs))
	for callID := range callIDs {
		callsToStop = append(callsToStop, callID)
		delete(e.activeCalls, callID)
	}
	e.mu.Unlock()

	log.Info().
		Int64("campaign_id", campaignID).
		Int("call_count", len(callsToStop)).
		Msg("Stopping campaign calls")

	// Hangup active calls - each call may have different credentials
	for _, callID := range callsToStop {
		callCtx, exists := e.activeCalls[callID]
		if !exists {
			continue
		}

		// Create client with this call's stored credentials
		cloudonixClient := cloudonix.NewClient(
			"https://api.cloudonix.io",
			callCtx.CloudonixAPIKey,
			callCtx.CloudonixDomain,
		)

		ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
		if err := cloudonixClient.HangupCall(ctx, callID); err != nil {
			log.Error().
				Err(err).
				Str("call_id", callID).
				Msg("Failed to hangup call")
		}
		cancel()
	}
}

// executeCall initiates a single outbound call
func (e *Executor) executeCall(ctx context.Context, campaign *models.Campaign, dest *models.Destination) {
	// Initiate call session in Laravel
	initReq := &models.InitiateCallRequest{
		DestinationID:    dest.ID,
		CampaignID:       campaign.ID,
		OrganizationID:   campaign.OrganizationID,
		AIAgentID:        campaign.AIAgentID,
		FromNumber:       campaign.FromNumber,
		ToNumber:         dest.PhoneNumber,
		CustomParameters: campaign.CustomParameters,
		AMDEnabled:       campaign.AMDEnabled,
		AMDTimeout:       campaign.AMDTimeout,
		AMDIntroTimeout:  campaign.AMDIntroTimeout,
	}

	initResp, err := e.laravelClient.InitiateCallSession(ctx, campaign.ID, initReq)
	if err != nil {
		log.Error().
			Err(err).
			Int64("destination_id", dest.ID).
			Msg("Failed to initiate call session")
		e.retryQueue.Add(dest.ID, "initiation_failed")
		return
	}

	log.Info().
		Str("session_id", initResp.SessionID).
		Int64("destination_id", dest.ID).
		Str("to_number", dest.PhoneNumber).
		Msg("Call session initiated")

	// Make the actual outbound call via Cloudonix
	// Create a client using this campaign's organization-specific credentials
	cloudonixClient := e.createCloudonixClient(campaign)

	cloudonixReq := &cloudonix.OutboundCallRequest{
		From:        campaign.FromNumber,
		To:          dest.PhoneNumber,
		CallbackURL: initResp.CallbackURL,
		CustomParameters: map[string]interface{}{
			"session_id":     initResp.SessionID,
			"campaign_id":    campaign.ID,
			"destination_id": dest.ID,
			"ai_agent_id":    campaign.AIAgentID,
		},
		Timeout:                campaign.DefaultTimeout,
		AMDEnabled:             campaign.AMDEnabled,
		AMDTimeout:             campaign.AMDTimeout,
		RoutingDestinationType: campaign.RoutingDestinationType,
		RoutingDestinationID:   campaign.RoutingDestinationID,
	}

	callResp, err := cloudonixClient.MakeOutboundCall(ctx, cloudonixReq)
	if err != nil {
		log.Error().
			Err(err).
			Str("session_id", initResp.SessionID).
			Msg("Failed to make outbound call")

		// Update call status to failed
		updateCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		e.laravelClient.UpdateCallStatus(updateCtx, initResp.SessionID, &models.UpdateCallStatusRequest{
			Status: "failed",
			Error:  err.Error(),
		})
		cancel()

		e.retryQueue.Add(dest.ID, "dial_failed")
		e.metrics.RecordCallFailed(campaign.ID)
		return
	}

	// Track active call (store credentials for hangup)
	callCtx := &CallContext{
		CampaignID:      campaign.ID,
		DestinationID:   dest.ID,
		SessionID:       initResp.SessionID,
		CallID:          callResp.CallID,
		StartedAt:       time.Now(),
		CloudonixDomain: campaign.CloudonixDomain,
		CloudonixAPIKey: campaign.CloudonixAPIKey,
	}

	e.mu.Lock()
	e.activeCalls[callResp.CallID] = callCtx
	if e.campaignCalls[campaign.ID] == nil {
		e.campaignCalls[campaign.ID] = make(map[string]bool)
	}
	e.campaignCalls[campaign.ID][callResp.CallID] = true
	e.mu.Unlock()

	e.metrics.RecordCallInitiated(campaign.ID)

	log.Info().
		Str("call_id", callResp.CallID).
		Str("session_id", initResp.SessionID).
		Int64("campaign_id", campaign.ID).
		Msg("Outbound call initiated successfully")
}

// HandleCallEvent processes webhook events from Cloudonix
func (e *Executor) HandleCallEvent(ctx context.Context, event *models.CloudonixWebhookEvent) error {
	e.mu.Lock()
	callCtx, exists := e.activeCalls[event.CallID]
	if !exists {
		e.mu.Unlock()
		return fmt.Errorf("call %s not found in active calls", event.CallID)
	}
	delete(e.activeCalls, event.CallID)
	delete(e.campaignCalls[callCtx.CampaignID], event.CallID)
	e.mu.Unlock()

	// Process based on event type
	switch event.EventType {
	case "call.completed":
		return e.handleCallCompleted(ctx, callCtx, event)
	case "call.failed":
		return e.handleCallFailed(ctx, callCtx, event)
	case "amd.completed":
		return e.handleAMDCompleted(ctx, callCtx, event)
	default:
		log.Debug().
			Str("event_type", event.EventType).
			Str("call_id", event.CallID).
			Msg("Unhandled event type")
	}

	return nil
}

// handleCallCompleted processes completed calls
func (e *Executor) handleCallCompleted(ctx context.Context, callCtx *CallContext, event *models.CloudonixWebhookEvent) error {
	duration := time.Since(callCtx.StartedAt).Seconds()

	// Record disposition
	dispReq := &models.DispositionRequest{
		SessionID:       callCtx.SessionID,
		Status:          event.Status,
		DurationSeconds: int(duration),
		AMDResult:       &event.AMDResult,
		RecordingURL:    event.RecordingURL,
		Transcript:      event.Transcript,
	}

	if err := e.laravelClient.SetDisposition(ctx, callCtx.CampaignID, dispReq); err != nil {
		log.Error().
			Err(err).
			Str("session_id", callCtx.SessionID).
			Msg("Failed to set disposition")
		return err
	}

	e.metrics.RecordCallCompleted(callCtx.CampaignID, duration)

	log.Info().
		Str("session_id", callCtx.SessionID).
		Str("status", event.Status).
		Float64("duration", duration).
		Msg("Call completed")

	return nil
}

// handleCallFailed processes failed calls
func (e *Executor) handleCallFailed(ctx context.Context, callCtx *CallContext, event *models.CloudonixWebhookEvent) error {
	// Update status
	updateReq := &models.UpdateCallStatusRequest{
		Status: event.Status,
		Error:  event.Error,
	}

	if err := e.laravelClient.UpdateCallStatus(ctx, callCtx.SessionID, updateReq); err != nil {
		log.Error().
			Err(err).
			Str("session_id", callCtx.SessionID).
			Msg("Failed to update call status")
	}

	// Add to retry queue if applicable
	e.retryQueue.Add(callCtx.DestinationID, event.Status)
	e.metrics.RecordCallFailed(callCtx.CampaignID)

	// Check if we should open circuit breaker
	if event.Error == "ai_agent_error" {
		e.breaker.RecordFailure()
	}

	log.Warn().
		Str("session_id", callCtx.SessionID).
		Str("error", event.Error).
		Msg("Call failed")

	return nil
}

// handleAMDCompleted processes AMD results
func (e *Executor) handleAMDCompleted(ctx context.Context, callCtx *CallContext, event *models.CloudonixWebhookEvent) error {
	// Update call with AMD result
	updateReq := &models.UpdateCallStatusRequest{
		Status:    "amd_completed",
		AMDResult: &event.AMDResult,
	}

	if err := e.laravelClient.UpdateCallStatus(ctx, callCtx.SessionID, updateReq); err != nil {
		log.Error().
			Err(err).
			Str("session_id", callCtx.SessionID).
			Msg("Failed to update AMD result")
		return err
	}

	log.Info().
		Str("session_id", callCtx.SessionID).
		Str("amd_result", event.AMDResult.Result).
		Msg("AMD completed")

	return nil
}

// canStartNewCall checks if we can start a new call
func (e *Executor) canStartNewCall() bool {
	e.mu.RLock()
	defer e.mu.RUnlock()
	return len(e.activeCalls) < e.maxConcurrent
}

// pauseCampaign pauses a campaign
func (e *Executor) pauseCampaign(ctx context.Context, campaignID int64, reason string) {
	if err := e.laravelClient.PauseCampaign(ctx, campaignID, reason); err != nil {
		log.Error().
			Err(err).
			Int64("campaign_id", campaignID).
			Msg("Failed to pause campaign")
	}
}
