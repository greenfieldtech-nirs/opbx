// Package executor handles the execution of outbound calls for campaigns.
//
// The executor is responsible for:
//   - Managing campaign execution cycles
//   - Enforcing CAC (Concurrent Active Calls) limits via Redis
//   - Rate limiting API calls to Cloudonix (60/CAC seconds)
//   - Handling CDR events to release concurrency slots
//   - Managing retries for failed calls
package executor

import (
	"context"
	"errors"
	"fmt"
	"sync"
	"time"

	"github.com/nirsolutions/opbx-dialer-worker/internal/api"
	"github.com/nirsolutions/opbx-dialer-worker/internal/circuitbreaker"
	"github.com/nirsolutions/opbx-dialer-worker/internal/cloudonix"
	"github.com/nirsolutions/opbx-dialer-worker/internal/concurrency"
	"github.com/nirsolutions/opbx-dialer-worker/internal/metrics"
	"github.com/nirsolutions/opbx-dialer-worker/internal/retry"
	"github.com/nirsolutions/opbx-dialer-worker/pkg/models"
	"github.com/rs/zerolog/log"
)

// Executor handles the execution of outbound calls for campaigns.
// It coordinates with the concurrency manager to enforce CAC limits
// and manages the lifecycle of call execution.
type Executor struct {
	laravelClient      *api.Client
	retryQueue         *retry.Queue
	breaker            *circuitbreaker.Breaker
	metrics            *metrics.Collector
	concurrencyManager *concurrency.Manager
	workerID           string

	// Active call tracking (local cache)
	activeCalls   map[string]*CallContext   // callID -> context
	campaignCalls map[int64]map[string]bool // campaignID -> set of callIDs
	mu            sync.RWMutex
}

// CallContext holds context for an active call.
// This is stored locally while the call is active.
type CallContext struct {
	CampaignID      int64
	DestinationID   int64
	SessionID       int64
	CallID          string
	StartedAt       time.Time
	CloudonixDomain string // Domain for hangup (from campaign credentials)
	CloudonixAPIKey string // API key for hangup (from campaign credentials)
}

// Config holds executor configuration.
type Config struct {
	MaxConcurrentGlobal int
	DefaultCallTimeout  int
}

// NewExecutor creates a new call executor.
//
// Parameters:
//   - laravelClient: Client for Laravel API communication
//   - retryQueue: Queue for managing retry attempts
//   - breaker: Circuit breaker for handling errors
//   - metrics: Metrics collector
//   - concurrencyManager: Redis-backed concurrency manager
//   - workerID: Unique identifier for this worker
//
// Returns a new Executor instance.
func NewExecutor(
	laravelClient *api.Client,
	retryQueue *retry.Queue,
	breaker *circuitbreaker.Breaker,
	metrics *metrics.Collector,
	concurrencyManager *concurrency.Manager,
	workerID string,
) *Executor {
	return &Executor{
		laravelClient:      laravelClient,
		retryQueue:         retryQueue,
		breaker:            breaker,
		metrics:            metrics,
		concurrencyManager: concurrencyManager,
		workerID:           workerID,
		activeCalls:        make(map[string]*CallContext),
		campaignCalls:      make(map[int64]map[string]bool),
	}
}

// createCloudonixClient creates a Cloudonix client for the given campaign.
// Uses campaign-specific credentials from the Laravel backend.
func (e *Executor) createCloudonixClient(campaign *models.Campaign) *cloudonix.Client {
	apiURL := campaign.CloudonixAPIURL
	if apiURL == "" {
		apiURL = "https://api.cloudonix.io"
	}
	return cloudonix.NewClient(apiURL, campaign.CloudonixAPIKey, campaign.CloudonixDomain)
}

