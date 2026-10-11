package executor

import (
	"context"
	"fmt"
	"log/slog"
	"strconv"
	"time"

	"opbx/dialer-worker/internal/api"
	"opbx/dialer-worker/internal/callerid"
	"opbx/dialer-worker/internal/limiter"
	"opbx/dialer-worker/internal/models"
	"opbx/dialer-worker/internal/redis"
	"opbx/dialer-worker/pkg/retry"
)

// Timeout and duration constants
const (
	// DefaultLockTTL is the default TTL for distributed locks
	DefaultLockTTL = 30 * time.Second
	// DefaultCallStateTTL is the default TTL for call state entries in Redis
	DefaultCallStateTTL = 60 * time.Second
)

// Executor handles the execution of dialer calls
type Executor struct {
	apiClient       *api.Client
	redisClient     *redis.Client
	limiter         *limiter.CACRateLimiter
	retryMgr        *retry.Manager
	strategyFactory *callerid.StrategyFactory
	retryTracker    *callerid.RetryTracker
	workerID        string
	logger          *slog.Logger
}

// NewExecutor creates a new call executor
func NewExecutor(
	apiClient *api.Client,
	redisClient *redis.Client,
	limiter *limiter.CACRateLimiter,
	retryMgr *retry.Manager,
	workerID string,
	logger *slog.Logger,
) *Executor {
	return &Executor{
		apiClient:       apiClient,
		redisClient:     redisClient,
		limiter:         limiter,
		retryMgr:        retryMgr,
		strategyFactory: callerid.NewStrategyFactory(redisClient),
		retryTracker:    callerid.NewRetryTracker(redisClient),
		workerID:        workerID,
		logger:          logger,
	}
}

// ExecuteCall executes a single call to a destination
func (e *Executor) ExecuteCall(ctx context.Context, campaign *models.Campaign, destination *models.Destination) error {
	logger := e.logger.With(
		"campaign_id", campaign.ID,
		"org_id", campaign.OrganizationID,
		"destination_id", destination.ID,
		"phone", destination.PhoneNumber,
	)

	// Acquire distributed lock for destination
	lockKey := fmt.Sprintf("dest:%d", destination.ID)
	acquired, err := e.redisClient.AcquireLock(ctx, lockKey, DefaultLockTTL)
	if err != nil {
		logger.Error("failed to acquire lock", "error", err)
		return fmt.Errorf("failed to acquire lock: %w", err)
	}
	if !acquired {
		logger.Info("destination already locked, skipping")
		return nil
	}
	defer e.redisClient.ReleaseLock(ctx, lockKey)

	// Check CAC availability (reserves a slot if available)
	canDial, activeCount, err := e.limiter.CanDial(ctx, campaign.ID)
	if err != nil {
		logger.Error("failed to check CAC", "error", err)
		return err
	}
	if !canDial {
		logger.Info("CAC limit reached, cannot dial")
		return nil
	}

	// Check if this is a retry (dial_attempts > 0 means previous attempts failed)
	isRetry := destination.DialAttempts > 0

	// Select Caller ID (use different one on retry)
	selectedCallerID, err := e.selectCallerID(ctx, campaign, destination, isRetry)
	if err != nil {
		logger.Error("failed to select caller ID", "error", err)
		return fmt.Errorf("failed to select caller ID: %w", err)
	}

	logger = logger.With(
		"caller_id", selectedCallerID.PhoneNumber,
		"caller_did_id", selectedCallerID.DIDID,
		"is_retry", isRetry,
	)

	// Initiate call via Laravel API
	logger.Info("initiating call")
	resp, err := e.apiClient.InitiateCallWithCallerID(
		ctx,
		campaign.ID,
		destination.ID,
		destination.PhoneNumber,
		e.workerID,
		selectedCallerID.PhoneNumber,
		selectedCallerID.DIDID,
	)
	if err != nil {
		logger.Error("failed to initiate call", "error", err)
		// Roll back the CAC slot reserved by CanDial
		if _, decErr := e.limiter.DecrementActive(ctx, campaign.ID); decErr != nil {
			logger.Error("failed to decrement active count after initiation failure", "error", decErr)
		}
		// Mark this DID as tried for retry tracking
		if campaign.CallerIDPoolEnabled {
			if markErr := e.retryTracker.MarkDIDAsTried(ctx, campaign.ID, destination.ID, selectedCallerID.DIDID); markErr != nil {
				logger.Error("failed to mark DID as tried", "error", markErr)
			}
		}
		return e.handleInitiationFailure(ctx, campaign, destination, err)
	}

	// Parse session ID from response
	sessionID := resp.SessionID
	callID := resp.CallID
	logger.Info("call initiated", "session_id", sessionID, "call_id", callID, "active_calls", activeCount)

	// Record call state in Redis with initial 60 second TTL
	// TTL will be extended or removed based on session status updates
	callState := &redis.CallState{
		SessionID:     sessionID,
		CampaignID:    campaign.ID,
		DestinationID: destination.ID,
		Status:        string(models.CallStatusInitiated),
		StartedAt:     time.Now(),
	}
	if err := e.redisClient.SetCallState(ctx, strconv.FormatInt(sessionID, 10), callState, DefaultCallStateTTL); err != nil {
		logger.Error("failed to store call state", "error", err)
	}

	// Record call timing in rate limiter
	e.limiter.RecordCall(campaign.ID)

	// Update LRU timestamp if using LRU strategy
	if campaign.CallerIDPoolEnabled && campaign.CallerIDStrategy == models.StrategyLeastRecentlyUsed {
		if lruStrategy, ok := e.strategyFactory.Create(campaign.CallerIDStrategy).(*callerid.LRUStrategy); ok {
			if markErr := lruStrategy.MarkAsUsed(ctx, campaign.ID, selectedCallerID.DIDID); markErr != nil {
				logger.Error("failed to update LRU timestamp", "error", markErr)
			}
		}
	}

	// Mark DID as tried for potential retries
	if campaign.CallerIDPoolEnabled {
		if markErr := e.retryTracker.MarkDIDAsTried(ctx, campaign.ID, destination.ID, selectedCallerID.DIDID); markErr != nil {
			logger.Error("failed to mark DID as tried", "error", markErr)
		}
	}

	return nil
}

