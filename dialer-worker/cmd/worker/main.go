package main

import (
	"context"
	"errors"
	"log"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
	"strings"
	"sync"
	"syscall"
	"time"

	"github.com/gin-gonic/gin"
	"opbx/dialer-worker/internal/api"
	"opbx/dialer-worker/internal/config"
	"opbx/dialer-worker/internal/executor"
	"opbx/dialer-worker/internal/limiter"
	"opbx/dialer-worker/internal/models"
	"opbx/dialer-worker/internal/redis"
	"opbx/dialer-worker/internal/webhook"
	apierrors "opbx/dialer-worker/pkg/errors"
)

// campaignAPI is the Laravel surface the poll loop needs (satisfied by *api.Client).
type campaignAPI interface {
	GetActiveCampaigns(ctx context.Context) ([]models.Campaign, error)
	GetPendingDestinations(ctx context.Context, campaignID int64, limit int) ([]models.Destination, error)
}

// callExecutor dials one destination (satisfied by *executor.Executor).
type callExecutor interface {
	ExecuteCall(ctx context.Context, campaign *models.Campaign, destination *models.Destination) error
}

// pacer applies the per-campaign CPS pacing (satisfied by *limiter.CACRateLimiter).
type pacer interface {
	RegisterCampaign(campaignID int64, cac int, cps int)
	WaitTime(campaignID int64) time.Duration
}

// drainTimeout bounds how long shutdown waits for in-flight initiates (the API
// client times out at 30s, so this lets an initiate finish).
const drainTimeout = 45 * time.Second

// Worker is the main dialer worker
type Worker struct {
	config    *config.Config
	apiClient campaignAPI
	limiter   pacer
	executor  callExecutor
	logger    *slog.Logger

	processingCampaigns sync.Map      // campaign IDs currently being processed
	campaignSem         chan struct{} // limits concurrent campaigns
	wg                  sync.WaitGroup
}

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("failed to load configuration: %v", err)
	}

	// Flat JSON contract shared with the rest of the stack
	// (see .my_agent/specifications/log-aggregation.md).
	logger := newLogger(os.Stdout)
	logger.Info("starting dialer worker", "worker_id", cfg.WorkerID)

	redisClient, err := redis.NewClient(cfg)
	if err != nil {
		logger.Error("failed to connect to Redis", "error", err)
		os.Exit(1)
	}
	defer redisClient.Close()

	apiClient := api.NewClient(cfg, logger)
	racLimiter := limiter.NewCACRateLimiter(redisClient)
	exec := executor.NewExecutor(apiClient, redisClient, racLimiter, cfg.WorkerID, logger)

	worker := &Worker{
		config:      cfg,
		apiClient:   apiClient,
		limiter:     racLimiter,
		executor:    exec,
		logger:      logger,
		campaignSem: make(chan struct{}, 10), // Max 10 concurrent campaigns
	}

	ctx, stop := signal.NotifyContext(context.Background(), syscall.SIGINT, syscall.SIGTERM)
	defer stop()

	srv := newHealthServer(cfg.WebhookPort)
	worker.wg.Add(1)
	go func() {
		defer worker.wg.Done()
		logger.Info("starting health server", "port", cfg.WebhookPort)
		if err := srv.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
			logger.Error("health server error", "error", err)
		}
	}()

	worker.wg.Add(1)
	go func() {
		defer worker.wg.Done()
		worker.run(ctx)
	}()

	<-ctx.Done()
	logger.Info("shutdown signal received, draining in-flight calls...")

	shutCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	if err := srv.Shutdown(shutCtx); err != nil {
		logger.Error("health server shutdown error", "error", err)
	}
	cancel()

	if !worker.drain(drainTimeout) {
		logger.Warn("drain timeout reached, exiting with calls still in flight")
	}
	logger.Info("worker stopped")
}

// drain waits for every tracked goroutine; it reports false on timeout.
func (w *Worker) drain(timeout time.Duration) bool {
	done := make(chan struct{})
	go func() { w.wg.Wait(); close(done) }()
	select {
	case <-done:
		return true
	case <-time.After(timeout):
		return false
	}
}

// run is the main worker loop; it returns when ctx is cancelled.
func (w *Worker) run(ctx context.Context) {
	ticker := time.NewTicker(w.config.PollInterval)
	defer ticker.Stop()

	for {
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
			w.processCampaigns(ctx)
		}
	}
}

