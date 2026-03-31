package cdr

import (
	"context"
	"encoding/json"
	"testing"
	"time"

	"github.com/go-redis/redis/v8"
	"github.com/nirsolutions/opbx-dialer-worker/internal/concurrency"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// setupTestRedis creates a Redis client for testing.
func setupTestRedis(t *testing.T) *redis.Client {
	addr := "localhost:6379"
	if envAddr := ""; envAddr != "" {
		addr = envAddr
	}

	client := redis.NewClient(&redis.Options{
		Addr:     addr,
		Password: "",
		DB:       1, // Use DB 1 for tests
	})

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	err := client.Ping(ctx).Err()
	if err != nil {
		t.Skipf("Redis not available at %s: %v", addr, err)
	}

	// Clean up
	err = client.FlushDB(ctx).Err()
	require.NoError(t, err)

	return client
}

// TestConsumer_ProcessMessage tests processing a CDR event.
func TestConsumer_ProcessMessage(t *testing.T) {
	redisClient := setupTestRedis(t)
	defer redisClient.Close()

	concurrencyManager := concurrency.NewManager(redisClient)
	ctx := context.Background()
	campaignID := int64(100)

	// Start a call first
	err := concurrencyManager.StartCall(ctx, campaignID, "test_session_token")
	require.NoError(t, err)

	count, _ := concurrencyManager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 1, count)

	// Create CDR event
	event := Event{
		Type:          "call.completed",
		SessionToken:  "test_session_token",
		CampaignID:    campaignID,
		DestinationID: 456,
		SessionID:     789,
		Disposition:   "answered",
		Duration:      60,
		Billsec:       55,
		Timestamp:     time.Now().Format(time.RFC3339),
	}

	payload, _ := json.Marshal(event)

	// Process the message
	consumer := NewConsumer(redisClient, concurrencyManager, nil)
	err = consumer.processMessage(ctx, string(payload))
	require.NoError(t, err)

	// Verify counter decremented
	count, _ = concurrencyManager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 0, count)

	// Verify session removed
	sessions, _ := concurrencyManager.GetActiveSessions(ctx, campaignID)
	assert.Len(t, sessions, 0)
}

// TestConsumer_ProcessMessage_InvalidJSON tests handling of invalid JSON.
func TestConsumer_ProcessMessage_InvalidJSON(t *testing.T) {
	redisClient := setupTestRedis(t)
	defer redisClient.Close()

	concurrencyManager := concurrency.NewManager(redisClient)
	ctx := context.Background()

	consumer := NewConsumer(redisClient, concurrencyManager, nil)
	err := consumer.processMessage(ctx, "invalid json")
	assert.Error(t, err)
}

// TestConsumer_StartAndStop tests starting and stopping the consumer.
func TestConsumer_StartAndStop(t *testing.T) {
	redisClient := setupTestRedis(t)
	defer redisClient.Close()

	concurrencyManager := concurrency.NewManager(redisClient)

	consumer := NewConsumer(redisClient, concurrencyManager, nil)

	// Initially not running
	assert.False(t, consumer.IsRunning())

	// Start in background
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	go func() {
		err := consumer.Start(ctx)
		// Should return when context is cancelled
		assert.ErrorIs(t, err, context.Canceled)
	}()

	// Wait a bit for consumer to start
	time.Sleep(100 * time.Millisecond)
	assert.True(t, consumer.IsRunning())

	// Stop
	cancel()
	time.Sleep(100 * time.Millisecond)
	assert.False(t, consumer.IsRunning())
}

// TestConsumer_CustomHandler tests using a custom CDR handler.
func TestConsumer_CustomHandler(t *testing.T) {
	redisClient := setupTestRedis(t)
	defer redisClient.Close()

	concurrencyManager := concurrency.NewManager(redisClient)
	ctx := context.Background()

	// Track if custom handler was called
	handlerCalled := false
	var receivedEvent Event

	customHandler := func(ctx context.Context, event Event) error {
		handlerCalled = true
		receivedEvent = event
		return nil
	}

	consumer := NewConsumer(redisClient, concurrencyManager, customHandler)

	event := Event{
		Type:          "call.completed",
		SessionToken:  "custom_token",
		CampaignID:    100,
		DestinationID: 200,
		SessionID:     300,
		Disposition:   "busy",
		Duration:      0,
		Billsec:       0,
	}

	payload, _ := json.Marshal(event)
	err := consumer.processMessage(ctx, string(payload))
	require.NoError(t, err)

	assert.True(t, handlerCalled)
	assert.Equal(t, "custom_token", receivedEvent.SessionToken)
	assert.Equal(t, "busy", receivedEvent.Disposition)
}

