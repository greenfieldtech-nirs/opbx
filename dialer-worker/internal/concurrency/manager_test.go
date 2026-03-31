package concurrency

import (
	"context"
	"testing"
	"time"

	"github.com/go-redis/redis/v8"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// setupTestRedis creates a Redis client for testing.
// It uses the REDIS_TEST_ADDR environment variable or defaults to localhost:6379.
func setupTestRedis(t *testing.T) *redis.Client {
	addr := "localhost:6379"
	if envAddr := ""; envAddr != "" {
		addr = envAddr
	}

	client := redis.NewClient(&redis.Options{
		Addr:     addr,
		Password: "",
		DB:       1, // Use DB 1 for tests to avoid conflicts
	})

	// Test connection
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	err := client.Ping(ctx).Err()
	if err != nil {
		t.Skipf("Redis not available at %s: %v", addr, err)
	}

	// Clean up test database
	err = client.FlushDB(ctx).Err()
	require.NoError(t, err)

	return client
}

// TestManager_CanStartCall tests the CAC limit checking logic.
func TestManager_CanStartCall(t *testing.T) {
	client := setupTestRedis(t)
	defer client.Close()

	manager := NewManager(client)
	ctx := context.Background()
	campaignID := int64(123)
	cac := 5

	// Initially should be able to start (0 active < 5 CAC)
	assert.True(t, manager.CanStartCall(ctx, campaignID, cac))

	// Start some calls
	for i := 0; i < 5; i++ {
		err := manager.StartCall(ctx, campaignID, "token_"+string(rune('a'+i)))
		require.NoError(t, err)
	}

	// Now at limit, should not be able to start
	assert.False(t, manager.CanStartCall(ctx, campaignID, cac))

	// Complete one call
	err := manager.CompleteCall(ctx, campaignID, "token_a")
	require.NoError(t, err)

	// Now should be able to start again
	assert.True(t, manager.CanStartCall(ctx, campaignID, cac))
}

// TestManager_StartCall tests starting calls and incrementing counter.
func TestManager_StartCall(t *testing.T) {
	client := setupTestRedis(t)
	defer client.Close()

	manager := NewManager(client)
	ctx := context.Background()
	campaignID := int64(456)

	// Start first call
	err := manager.StartCall(ctx, campaignID, "token_1")
	require.NoError(t, err)

	count, err := manager.GetActiveCount(ctx, campaignID)
	require.NoError(t, err)
	assert.Equal(t, 1, count)

	// Start second call
	err = manager.StartCall(ctx, campaignID, "token_2")
	require.NoError(t, err)

	count, err = manager.GetActiveCount(ctx, campaignID)
	require.NoError(t, err)
	assert.Equal(t, 2, count)

	// Verify sessions set
	sessions, err := manager.GetActiveSessions(ctx, campaignID)
	require.NoError(t, err)
	assert.Len(t, sessions, 2)
	assert.Contains(t, sessions, "token_1")
	assert.Contains(t, sessions, "token_2")
}

// TestManager_CompleteCall tests completing calls and decrementing counter.
func TestManager_CompleteCall(t *testing.T) {
	client := setupTestRedis(t)
	defer client.Close()

	manager := NewManager(client)
	ctx := context.Background()
	campaignID := int64(789)

	// Start calls
	for i := 0; i < 3; i++ {
		err := manager.StartCall(ctx, campaignID, "token_"+string(rune('a'+i)))
		require.NoError(t, err)
	}

	count, _ := manager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 3, count)

	// Complete one call
	err := manager.CompleteCall(ctx, campaignID, "token_a")
	require.NoError(t, err)

	count, _ = manager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 2, count)

	// Verify session removed
	sessions, _ := manager.GetActiveSessions(ctx, campaignID)
	assert.Len(t, sessions, 2)
	assert.NotContains(t, sessions, "token_a")

	// Complete non-existent call (should not error or go negative)
	err = manager.CompleteCall(ctx, campaignID, "non_existent")
	require.NoError(t, err)

	count, _ = manager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 2, count) // Should remain 2
}

