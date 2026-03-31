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
	"github.com/nirsolutions/opbx-dialer-worker/internal/metrics"
	"github.com/nirsolutions/opbx-dialer-worker/internal/retry"
	"github.com/nirsolutions/opbx-dialer-worker/pkg/models"
	"github.com/rs/zerolog/log"
	"golang.org/x/time/rate"
)

// MaxConcurrentActiveCalls is the maximum number of concurrent active calls per organization
const MaxConcurrentActiveCalls = 5

// Executor handles the execution of outbound calls for campaigns
type Executor struct {
	laravelClient *api.Client
	retryQueue    *retry.Queue
	breaker       *circuitbreaker.Breaker
	metrics       *metrics.Collector
	workerID      string

	// Rate limiting per campaign
	campaignLimiters map[int64]*rate.Limiter // campaignID -> rate limiter

	// Active call tracking
	activeCalls       map[string]*CallContext   // callID -> context
	campaignCalls     map[int64]map[string]bool // campaignID -> set of callIDs
	orgActiveCalls    map[int64]int             // organizationID -> count of active calls
	orgCallSemaphores map[int64]chan struct{}   // organizationID -> semaphore for limiting concurrent calls

	mu            sync.RWMutex
	maxConcurrent int
}

// CallContext holds context for an active call
type CallContext struct {
	CampaignID      int64
	DestinationID   int64
	SessionID       int64
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
	workerID string,
) *Executor {
	return &Executor{
		laravelClient:     laravelClient,
		retryQueue:        retryQueue,
		breaker:           breaker,
		metrics:           metrics,
		workerID:          workerID,
		campaignLimiters:  make(map[int64]*rate.Limiter),
		activeCalls:       make(map[string]*CallContext),
		campaignCalls:     make(map[int64]map[string]bool),
		orgActiveCalls:    make(map[int64]int),
		orgCallSemaphores: make(map[int64]chan struct{}),
		maxConcurrent:     cfg.MaxConcurrentGlobal,
	}
}

// getCampaignLimiter returns the rate limiter for a campaign, creating it if needed
// Uses the campaign's calls_per_second setting, with a minimum of 1 CPS
func (e *Executor) getCampaignLimiter(campaign *models.Campaign) *rate.Limiter {
	e.mu.Lock()
	defer e.mu.Unlock()

	limiter, exists := e.campaignLimiters[campaign.ID]
	if !exists {
		// Use campaign CPS, default to 1 if not set
		cps := campaign.CallsPerSecond
		if cps < 1 {
			cps = 1
		}
		// Burst is 1 to enforce strict CPS - no burst allowed
		limiter = rate.NewLimiter(rate.Limit(cps), 1)
		e.campaignLimiters[campaign.ID] = limiter
		log.Info().
			Int64("campaign_id", campaign.ID).
			Int("calls_per_second", cps).
			Msg("Created campaign rate limiter")
	}
	return limiter
}

// getOrgSemaphore returns the semaphore for an organization, creating it if needed
// The semaphore limits concurrent active calls to MaxConcurrentActiveCalls (5)
func (e *Executor) getOrgSemaphore(orgID int64) chan struct{} {
	e.mu.Lock()
	defer e.mu.Unlock()

	sem, exists := e.orgCallSemaphores[orgID]
	if !exists {
		// Create buffered channel with capacity of MaxConcurrentActiveCalls
		sem = make(chan struct{}, MaxConcurrentActiveCalls)
		e.orgCallSemaphores[orgID] = sem
		log.Info().
			Int64("organization_id", orgID).
			Int("max_concurrent", MaxConcurrentActiveCalls).
			Msg("Created organization call semaphore")
	}
	return sem
}

// acquireOrgCallSlot attempts to acquire a slot for an organization
// Returns true if a slot was acquired, false if at max capacity
func (e *Executor) acquireOrgCallSlot(orgID int64) bool {
	sem := e.getOrgSemaphore(orgID)

	select {
	case sem <- struct{}{}:
		// Acquired slot
		e.mu.Lock()
		e.orgActiveCalls[orgID]++
		count := e.orgActiveCalls[orgID]
		e.mu.Unlock()

		log.Debug().
			Int64("organization_id", orgID).
			Int("active_calls", count).
			Msg("Acquired organization call slot")
		return true
	default:
		// Channel is full (at max capacity)
		return false
	}
}