// processCampaigns fetches active campaigns and starts a tracked goroutine for each.
func (w *Worker) processCampaigns(ctx context.Context) {
	w.logger.Info("polling for campaigns")

	campaigns, err := w.apiClient.GetActiveCampaigns(ctx)
	if err != nil {
		w.logger.Error("failed to get active campaigns", "error", err)
		return
	}

	w.logger.Info("found active campaigns", "count", len(campaigns))

	for i := range campaigns {
		campaign := &campaigns[i]
		w.logger.Info("processing campaign", "id", campaign.ID, "name", campaign.Name, "status", campaign.Status, "cac", campaign.CAC, "cps", campaign.CPS)

		w.limiter.RegisterCampaign(campaign.ID, campaign.CAC, campaign.CPS)

		// Skip if a previous cycle is still processing this campaign
		if _, alreadyProcessing := w.processingCampaigns.LoadOrStore(campaign.ID, true); alreadyProcessing {
			w.logger.Info("campaign already being processed, skipping", "id", campaign.ID)
			continue
		}

		w.wg.Add(1)
		go func(c *models.Campaign) {
			defer w.wg.Done()
			defer w.processingCampaigns.Delete(c.ID)
			select {
			case w.campaignSem <- struct{}{}:
				defer func() { <-w.campaignSem }()
			case <-ctx.Done():
				return
			}
			w.processCampaign(ctx, c)
		}(campaign)
	}
}

// processCampaign handles dialing for a single campaign
func (w *Worker) processCampaign(ctx context.Context, campaign *models.Campaign) {
	logger := w.logger.With("campaign_id", campaign.ID, "campaign_name", campaign.Name)

	// Check if campaign is runnable
	logger.Info("checking campaign status", "status", campaign.Status, "cac", campaign.CAC, "cps", campaign.CPS)
	if campaign.Status != "active" {
		logger.Info("campaign not active, skipping", "status", campaign.Status)
		return
	}

	// Calculate batch size: CPS × poll_interval, capped at CAC
	cps := campaign.CPS
	if cps < 1 {
		cps = 1
	}
	if cps > 30 {
		cps = 30
	}
	pollIntervalSec := int(w.config.PollInterval.Seconds())
	if pollIntervalSec < 1 {
		pollIntervalSec = 10
	}
	batchSize := cps * pollIntervalSec
	if batchSize > campaign.CAC {
		batchSize = campaign.CAC
	}
	if batchSize < 1 {
		batchSize = 1
	}

	// Get pending destinations
	logger.Info("fetching pending destinations", "limit", batchSize, "cps", cps, "poll_interval", pollIntervalSec)
	destinations, err := w.apiClient.GetPendingDestinations(ctx, campaign.ID, batchSize)
	if err != nil {
		logger.Error("failed to get pending destinations", "error", err)
		return
	}

	if len(destinations) == 0 {
		logger.Info("no pending destinations")
		return
	}

	logger.Info("processing destinations", "count", len(destinations))

	w.dialBatch(ctx, logger, campaign, destinations)
}

// dialBatch dials the destinations in order, paced by CPS. Once started, an initiate
// is not cancelled by shutdown (ctx only stops the batch between destinations). A 429 from Laravel
// backs off for Retry-After and aborts the rest of the batch.
func (w *Worker) dialBatch(ctx context.Context, logger *slog.Logger, campaign *models.Campaign, destinations []models.Destination) {
	for i := range destinations {
		dest := &destinations[i]
		if ctx.Err() != nil {
			return
		}

		if waitTime := w.limiter.WaitTime(campaign.ID); waitTime > 0 {
			logger.Debug("rate limiting", "wait", waitTime)
			if !sleep(ctx, waitTime) {
				return
			}
		}

		if err := w.executor.ExecuteCall(context.WithoutCancel(ctx), campaign, dest); err != nil {
			var rl *apierrors.RetryableError
			if errors.As(err, &rl) {
				logger.Warn("rate limited by Laravel, backing off and aborting batch", "retry_after", rl.RetryAfter)
				sleep(ctx, rl.RetryAfter)
				return
			}
			logger.Error("failed to execute call", "destination_id", dest.ID, "error", err)
		}
	}
}

// sleep waits for d or until ctx is cancelled; it reports whether the full wait elapsed.
func sleep(ctx context.Context, d time.Duration) bool {
	t := time.NewTimer(d)
	defer t.Stop()
	select {
	case <-ctx.Done():
		return false
	case <-t.C:
		return true
	}
}

// newHealthServer builds the HTTP server that serves only the health route.
func newHealthServer(port string) *http.Server {
	gin.SetMode(gin.ReleaseMode)
	router := gin.New()
	webhook.RegisterRoutes(router)

	return &http.Server{
		Addr:              ":" + port,
		Handler:           router,
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       10 * time.Second,
	}
}

// newLogger builds the contract-conformant JSON logger: ts/level(lowercase)/
// msg plus the fixed service and log_type dimensions.
func newLogger(w *os.File) *slog.Logger {
	return slog.New(slog.NewJSONHandler(w, &slog.HandlerOptions{
		Level: slog.LevelInfo,
		ReplaceAttr: func(groups []string, a slog.Attr) slog.Attr {
			if a.Key == slog.TimeKey {
				a.Key = "ts"
			}
			if a.Key == slog.LevelKey {
				a.Value = slog.StringValue(strings.ToLower(a.Value.String()))
			}
			return a
		},
	})).With("service", "dialer-worker", "log_type", "call_flow")
}
