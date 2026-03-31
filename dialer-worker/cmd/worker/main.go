package main

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"os/signal"
	"syscall"
	"time"

	"github.com/rs/zerolog"
	"github.com/rs/zerolog/log"

	"github.com/nirsolutions/opbx-dialer-worker/internal/api"
	"github.com/nirsolutions/opbx-dialer-worker/internal/circuitbreaker"
	"github.com/nirsolutions/opbx-dialer-worker/internal/config"
	"github.com/nirsolutions/opbx-dialer-worker/internal/executor"
	"github.com/nirsolutions/opbx-dialer-worker/internal/metrics"
	"github.com/nirsolutions/opbx-dialer-worker/internal/retry"
	"github.com/nirsolutions/opbx-dialer-worker/internal/scheduler"
	"github.com/nirsolutions/opbx-dialer-worker/internal/state"
	"github.com/nirsolutions/opbx-dialer-worker/internal/webhook"
)

func main() {
	// Load configuration
	cfg, err := config.Load()
	if err != nil {
		fmt.Fprintf(os.Stderr, "Failed to load configuration: %v\n", err)
		os.Exit(1)
	}

	// Setup logging
	setupLogging(cfg.LogLevel)

	log.Info().
		Str("worker_id", cfg.WorkerID).
		Str("version", "1.0.0").
		Msg("Starting dialer worker")

	// Create context for graceful shutdown
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	// Setup signal handling
	sigChan := make(chan os.Signal, 1)
	signal.Notify(sigChan, syscall.SIGINT, syscall.SIGTERM)

	// Initialize components
	laravelClient := api.NewClient(cfg.LaravelAPIURL, cfg.LaravelAPIToken)
	metricsCollector := metrics.NewCollector()

	// Initialize circuit breaker
	cbCfg := circuitbreaker.DefaultConfig()
	cbCfg.MaxFailures = cfg.CircuitBreakerThreshold
	cbCfg.Timeout = time.Duration(cfg.CircuitBreakerTimeoutMinutes) * time.Minute

	cb := circuitbreaker.NewBreaker(cbCfg,
		func() {
			log.Warn().Msg("Circuit breaker opened")
			metricsCollector.SetCircuitBreakerState("open")
			metricsCollector.RecordCircuitBreakerTrip("ai_agent_errors")
		},
		func() {
			log.Info().Msg("Circuit breaker closed")
			metricsCollector.SetCircuitBreakerState("closed")
		},
	)

	// Initialize retry queue
	retryCfg := retry.DefaultConfig()
	retryQueue := retry.NewQueue(retryCfg, func(destinationID int64) error {
		// Retry handler - will be called by executor
		return nil
	})

	// Initialize executor
	execCfg := executor.Config{
		MaxConcurrentGlobal: cfg.MaxConcurrentCalls,
		DefaultCallTimeout:  cfg.DefaultCallTimeout,
		RateLimitPerSecond:  10, // Configurable
	}
	exec := executor.NewExecutor(laravelClient, retryQueue, cb, metricsCollector, execCfg)

	// Update retry queue handler to use executor
	retryQueue = retry.NewQueue(retryCfg, func(destinationID int64) error {
		// Get retry destinations from API
		// This is a simplified version - full implementation would queue for execution
		log.Info().Int64("destination_id", destinationID).Msg("Processing retry")
		return nil
	})

	// Initialize state persister
	statePersister := state.NewPersister(laravelClient, cfg.WorkerID, cfg.StateDir)
	statePersister.Start(ctx)

	// Initialize scheduler
	sched, err := scheduler.NewScheduler(laravelClient, exec)
	if err != nil {
		log.Fatal().Err(err).Msg("Failed to create scheduler")
	}

	// Initialize webhook handler
	webhookHandler := webhook.NewHandler(exec)
	webhookMux := http.NewServeMux()
	webhookHandler.RegisterRoutes(webhookMux)

	// Add status endpoint to main server (replaces separate metrics server)
	webhookMux.HandleFunc("/status", metricsCollector.StatusHandler)

	// Start webhook server
	webhookServer := &http.Server{
		Addr:         fmt.Sprintf(":%d", cfg.WorkerAPIPort),
		Handler:      webhookMux,
		ReadTimeout:  30 * time.Second,
		WriteTimeout: 30 * time.Second,
	}

	go func() {
		log.Info().Int("port", cfg.WorkerAPIPort).Msg("Starting webhook server")
		if err := webhookServer.ListenAndServe(); err != nil && err != http.ErrServerClosed {
			log.Fatal().Err(err).Msg("Webhook server error")
		}
	}()

	// Start scheduler
	go func() {
		if err := sched.Start(ctx); err != nil {
			log.Error().Err(err).Msg("Scheduler error")
		}
	}()

	// Start retry queue
	go retryQueue.Start(ctx)

	// Wait for shutdown signal
	<-sigChan
	log.Info().Msg("Shutdown signal received, gracefully shutting down...")

	// Graceful shutdown
	shutdownCtx, shutdownCancel := context.WithTimeout(context.Background(), 30*time.Second)
	defer shutdownCancel()

	// Stop all components
	retryQueue.Stop()
	statePersister.Stop()

	if err := sched.Stop(); err != nil {
		log.Error().Err(err).Msg("Error stopping scheduler")
	}

	if err := webhookServer.Shutdown(shutdownCtx); err != nil {
		log.Error().Err(err).Msg("Error shutting down webhook server")
	}

	cancel()

	log.Info().Msg("Shutdown complete")
}

func setupLogging(level string) {
	// Set log level
	lvl, err := zerolog.ParseLevel(level)
	if err != nil {
		lvl = zerolog.InfoLevel
	}
	zerolog.SetGlobalLevel(lvl)

	// Use pretty console output for development
	if os.Getenv("ENV") == "development" {
		log.Logger = log.Output(zerolog.ConsoleWriter{
			Out:        os.Stdout,
			TimeFormat: time.RFC3339,
		})
	}

	// Add caller info
	log.Logger = log.With().Caller().Logger()
}
