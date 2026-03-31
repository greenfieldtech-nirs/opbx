// Package cdr provides CDR (Call Detail Record) event consumption from Redis.
//
// The CDR consumer subscribes to the 'cdr:completed' Redis channel and processes
// events as they arrive. When a CDR is received, it notifies the concurrency
// manager to decrement the counter and remove the session from active sessions.
//
// This package works in conjunction with the concurrency package to maintain
// accurate CAC (Concurrent Active Calls) tracking.
package cdr

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/go-redis/redis/v8"
	"github.com/nirsolutions/opbx-dialer-worker/internal/concurrency"
	"github.com/rs/zerolog/log"
)

// ChannelName is the Redis channel for CDR events.
const ChannelName = "cdr:completed"

// Event represents a CDR event received from Redis.
// This struct matches the JSON format published by the Laravel CDRPublisher.
type Event struct {
	Type          string `json:"type"`           // Event type (e.g., "call.completed")
	SessionToken  string `json:"session_token"`  // Cloudonix session token (call_id)
	CampaignID    int64  `json:"campaign_id"`    // Auto-dialer campaign ID
	DestinationID int64  `json:"destination_id"` // Destination ID that was called
	SessionID     int64  `json:"session_id"`     // Auto-dialer session ID
	Disposition   string `json:"disposition"`    // Call disposition (answered, busy, etc.)
	Duration      int    `json:"duration"`       // Call duration in seconds
	Billsec       int    `json:"billsec"`        // Billable seconds
	WorkerID      string `json:"worker_id"`      // Worker that initiated the call (optional)
	Timestamp     string `json:"timestamp"`      // ISO8601 timestamp
}

// Consumer subscribes to CDR events from Redis and processes them.
type Consumer struct {
	redisClient        *redis.Client
	concurrencyManager *concurrency.Manager
	handler            func(ctx context.Context, event Event) error
	stopChan           chan struct{}
	isRunning          bool
}

// NewConsumer creates a new CDR consumer.
//
// Parameters:
//   - redisClient: Redis client for subscribing to the CDR channel
//   - concurrencyManager: Manager to update when calls complete
//   - handler: Optional custom handler for CDR events (can be nil)
//
// If handler is nil, the default handler will be used which simply
// decrements the concurrency counter.
func NewConsumer(
	redisClient *redis.Client,
	concurrencyManager *concurrency.Manager,
	handler func(ctx context.Context, event Event) error,
) *Consumer {
	return &Consumer{
		redisClient:        redisClient,
		concurrencyManager: concurrencyManager,
		handler:            handler,
		stopChan:           make(chan struct{}),
	}
}

// Start begins consuming CDR events from Redis.
//
// This method blocks and runs until Stop() is called or the context is cancelled.
// It should be run in a goroutine.
//
// Parameters:
//   - ctx: Context for the consumer lifecycle
//
// Returns an error if the consumer fails to start or encounters a fatal error.
func (c *Consumer) Start(ctx context.Context) error {
	if c.isRunning {
		return fmt.Errorf("CDR consumer is already running")
	}

	c.isRunning = true
	defer func() { c.isRunning = false }()

	log.Info().
		Str("channel", ChannelName).
		Msg("Starting CDR consumer")

	// Create a new pubsub connection
	pubsub := c.redisClient.Subscribe(ctx, ChannelName)
	defer pubsub.Close()

	// Wait for subscription confirmation
	_, err := pubsub.Receive(ctx)
	if err != nil {
		return fmt.Errorf("failed to subscribe to CDR channel: %w", err)
	}

	log.Info().
		Str("channel", ChannelName).
		Msg("Subscribed to CDR channel")

	// Get the channel for messages
	ch := pubsub.Channel()

	for {
		select {
		case <-ctx.Done():
			log.Info().Msg("CDR consumer stopping due to context cancellation")
			return ctx.Err()

		case <-c.stopChan:
			log.Info().Msg("CDR consumer stopping due to stop signal")
			return nil

		case msg, ok := <-ch:
			if !ok {
				log.Warn().Msg("CDR channel closed, reconnecting...")
				time.Sleep(1 * time.Second)
				continue
			}

			if err := c.processMessage(ctx, msg.Payload); err != nil {
				log.Error().
					Err(err).
					Str("payload", msg.Payload).
					Msg("Failed to process CDR message")
			}
		}
	}
}

// Stop signals the consumer to stop.
//
// This method is safe to call multiple times. The consumer will stop
// gracefully, finishing any in-progress message processing before returning.
func (c *Consumer) Stop() {
	if !c.isRunning {
		return
	}

	log.Info().Msg("Stopping CDR consumer")
	close(c.stopChan)
}

// IsRunning returns true if the consumer is currently running.
func (c *Consumer) IsRunning() bool {
	return c.isRunning
}

// processMessage parses and processes a CDR message from Redis.
//
// Parameters:
//   - ctx: Context for processing
//   - payload: The JSON payload from Redis
//
// Returns an error if parsing or processing fails.
func (c *Consumer) processMessage(ctx context.Context, payload string) error {
	var event Event
	if err := json.Unmarshal([]byte(payload), &event); err != nil {
		return fmt.Errorf("failed to parse CDR event: %w", err)
	}

	log.Info().
		Str("session_token", event.SessionToken).
		Int64("campaign_id", event.CampaignID).
		Int64("session_id", event.SessionID).
		Str("disposition", event.Disposition).
		Msg("Received CDR event")

	// Use custom handler if provided
	if c.handler != nil {
		return c.handler(ctx, event)
	}

	// Use default handler
	return c.defaultHandler(ctx, event)
}

// defaultHandler is the default CDR event handler.
//
// It simply decrements the concurrency counter and removes the session
// from the active sessions list.
//
// Parameters:
//   - ctx: Context for processing
//   - event: The parsed CDR event
//
// Returns an error if the concurrency manager operation fails.
func (c *Consumer) defaultHandler(ctx context.Context, event Event) error {
	// Update the concurrency counter
	if err := c.concurrencyManager.CompleteCall(ctx, event.CampaignID, event.SessionToken); err != nil {
		return fmt.Errorf("failed to complete call in concurrency manager: %w", err)
	}

	log.Info().
		Str("session_token", event.SessionToken).
		Int64("campaign_id", event.CampaignID).
		Str("disposition", event.Disposition).
		Msg("CDR processed - concurrency counter decremented")

	return nil
}