// TestManager_CompleteCall_NeverNegative ensures counter never goes below 0.
func TestManager_CompleteCall_NeverNegative(t *testing.T) {
	client := setupTestRedis(t)
	defer client.Close()

	manager := NewManager(client)
	ctx := context.Background()
	campaignID := int64(999)

	// Complete call without starting any
	err := manager.CompleteCall(ctx, campaignID, "token_1")
	require.NoError(t, err)

	count, _ := manager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 0, count) // Should be 0, not negative
}

// TestManager_ResetCampaign tests resetting campaign state.
func TestManager_ResetCampaign(t *testing.T) {
	client := setupTestRedis(t)
	defer client.Close()

	manager := NewManager(client)
	ctx := context.Background()
	campaignID := int64(111)

	// Start some calls
	for i := 0; i < 5; i++ {
		err := manager.StartCall(ctx, campaignID, "token_"+string(rune('a'+i)))
		require.NoError(t, err)
	}

	count, _ := manager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 5, count)

	// Reset campaign
	err := manager.ResetCampaign(ctx, campaignID)
	require.NoError(t, err)

	count, _ = manager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 0, count)

	sessions, _ := manager.GetActiveSessions(ctx, campaignID)
	assert.Len(t, sessions, 0)
}

// TestManager_ConcurrentAccess tests thread safety with concurrent operations.
func TestManager_ConcurrentAccess(t *testing.T) {
	client := setupTestRedis(t)
	defer client.Close()

	manager := NewManager(client)
	ctx := context.Background()
	campaignID := int64(222)

	// Concurrent starts
	done := make(chan bool, 10)
	for i := 0; i < 10; i++ {
		go func(i int) {
			err := manager.StartCall(ctx, campaignID, "token_"+string(rune('a'+i)))
			assert.NoError(t, err)
			done <- true
		}(i)
	}

	// Wait for all starts
	for i := 0; i < 10; i++ {
		<-done
	}

	count, _ := manager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 10, count)

	// Concurrent completes
	for i := 0; i < 10; i++ {
		go func(i int) {
			err := manager.CompleteCall(ctx, campaignID, "token_"+string(rune('a'+i)))
			assert.NoError(t, err)
			done <- true
		}(i)
	}

	// Wait for all completes
	for i := 0; i < 10; i++ {
		<-done
	}

	count, _ = manager.GetActiveCount(ctx, campaignID)
	assert.Equal(t, 0, count)
}

// TestManager_MultipleCampaigns tests isolation between campaigns.
func TestManager_MultipleCampaigns(t *testing.T) {
	client := setupTestRedis(t)
	defer client.Close()

	manager := NewManager(client)
	ctx := context.Background()

	campaign1 := int64(100)
	campaign2 := int64(200)

	// Start calls on campaign 1
	for i := 0; i < 3; i++ {
		err := manager.StartCall(ctx, campaign1, "c1_token_"+string(rune('a'+i)))
		require.NoError(t, err)
	}

	// Start calls on campaign 2
	for i := 0; i < 5; i++ {
		err := manager.StartCall(ctx, campaign2, "c2_token_"+string(rune('a'+i)))
		require.NoError(t, err)
	}

	// Verify separate counts
	count1, _ := manager.GetActiveCount(ctx, campaign1)
	count2, _ := manager.GetActiveCount(ctx, campaign2)
	assert.Equal(t, 3, count1)
	assert.Equal(t, 5, count2)

	// Complete call on campaign 1
	err := manager.CompleteCall(ctx, campaign1, "c1_token_a")
	require.NoError(t, err)

	count1, _ = manager.GetActiveCount(ctx, campaign1)
	count2, _ = manager.GetActiveCount(ctx, campaign2)
	assert.Equal(t, 2, count1) // Campaign 1 decreased
	assert.Equal(t, 5, count2) // Campaign 2 unchanged
}