// releaseOrgCallSlot releases a slot for an organization
func (e *Executor) releaseOrgCallSlot(orgID int64) {
	sem := e.getOrgSemaphore(orgID)

	select {
	case <-sem:
		// Released slot
		e.mu.Lock()
		e.orgActiveCalls[orgID]--
		if e.orgActiveCalls[orgID] < 0 {
			e.orgActiveCalls[orgID] = 0
		}
		count := e.orgActiveCalls[orgID]
		e.mu.Unlock()

		log.Debug().
			Int64("organization_id", orgID).
			Int("active_calls", count).
			Msg("Released organization call slot")
	default:
		// Channel was empty (shouldn't happen, but log it)
		log.Warn().
			Int64("organization_id", orgID).
			Msg("Attempted to release org call slot but none held")
	}
}

// getOrgActiveCallCount returns the current number of active calls for an organization
func (e *Executor) getOrgActiveCallCount(orgID int64) int {
	e.mu.RLock()
	defer e.mu.RUnlock()
	return e.orgActiveCalls[orgID]
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
		Int64("organization_id", campaign.OrganizationID).
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

		// Check if organization has capacity for another call
		if !e.acquireOrgCallSlot(campaign.OrganizationID) {
			activeCount := e.getOrgActiveCallCount(campaign.OrganizationID)
			log.Debug().
				Int64("campaign_id", campaign.ID).
				Int64("organization_id", campaign.OrganizationID).
				Int("active_calls", activeCount).
				Int("max_concurrent", MaxConcurrentActiveCalls).
				Msg("Organization at max concurrent calls, waiting")
			time.Sleep(time.Second)
			continue
		}

		// Launch call execution (rate limiting happens inside executeCall)
		go e.executeCall(ctx, campaign, &dest, e.workerID)
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
func (e *Executor) executeCall(ctx context.Context, campaign *models.Campaign, dest *models.Destination, workerID string) {
	// Release the org slot when done (even if call fails)
	defer e.releaseOrgCallSlot(campaign.OrganizationID)

	// Wait for campaign rate limiter before making API call
	// This enforces the CPS (calls per second) limit
	campaignLimiter := e.getCampaignLimiter(campaign)
	if err := campaignLimiter.Wait(ctx); err != nil {
		log.Error().
			Err(err).
			Int64("campaign_id", campaign.ID).
			Msg("Campaign rate limiter wait failed")
		e.retryQueue.Add(dest.ID, "rate_limit_wait_failed")
		return
	}

	log.Debug().
		Int64("campaign_id", campaign.ID).
		Int64("destination_id", dest.ID).
		Msg("Rate limiter passed, initiating call")

	// Initiate call session in Laravel
	initReq := &models.InitiateCallRequest{
		CampaignID:    campaign.ID,
		DestinationID: dest.ID,
		PhoneNumber:   dest.PhoneNumber,
		WorkerID:      workerID,
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
		Int64("destination_id", dest.ID).
		Str("to_number", dest.PhoneNumber).
		Msg("Call session initiated")

	// Generate a unique call SID for this outbound call
	callSid := fmt.Sprintf("call_%d_%d_%d", campaign.ID, dest.ID, time.Now().Unix())

	// Fetch CXML for routing from Laravel API
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
			Msg("Failed to generate CXML for outbound call")

		// Update call status to failed
		updateCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
		e.laravelClient.UpdateCallStatus(updateCtx, initResp.SessionID, &models.UpdateCallStatusRequest{
			Status: "failed",
			Error:  err.Error(),
		})
		cancel()

		e.retryQueue.Add(dest.ID, "cxml_generation_failed")
		e.metrics.RecordCallFailed(campaign.ID)
		return
	}

	log.Info().
		Int64("session_id", initResp.SessionID).
		Str("routing_type", cxmlResp.RoutingType).
		Str("cxml_preview", fmt.Sprintf("%.50s...", cxmlResp.CXML)).
		Msg("Generated CXML for outbound call routing")

	// Make the actual outbound call via Cloudonix
	// Create a client using this campaign's organization-specific credentials
	log.Info().
		Int64("campaign_id", campaign.ID).
		Str("cloudonix_domain", campaign.CloudonixDomain).
		Str("api_key_set", fmt.Sprintf("%t", campaign.CloudonixAPIKey != "")).
		Msg("Creating Cloudonix client with credentials")

	cloudonixClient := e.createCloudonixClient(campaign)

	cloudonixReq := &cloudonix.OutboundCallRequest{
		From:        campaign.CallerID,
		To:          dest.PhoneNumber,
		CallbackURL: initResp.CallbackURL,
		CustomParameters: map[string]interface{}{
			"session_id":     initResp.SessionID,
			"campaign_id":    campaign.ID,
			"destination_id": dest.ID,
			"ai_agent_id":    campaign.AIAgentID,
			"call_sid":       callSid,
		},
		Timeout:                campaign.DefaultTimeout,
		AMDEnabled:             campaign.AMDEnabled,
		AMDTimeout:             campaign.AMDTimeout,
		RoutingDestinationType: "cxml", // Use CXML routing
		RoutingCXML:            cxmlResp.CXML,
	}

	callResp, err := cloudonixClient.MakeOutboundCall(ctx, cloudonixReq)
	if err != nil {
		// Handle Cloudonix rate limiting (HTTP 429) - PAUSE IMMEDIATELY
		if errors.Is(err, cloudonix.ErrRateLimited) {
			log.Error().
				Int64("campaign_id", campaign.ID).
				Int64("organization_id", campaign.OrganizationID).
				Str("to", dest.PhoneNumber).
				Msg("Cloudonix rate limit exceeded (HTTP 429) - PAUSING CAMPAIGN IMMEDIATELY")

			// Update call status to indicate rate limiting
			updateCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			e.laravelClient.UpdateCallStatus(updateCtx, initResp.SessionID, &models.UpdateCallStatusRequest{
				Status: "failed",
				Error:  "rate_limited_by_cloudonix",
			})
			cancel()

			// PAUSE CAMPAIGN IMMEDIATELY (not after delay)
			pauseCtx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
			e.pauseCampaign(pauseCtx, campaign.ID, "cloudonix_rate_limit")
			cancel()
			return
		}

		log.Error().
			Err(err).
			Int64("session_id", initResp.SessionID).
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

	log.Info().
		Int64("session_id", initResp.SessionID).
		Str("call_id", callResp.CallID).
		Str("to", dest.PhoneNumber).
		Msg("Outbound call initiated successfully")

	e.metrics.RecordCallInitiated(campaign.ID)
}

// canStartNewCall checks if we can start a new call
func (e *Executor) canStartNewCall() bool {
	e.mu.RLock()
	defer e.mu.RUnlock()
	return len(e.activeCalls) < e.maxConcurrent
}

// pauseCampaign pauses a campaign immediately
func (e *Executor) pauseCampaign(ctx context.Context, campaignID int64, reason string) {
	log.Error().
		Int64("campaign_id", campaignID).
		Str("reason", reason).
		Msg("PAUSING CAMPAIGN due to rate limiting or errors")

	if err := e.laravelClient.PauseCampaign(ctx, campaignID, reason); err != nil {
		log.Error().
			Err(err).
			Int64("campaign_id", campaignID).
			Msg("Failed to pause campaign")
	}
}

// pauseCampaignWithDelay pauses a campaign after a specified delay (kept for compatibility)
// Used for rate limiting scenarios where we need to wait before pausing
func (e *Executor) pauseCampaignWithDelay(ctx context.Context, campaignID int64, reason string, delay time.Duration) {
	log.Info().
		Int64("campaign_id", campaignID).
		Dur("delay", delay).
		Str("reason", reason).
		Msg("Scheduling campaign pause after delay")

	time.Sleep(delay)

	if err := e.laravelClient.PauseCampaign(ctx, campaignID, reason); err != nil {
		log.Error().
			Err(err).
			Int64("campaign_id", campaignID).
			Msg("Failed to pause campaign after delay")
	}
}