// TestConsumer_PublishAndConsume tests publishing to Redis and consuming.
func TestConsumer_PublishAndConsume(t *testing.T) {
	redisClient := setupTestRedis(t)
	defer redisClient.Close()

	concurrencyManager := concurrency.NewManager(redisClient)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	campaignID := int64(200)

	// Start a call
	err := concurrencyManager.StartCall(ctx, campaignID, "pubsub_token")
	require.NoError(t, err)

	// Setup handler to track events
	eventsReceived := make(chan Event, 1)
	handler := func(ctx context.Context, event Event) error {
		eventsReceived <- event
		return nil
	}

	// Start consumer
	consumer := NewConsumer(redisClient, concurrencyManager, handler)
	go consumer.Start(ctx)

	// Wait for consumer to subscribe
	time.Sleep(100 * time.Millisecond)

	// Publish event to Redis
	event := Event{
		Type:          "call.completed",
		SessionToken:  "pubsub_token",
		CampaignID:    campaignID,
		DestinationID: 456,
		SessionID:     789,
		Disposition:   "answered",
		Duration:      30,
		Billsec:       25,
		Timestamp:     time.Now().Format(time.RFC3339),
	}

	payload, _ := json.Marshal(event)
	err = redisClient.Publish(ctx, ChannelName, payload).Err()
	require.NoError(t, err)

	// Wait for event to be processed
	select {
	case received := <-eventsReceived:
		assert.Equal(t, "pubsub_token", received.SessionToken)
		assert.Equal(t, "answered", received.Disposition)
	case <-time.After(2 * time.Second):
		t.Fatal("Timeout waiting for event")
	}

	// Verify counter was decremented
	count, _ := concurrencyManager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 0, count)
}

// TestConsumer_MultipleEvents tests processing multiple CDR events.
func TestConsumer_MultipleEvents(t *testing.T) {
	redisClient := setupTestRedis(t)
	defer redisClient.Close()

	concurrencyManager := concurrency.NewManager(redisClient)
	ctx := context.Background()
	campaignID := int64(300)

	// Start multiple calls
	tokens := []string{"token_a", "token_b", "token_c"}
	for _, token := range tokens {
		err := concurrencyManager.StartCall(ctx, campaignID, token)
		require.NoError(t, err)
	}

	count, _ := concurrencyManager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 3, count)

	consumer := NewConsumer(redisClient, concurrencyManager, nil)

	// Process CDR for each call
	for i, token := range tokens {
		event := Event{
			Type:          "call.completed",
			SessionToken:  token,
			CampaignID:    campaignID,
			DestinationID: int64(i),
			SessionID:     int64(i),
			Disposition:   "answered",
			Duration:      30,
			Billsec:       25,
			Timestamp:     time.Now().Format(time.RFC3339),
		}

		payload, _ := json.Marshal(event)
		err := consumer.processMessage(ctx, string(payload))
		require.NoError(t, err)
	}

	// Verify all calls completed
	count, _ = concurrencyManager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 0, count)

	sessions, _ := concurrencyManager.GetActiveSessions(ctx, campaignID)
	assert.Len(t, sessions, 0)
}

// BenchmarkProcessMessage benchmarks CDR event processing.
func BenchmarkProcessMessage(b *testing.B) {
	addr := "localhost:6379"
	client := redis.NewClient(&redis.Options{
		Addr: addr,
		DB:   1,
	})

	ctx := context.Background()
	err := client.Ping(ctx).Err()
	if err != nil {
		b.Skipf("Redis not available: %v", err)
	}
	defer client.Close()

	concurrencyManager := concurrency.NewManager(client)
	consumer := NewConsumer(client, concurrencyManager, nil)

	event := Event{
		Type:          "call.completed",
		SessionToken:  "bench_token",
		CampaignID:    100,
		DestinationID: 200,
		SessionID:     300,
		Disposition:   "answered",
		Duration:      30,
		Billsec:       25,
		Timestamp:     time.Now().Format(time.RFC3339),
	}
	payload, _ := json.Marshal(event)

	b.ResetTimer()
	for i := 0; i < b.N; i++ {
		// Start call
		concurrencyManager.StartCall(ctx, 100, "bench_token")

		// Process CDR
		consumer.processMessage(ctx, string(payload))
	}
}