// ExecuteCampaign begins executing calls for a campaign.
//
// This method runs the campaign execution loop which:
//   1. Fetches pending destinations
//   2. Waits for CAC slots to become available
//   3. Waits for the API rate limit interval (60/CAC seconds)
//   4. Initiates calls via Cloudonix API
//   5. Updates Redis concurrency state
//
// Parameters:
//   - ctx: Context for the execution
//   - campaign: The campaign to execute
func (e *Executor) ExecuteCampaign(ctx context.Context, campaign *models.Campaign) {
	log.Info().
		Int64("campaign_id", campaign.ID).
		Str("campaign_name", campaign.Name).
		Int("cac", campaign.ConcurrentActiveCalls).
		Float64("api_interval", campaign.GetApiIntervalSeconds()).
		Msg("Starting campaign execution")

	// Calculate API interval: 60 / CAC seconds
	apiInterval := time.Duration(campaign.GetApiIntervalSeconds() * float64(time.Second))

	// Execution loop
	for {
		// Check if context is cancelled
		if ctx.Err() != nil {
			log.Info().
				Int64("campaign_id", campaign.ID).
				Msg("Campaign execution stopped due to context cancellation")
			return
		}

		// Check circuit breaker
		if e.breaker.IsOpen() {
			log.Warn().Msg("Circuit breaker is open, pausing campaign execution")
			e.pauseCampaign(ctx, campaign.ID, "ai_agent_errors")
			return
		}

		// Check if we can start a new call (CAC limit)
		if !e.concurrencyManager.CanStartCall(ctx, campaign.ID, campaign.ConcurrentActiveCalls) {
			activeCount, _ := e.concurrencyManager.GetActiveCount(ctx, campaign.ID)
			log.Debug().
				Int64("campaign_id", campaign.ID).
				Int("active_calls", activeCount).
				Int("cac_limit", campaign.ConcurrentActiveCalls).
				Msg("At CAC limit, waiting for slot")
			time.Sleep(1 * time.Second)
			continue
		}

		// Get pending destinations
		destinations, err := e.laravelClient.GetPendingDestinations(ctx, campaign.ID, 1)
		if err != nil {
			log.Error().
				Err(err).
				Int64("campaign_id", campaign.ID).
				Msg("Failed to fetch pending destinations")
			time.Sleep(5 * time.Second)
			continue
		}

		if len(destinations) == 0 {
			log.Debug().
				Int64("campaign_id", campaign.ID).
				Msg("No pending destinations, sleeping")
			time.Sleep(5 * time.Second)
			continue
		}

		// Execute the call
		e.executeCall(ctx, campaign, &destinations[0])

		// Wait for API rate limit interval before next call
		log.Debug().
			Int64("campaign_id", campaign.ID).
			Dur("interval", apiInterval).
			Msg("Waiting for API rate limit interval")
		time.Sleep(apiInterval)
	}
}

