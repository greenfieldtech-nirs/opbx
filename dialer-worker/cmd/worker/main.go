package main

import (
	"context"
	"log/slog"
	"net/http"
	"os"
	"os/signal"
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
	"opbx/dialer-worker/pkg/retry"
	"sync"
)

// Worker is the main dialer worker
type Worker struct {
	config      *config.Config
	apiClient   *api.Client
	redisClient *redis.Client
	limiter     *limiter.CACRateLimiter
	executor    *executor.Executor
	retryMgr    *retry.Manager
	logger      *slog.Logger

	// State
	activeCampaigns     map[int64]*models.Campaign
	processingCampaigns sync.Map // tracks campaign IDs currently being processed
	shutdown            chan struct{}

	// Concurrency control
	campaignSem chan struct{} // semaphore to limit concurrent campaigns

	// Webhook server
	webhookServer *http.Server
	wg            sync.WaitGroup
}

func main() {
	// Load configuration
	cfg := config.Load()

	// Setup logger
	logger := slog.New(slog.NewJSONHandler(os.Stdout, &slog.HandlerOptions{
		Level: slog.LevelInfo,
	}))

	logger.Info("starting dialer worker", "worker_id", cfg.WorkerID)

	// Create Redis client
	redisClient, err := redis.NewClient(cfg)
	if err != nil {
		logger.Error("failed to connect to Redis", "error", err)
		os.Exit(1)
	}
	defer redisClient.Close()

	// Create API client
	apiClient := api.NewClient(cfg, logger)

	// Create rate limiter
	racLimiter := limiter.NewCACRateLimiter(redisClient)

	// Create retry manager
	retryMgr := retry.NewManager(cfg)

	// Create executor
	exec := executor.NewExecutor(apiClient, redisClient, racLimiter, retryMgr, cfg.WorkerID, logger)

	// Create worker
	worker := &Worker{
		config:          cfg,
		apiClient:       apiClient,
		redisClient:     redisClient,
		limiter:         racLimiter,
		executor:        exec,
		retryMgr:        retryMgr,
		logger:          logger,
		activeCampaigns: make(map[int64]*models.Campaign),
		shutdown:        make(chan struct{}),
		campaignSem:     make(chan struct{}, 10), // Max 10 concurrent campaigns
	}

	// Register worker in Redis
	ctx := context.Background()
	if err := redisClient.RegisterWorker(ctx, cfg.WorkerID, 30*time.Second); err != nil {
		logger.Error("failed to register worker", "error", err)
		os.Exit(1)
	}

	// Start webhook server
	go worker.startWebhookServer()

	// Start main loop
	go worker.run()

	// Wait for shutdown signal
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)

	<-sigChan
	logger.Info("shutdown signal received, stopping worker...")
	close(worker.shutdown)

	// Graceful shutdown
	worker.shutdownServer()
	logger.Info("worker stopped")
}

// run is the main worker loop
func (w *Worker) run() {
	ticker := time.NewTicker(w.config.PollInterval)
	defer ticker.Stop()

	for {
		select {
		case <-w.shutdown:
			return
		case <-ticker.C:
			w.processCampaigns()
		}
	}
}

// processCampaigns fetches and processes active campaigns
func (w *Worker) processCampaigns() {
	w.logger.Info("polling for campaigns")
	ctx := context.Background()

	// Get active campaigns from Laravel
	campaigns, err := w.apiClient.GetActiveCampaigns(ctx)
	if err != nil {
		w.logger.Error("failed to get active campaigns", "error", err)
		return
	}

	w.logger.Info("found active campaigns", "count", len(campaigns))

	// Update active campaigns map
	w.activeCampaigns = make(map[int64]*models.Campaign)
	for i := range campaigns {
		campaign := &campaigns[i]
		w.activeCampaigns[campaign.ID] = campaign
		w.logger.Info("processing campaign", "id", campaign.ID, "name", campaign.Name, "status", campaign.Status, "cac", campaign.CAC, "cps", campaign.CPS)

		// Register with rate limiter
		w.limiter.RegisterCampaign(campaign.ID, campaign.CAC, campaign.CPS)

		// Skip if this campaign is already being processed by a previous cycle's goroutine
		if _, alreadyProcessing := w.processingCampaigns.LoadOrStore(campaign.ID, true); alreadyProcessing {
			w.logger.Info("campaign already being processed, skipping", "id", campaign.ID)
			continue
		}

		// Process the campaign in a goroutine with semaphore, clearing the flag when done
		go func(c *models.Campaign) {
			w.campaignSem <- struct{}{}        // Acquire semaphore
			defer func() { <-w.campaignSem }() // Release semaphore
			defer w.processingCampaigns.Delete(c.ID)
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

	// Process each destination
	for i := range destinations {
		dest := &destinations[i]
		select {
		case <-w.shutdown:
			return
		default:
		}

		// Wait for rate limiter
		waitTime := w.limiter.WaitTime(campaign.ID)
		if waitTime > 0 {
			logger.Debug("rate limiting", "wait", waitTime)
			time.Sleep(waitTime)
		}

		// Execute call
		if err := w.executor.ExecuteCall(ctx, campaign, dest); err != nil {
			logger.Error("failed to execute call", "destination_id", dest.ID, "error", err)
			continue
		}
	}
}

// startWebhookServer starts the webhook HTTP server
func (w *Worker) startWebhookServer() {
	gin.SetMode(gin.ReleaseMode)
	router := gin.New()

	// Create webhook handler
	webhookHandler := webhook.NewHandler(w.executor, w.config.WebhookSecret)
	webhookHandler.RegisterRoutes(router)

	w.webhookServer = &http.Server{
		Addr:    ":" + w.config.WebhookPort,
		Handler: router,
	}

	w.logger.Info("starting webhook server", "port", w.config.WebhookPort)

	w.wg.Add(1)
	go func() {
		defer w.wg.Done()
		if err := w.webhookServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			w.logger.Error("webhook server error", "error", err)
		}
	}()
}

// shutdownServer gracefully shuts down the webhook server
func (w *Worker) shutdownServer() {
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	if w.webhookServer != nil {
		if err := w.webhookServer.Shutdown(ctx); err != nil {
			w.logger.Error("webhook server shutdown error", "error", err)
		}
	}
	w.wg.Wait()
}