// selectCallerID selects the appropriate Caller ID for the call
func (e *Executor) selectCallerID(
	ctx context.Context,
	campaign *models.Campaign,
	destination *models.Destination,
	isRetry bool,
) (*models.CallerIDPoolItem, error) {
	e.logger.Info("selecting caller ID",
		"campaign_id", campaign.ID,
		"caller_id_pool_enabled", campaign.CallerIDPoolEnabled,
		"pool_size", len(campaign.CallerIDPool),
		"caller_id_strategy", campaign.CallerIDStrategy,
		"is_retry", isRetry,
	)

	// If pool not enabled, use legacy Caller ID
	if !campaign.CallerIDPoolEnabled {
		e.logger.Info("pool not enabled, using legacy caller ID", "caller_id", campaign.CallerID)
		return &models.CallerIDPoolItem{
			DIDID:       0, // Unknown for legacy
			PhoneNumber: campaign.CallerID,
			Weight:      1,
		}, nil
	}

	// If pool is empty, fall back to legacy
	if len(campaign.CallerIDPool) == 0 {
		e.logger.Warn("pool enabled but empty, using legacy caller ID", "caller_id", campaign.CallerID)
		return &models.CallerIDPoolItem{
			DIDID:       0,
			PhoneNumber: campaign.CallerID,
			Weight:      1,
		}, nil
	}

	// Log pool contents
	for i, item := range campaign.CallerIDPool {
		e.logger.Info("pool item",
			"index", i,
			"did_id", item.DIDID,
			"phone_number", item.PhoneNumber,
			"weight", item.Weight,
		)
	}

	// Create strategy
	strategy := e.strategyFactory.Create(campaign.CallerIDStrategy)
	e.logger.Info("using strategy", "strategy", strategy.Name())

	// If retrying, get tried DIDs and select a different one
	if isRetry {
		triedDIDs, err := e.retryTracker.GetTriedDIDs(ctx, campaign.ID, destination.ID)
		if err == nil && len(triedDIDs) > 0 {
			e.logger.Info("retry mode, excluding tried DIDs", "tried_count", len(triedDIDs))
			// Use SelectWithRetry to exclude tried DIDs
			return strategy.SelectWithRetry(ctx, campaign.ID, campaign.CallerIDPool, triedDIDs)
		}
	}

	// First attempt or no retry tracking available
	selected, err := strategy.Select(ctx, campaign.ID, campaign.CallerIDPool)
	if err != nil {
		return nil, err
	}

	e.logger.Info("selected caller ID", "did_id", selected.DIDID, "phone_number", selected.PhoneNumber)
	return selected, nil
}

// handleInitiationFailure handles a failed call initiation
func (e *Executor) handleInitiationFailure(ctx context.Context, campaign *models.Campaign, destination *models.Destination, err error) error {
	logger := e.logger.With(
		"campaign_id", campaign.ID,
		"destination_id", destination.ID,
	)

	// Check if we should retry
	if e.retryMgr.ShouldRetry(destination.DialAttempts, "failed") {
		delay := e.retryMgr.GetRetryDelay(destination.DialAttempts)
		if scheduleErr := e.redisClient.ScheduleRetry(ctx, destination.ID, campaign.ID, delay); scheduleErr != nil {
			logger.Error("failed to schedule retry", "error", scheduleErr)
		}
		logger.Info("retry scheduled after initiation failure", "delay", delay)
	}

	return err
}