// executeCall initiates a single outbound call.
//
// This method:
//   1. Creates a Laravel session
//   2. Generates CXML for routing
//   3. Calls Cloudonix API
//   4. Updates Redis concurrency counter on success
//   5. Handles HTTP 429 by pausing campaign immediately
//
// Parameters:
//   - ctx: Context for the execution
//   - campaign: The campaign
//   - dest: The destination to call
func (e *Executor) executeCall(ctx context.Context, campaign *models.Campaign, dest *models.Destination) {
	log.Info().
		Int64("campaign_id", campaign.ID).
		Int64("destination_id", dest.ID).
		Str("phone_number", dest.PhoneNumber).
		Msg("Executing call")

	// Initiate call session in Laravel
	initReq := &models.InitiateCallRequest{
		CampaignID:    campaign.ID,
		DestinationID: dest.ID,
		PhoneNumber:   dest.PhoneNumber,
		WorkerID:      e.workerID,
		InitiatedAt:   time.Now().UTC(),
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
		Int64("session_id", initResp.SessionID).
		Msg("Call session initiated")

	// Generate a unique call SID
	callSid := fmt.Sprintf("call_%d_%d_%d", campaign.ID, dest.ID, time.Now().Unix())

	// Fetch CXML for routing
	cxmlReq := &models.GenerateCXMLRequest{
		CampaignID:  campaign.ID,
		SessionID:   initResp.SessionID,
		PhoneNumber: dest.PhoneNumber,
		CallSid:     callSid,
	}

	cxmlResp, err := e.laravelClient.GenerateCXML(ctx, cxmlReq)
	if err != nil {
		log.Error().
			Err(err).
			Int64("session_id", initResp.SessionID).
			Msg("Failed to generate CXML")
		e.updateCallStatus(ctx, initResp.SessionID, "failed", err.Error())
		e.retryQueue.Add(dest.ID, "cxml_generation_failed")
		return
	}

	// Make the outbound call via Cloudonix
	cloudonixClient := e.createCloudonixClient(campaign)

	cloudonixReq := &cloudonix.OutboundCallRequest{
		From:        campaign.CallerID,
		To:          dest.PhoneNumber,
		CallbackURL: initResp.CallbackURL,
		CustomParameters: map[string]interface{}{
			"session_id":     initResp.SessionID,
			"campaign_id":    campaign.ID,
			"destination_id": dest.ID,
			"call_sid":       callSid,
		},
		Timeout:                campaign.DefaultTimeout,
		AMDEnabled:             campaign.AMDEnabled,
		AMDTimeout:             campaign.AMDTimeout,
		RoutingDestinationType: "cxml",
		RoutingCXML:            cxmlResp.CXML,
	}

	callResp, err := cloudonixClient.MakeOutboundCall(ctx, cloudonixReq)
	if err != nil {
		// Handle Cloudonix rate limiting (HTTP 429) - PAUSE IMMEDIATELY
		if errors.Is(err, cloudonix.ErrRateLimited) {
			log.Error().
				Int64("campaign_id", campaign.ID).
				Msg("Cloudonix rate limit exceeded (HTTP 429) - PAUSING CAMPAIGN")

			e.updateCallStatus(ctx, initResp.SessionID, "failed", "rate_limited_by_cloudonix")
			e.pauseCampaign(ctx, campaign.ID, "cloudonix_rate_limit")
			return
		}

		log.Error().
			Err(err).
			Int64("session_id", initResp.SessionID).
			Msg("Failed to make outbound call")

		e.updateCallStatus(ctx, initResp.SessionID, "failed", err.Error())
		e.retryQueue.Add(dest.ID, "dial_failed")
		return
	}

	// Call initiated successfully - increment concurrency counter in Redis
	if err := e.concurrencyManager.StartCall(ctx, campaign.ID, callResp.CallID); err != nil {
		log.Error().
			Err(err).
			Int64("campaign_id", campaign.ID).
			Str("call_id", callResp.CallID).
			Msg("Failed to increment concurrency counter, but call was initiated")
		// Don't fail the call, just log the error
	}

	// Track active call locally
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

	log.Info().
		Int64("session_id", initResp.SessionID).
		Str("call_id", callResp.CallID).
		Str("to", dest.PhoneNumber).
		Msg("Outbound call initiated successfully")

	e.metrics.RecordCallInitiated(campaign.ID)
}

// updateCallStatus updates the call status in Laravel.
func (e *Executor) updateCallStatus(ctx context.Context, sessionID int64, status, errMsg string) {
	updateCtx, cancel := context.WithTimeout(ctx, 5*time.Second)
	defer cancel()

	e.laravelClient.UpdateCallStatus(updateCtx, sessionID, &models.UpdateCallStatusRequest{
		Status: status,
		Error:  errMsg,
	})
}

// pauseCampaign pauses a campaign immediately.
func (e *Executor) pauseCampaign(ctx context.Context, campaignID int64, reason string) {
	log.Error().
		Int64("campaign_id", campaignID).
		Str("reason", reason).
		Msg("PAUSING CAMPAIGN")

	if err := e.laravelClient.PauseCampaign(ctx, campaignID, reason); err != nil {
		log.Error().
			Err(err).
			Int64("campaign_id", campaignID).
			Msg("Failed to pause campaign")
	}
}

// StopCampaign stops all active calls for a campaign.
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

	// Hangup active calls
	for _, callID := range callsToStop {
		callCtx, exists := e.activeCalls[callID]
		if !exists {
			continue
		}

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

// HandleCDR handles a CDR event by decrementing the concurrency counter.
//
// This method is called by the CDR consumer when a CDR event is received.
// It updates the local state and decrements the Redis concurrency counter.
//
// Parameters:
//   - ctx: Context for the operation
//   - campaignID: The campaign ID
//   - sessionToken: The Cloudonix session token (call_id)
//   - disposition: The call disposition
func (e *Executor) HandleCDR(ctx context.Context, campaignID int64, sessionToken, disposition string) {
	// Decrement Redis concurrency counter
	if err := e.concurrencyManager.CompleteCall(ctx, campaignID, sessionToken); err != nil {
		log.Error().
			Err(err).
			Int64("campaign_id", campaignID).
			Str("session_token", sessionToken).
			Msg("Failed to decrement concurrency counter")
	}

	// Update local tracking
	e.mu.Lock()
	if callCtx, exists := e.activeCalls[sessionToken]; exists {
		delete(e.activeCalls, sessionToken)
		if campaignCalls, exists := e.campaignCalls[callCtx.CampaignID]; exists {
			delete(campaignCalls, sessionToken)
		}
	}
	e.mu.Unlock()

	log.Info().
		Int64("campaign_id", campaignID).
		Str("session_token", sessionToken).
		Str("disposition", disposition).
		Msg("CDR processed - call completed")
}
