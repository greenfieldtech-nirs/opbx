# Caller ID Pooling - Go Worker Specification

## Overview
Changes to the Go Dialer Worker to support Caller ID pooling with local strategy implementation.

---

## 1. Architecture Changes

### 1.1 Current Flow
```
[Main Loop] -> GET /campaigns/active
  -> for each destination:
    -> CanDial() -> POST /calls/initiate (Laravel selects Caller ID)
    -> Increment CAC
```

### 1.2 New Flow
```
[Main Loop] -> GET /campaigns/active (receives full Caller ID pool)
  -> for each destination:
    -> Select Caller ID locally using strategy
    -> CanDial() -> POST /calls/initiate (includes selected Caller ID)
    -> Increment CAC
    -> Update strategy state (Redis)
```

---

## 2. Data Models

### 2.1 Campaign Model Updates

**File:** `dialer-worker/internal/models/models.go`

```go
package models

import "time"

type Campaign struct {
    ID                     int64               `json:"id"`
    Name                   string              `json:"name"`
    OrganizationID         int64               `json:"organization_id"`
    Status                 string              `json:"status"`
    
    // Existing fields
    CallerID               string              `json:"caller_id"`           // Legacy single Caller ID
    ConcurrentActiveCalls  int                 `json:"concurrent_active_calls"`
    CallsPerSecond         int                 `json:"calls_per_second"`
    
    // New Caller ID Pool fields
    CallerIDPoolEnabled    bool                `json:"caller_id_pool_enabled"`
    CallerIDPool           []CallerIDPoolItem  `json:"caller_id_pool"`
    CallerIDStrategy       CallerIDStrategy    `json:"caller_id_strategy"`
    
    // Existing routing fields
    RoutingDestinationType string              `json:"routing_destination_type"`
    RoutingDestinationID   int64               `json:"routing_destination_id"`
    MaxDialAttempts        int                 `json:"max_dial_attempts"`
    DialTimeout            int                 `json:"dial_timeout"`
    AmdEnabled             bool                `json:"amd_enabled"`
    AmdMode                string              `json:"amd_mode"`
}

type CallerIDPoolItem struct {
    DIDID       int64  `json:"did_id"`
    PhoneNumber string `json:"phone_number"`
    Weight      int    `json:"weight"`
}

type CallerIDStrategy string

const (
    StrategyRoundRobin         CallerIDStrategy = "round_robin"
    StrategyRandom             CallerIDStrategy = "random"
    StrategyLeastRecentlyUsed  CallerIDStrategy = "least_recently_used"
)
```

---

## 3. Strategy Implementation

### 3.1 Strategy Interface

**New File:** `dialer-worker/internal/callerid/strategy.go`

```go
package callerid

import (
    "context"
    "dialer-worker/internal/models"
    "dialer-worker/internal/redis"
)

// RetryContext tracks Caller IDs already tried for a destination
type RetryContext struct {
    DestinationID int64
    CampaignID    int64
    AttemptNumber int
    TriedDIDs     []int64
}

// Strategy defines the interface for Caller ID selection strategies
type Strategy interface {
    // Select chooses the next Caller ID from the pool
    // Returns the selected Caller ID and its DID ID
    Select(ctx context.Context, campaignID int64, pool []models.CallerIDPoolItem) (*models.CallerIDPoolItem, error)
    
    // SelectWithRetry selects a Caller ID excluding already tried DIDs
    // Used when retrying a failed call to ensure a different Caller ID is used
    SelectWithRetry(ctx context.Context, campaignID int64, pool []models.CallerIDPoolItem, triedDIDs []int64) (*models.CallerIDPoolItem, error)
    
    // Name returns the strategy identifier
    Name() string
}

// StrategyFactory creates the appropriate strategy implementation
type StrategyFactory struct {
    redisClient *redis.Client
}

func NewStrategyFactory(redisClient *redis.Client) *StrategyFactory {
    return &StrategyFactory{redisClient: redisClient}
}

func (f *StrategyFactory) Create(strategy models.CallerIDStrategy) Strategy {
    switch strategy {
    case models.StrategyRoundRobin:
        return NewRoundRobinStrategy(f.redisClient)
    case models.StrategyRandom:
        return NewRandomStrategy()
    case models.StrategyLeastRecentlyUsed:
        return NewLRUStrategy(f.redisClient)
    default:
        // Default to round robin
        return NewRoundRobinStrategy(f.redisClient)
    }
}
```

---

### 3.2 Round Robin Strategy

**New File:** `dialer-worker/internal/callerid/round_robin.go`

```go
package callerid

import (
    "context"
    "fmt"
    
    "dialer-worker/internal/models"
    "dialer-worker/internal/redis"
)

const roundRobinKeyTemplate = "dialer:rr:%d:position"

type RoundRobinStrategy struct {
    redisClient *redis.Client
}

func NewRoundRobinStrategy(redisClient *redis.Client) *RoundRobinStrategy {
    return &RoundRobinStrategy{redisClient: redisClient}
}

func (s *RoundRobinStrategy) Name() string {
    return "round_robin"
}

func (s *RoundRobinStrategy) Select(
    ctx context.Context, 
    campaignID int64, 
    pool []models.CallerIDPoolItem,
) (*models.CallerIDPoolItem, error) {
    return s.SelectWithRetry(ctx, campaignID, pool, nil)
}

func (s *RoundRobinStrategy) SelectWithRetry(
    ctx context.Context,
    campaignID int64,
    pool []models.CallerIDPoolItem,
    triedDIDs []int64,
) (*models.CallerIDPoolItem, error) {
    if len(pool) == 0 {
        return nil, fmt.Errorf("caller ID pool is empty")
    }
    
    // Build exclusion set from tried DIDs
    excludedDIDs := make(map[int64]bool)
    for _, didID := range triedDIDs {
        excludedDIDs[didID] = true
    }
    
    // Filter pool to exclude tried DIDs
    availablePool := make([]models.CallerIDPoolItem, 0)
    for _, item := range pool {
        if !excludedDIDs[item.DIDID] {
            availablePool = append(availablePool, item)
        }
    }
    
    // If all DIDs have been tried, reset and use full pool
    if len(availablePool) == 0 {
        availablePool = pool
    }
    
    // Handle weighted round robin
    // Expand pool based on weights: [A:1, B:2] -> [A, B, B]
    expandedPool := s.expandPoolByWeight(availablePool)
    poolSize := len(expandedPool)
    
    // Get current position from Redis
    key := fmt.Sprintf(roundRobinKeyTemplate, campaignID)
    
    // Atomically increment and get new value
    position, err := s.redisClient.Incr(ctx, key)
    if err != nil {
        // Fallback to random on Redis error
        position = int64(randomInt(poolSize))
    }
    
    // Calculate index (1-based position to 0-based index)
    index := int((position - 1) % int64(poolSize))
    selected := expandedPool[index]
    
    // Set TTL on the key (24 hours)
    s.redisClient.Expire(ctx, key, 24*60*60)
    
    return &selected, nil
}

// expandPoolByWeight creates a weighted slice for fair distribution
func (s *RoundRobinStrategy) expandPoolByWeight(pool []models.CallerIDPoolItem) []models.CallerIDPoolItem {
    var expanded []models.CallerIDPoolItem
    for _, item := range pool {
        weight := item.Weight
        if weight < 1 {
            weight = 1
        }
        for i := 0; i < weight; i++ {
            expanded = append(expanded, item)
        }
    }
    return expanded
}
```

---

### 3.3 Random Strategy

**New File:** `dialer-worker/internal/callerid/random.go`

```go
package callerid

import (
    "context"
    "fmt"
    "math/rand"
    "time"
    
    "dialer-worker/internal/models"
)

func init() {
    rand.Seed(time.Now().UnixNano())
}

type RandomStrategy struct{}

func NewRandomStrategy() *RandomStrategy {
    return &RandomStrategy{}
}

func (s *RandomStrategy) Name() string {
    return "random"
}

func (s *RandomStrategy) Select(
    ctx context.Context,
    campaignID int64,
    pool []models.CallerIDPoolItem,
) (*models.CallerIDPoolItem, error) {
    if len(pool) == 0 {
        return nil, fmt.Errorf("caller ID pool is empty")
    }
    
    // Weighted random selection
    totalWeight := 0
    for _, item := range pool {
        weight := item.Weight
        if weight < 1 {
            weight = 1
        }
        totalWeight += weight
    }
    
    // Generate random number in range [1, totalWeight]
    target := rand.Intn(totalWeight) + 1
    
    // Find the selected item
    cumulativeWeight := 0
    for _, item := range pool {
        weight := item.Weight
        if weight < 1 {
            weight = 1
        }
        cumulativeWeight += weight
        if target <= cumulativeWeight {
            // Return copy to avoid pointer issues
            selected := item
            return &selected, nil
        }
    }
    
    // Fallback to last item (should not reach here)
    selected := pool[len(pool)-1]
    return &selected, nil
}

func randomInt(max int) int {
    return rand.Intn(max)
}
```

---

### 3.4 LRU (Least Recently Used) Strategy

**New File:** `dialer-worker/internal/callerid/lru.go`

```go
package callerid

import (
    "context"
    "fmt"
    "strconv"
    "time"
    
    "dialer-worker/internal/models"
    "dialer-worker/internal/redis"
)

const lruKeyTemplate = "dialer:lru:%d:timestamps"
type LRUStrategy struct {
    redisClient *redis.Client
}

func NewLRUStrategy(redisClient *redis.Client) *LRUStrategy {
    return &LRUStrategy{redisClient: redisClient}
}

func (s *LRUStrategy) Name() string {
    return "least_recently_used"
}

func (s *LRUStrategy) Select(
    ctx context.Context,
    campaignID int64,
    pool []models.CallerIDPoolItem,
) (*models.CallerIDPoolItem, error) {
    if len(pool) == 0 {
        return nil, fmt.Errorf("caller ID pool is empty")
    }
    
    key := fmt.Sprintf(lruKeyTemplate, campaignID)
    
    // Build map of DID IDs in pool for quick lookup
    poolDIDs := make(map[int64]models.CallerIDPoolItem)
    for _, item := range pool {
        poolDIDs[item.DIDID] = item
    }
    
    // Get all timestamps from Redis
    timestamps, err := s.redisClient.HGetAll(ctx, key)
    if err != nil {
        // On Redis error, fall back to random
        return s.fallbackRandom(pool)
    }
    
    // Find the least recently used DID that's in our pool
    var selected *models.CallerIDPoolItem
    var oldestTime int64 = -1
    now := time.Now().Unix()
    
    // Check all items in pool
    for didID, item := range poolDIDs {
        timestampStr, exists := timestamps[strconv.FormatInt(didID, 10)]
        
        if !exists {
            // Never used - this is our candidate (use negative timestamp for sorting)
            if oldestTime == -1 || 0 < oldestTime {
                selected = &item
                oldestTime = 0
            }
            continue
        }
        
        timestamp, err := strconv.ParseInt(timestampStr, 10, 64)
        if err != nil {
            continue
        }
        
        if oldestTime == -1 || timestamp < oldestTime {
            // Make a copy to avoid pointer issues
            itemCopy := item
            selected = &itemCopy
            oldestTime = timestamp
        }
    }
    
    // If nothing found (shouldn't happen), pick first
    if selected == nil {
        itemCopy := pool[0]
        selected = &itemCopy
    }
    
    // Update the timestamp for the selected DID
    s.redisClient.HSet(ctx, key, strconv.FormatInt(selected.DIDID, 10), now)
    s.redisClient.Expire(ctx, key, 24*60*60) // 24 hour TTL
    
    return selected, nil
}

func (s *LRUStrategy) fallbackRandom(pool []models.CallerIDPoolItem) (*models.CallerIDPoolItem, error) {
    // Simple random fallback
    index := rand.Intn(len(pool))
    selected := pool[index]
    return &selected, nil
}

// MarkAsUsed updates the LRU timestamp for a DID (called after successful call initiation)
func (s *LRUStrategy) MarkAsUsed(ctx context.Context, campaignID, didID int64) error {
    key := fmt.Sprintf(lruKeyTemplate, campaignID)
    now := time.Now().Unix()
    return s.redisClient.HSet(ctx, key, strconv.FormatInt(didID, 10), now)
}
```

---

## 4. Redis Client Updates

### 4.1 New Methods

**File:** `dialer-worker/internal/redis/client.go`

```go
package redis

import (
    "context"
    "fmt"
    "strconv"
    "time"
    
    "github.com/go-redis/redis/v8"
)

// Existing methods...

// Incr atomically increments a key and returns the new value
func (c *Client) Incr(ctx context.Context, key string) (int64, error) {
    fullKey := c.prefixKey(key)
    return c.client.Incr(ctx, fullKey).Result()
}

// Expire sets a TTL on a key
func (c *Client) Expire(ctx context.Context, key string, seconds int) error {
    fullKey := c.prefixKey(key)
    return c.client.Expire(ctx, fullKey, time.Duration(seconds)*time.Second).Err()
}

// HGetAll gets all fields from a hash
func (c *Client) HGetAll(ctx context.Context, key string) (map[string]string, error) {
    fullKey := c.prefixKey(key)
    return c.client.HGetAll(ctx, fullKey).Result()
}

// HSet sets a field in a hash
func (c *Client) HSet(ctx context.Context, key string, field string, value interface{}) error {
    fullKey := c.prefixKey(key)
    return c.client.HSet(ctx, fullKey, field, value).Err()
}

// SAdd adds members to a set
func (c *Client) SAdd(ctx context.Context, key string, members ...interface{}) error {
    fullKey := c.prefixKey(key)
    return c.client.SAdd(ctx, fullKey, members...).Err()
}

// SMembers returns all members of a set
func (c *Client) SMembers(ctx context.Context, key string) ([]string, error) {
    fullKey := c.prefixKey(key)
    return c.client.SMembers(ctx, fullKey).Result()
}

// Del deletes a key
func (c *Client) Del(ctx context.Context, key string) error {
    fullKey := c.prefixKey(key)
    return c.client.Del(ctx, fullKey).Err()
}
```

---

## 5. Retry Tracking

### 5.1 Retry State Management

**Purpose:** Track which Caller IDs have been tried for a destination to ensure different Caller IDs are used on retries.

**Redis Key Pattern:** `dialer:retry:{campaign_id}:{destination_id}`

**File:** `dialer-worker/internal/callerid/retry_tracker.go`

```go
package callerid

import (
    "context"
    "fmt"
    "strconv"
    
    "dialer-worker/internal/redis"
)

const retryTrackerKeyTemplate = "dialer:retry:%d:%d"

type RetryTracker struct {
    redisClient *redis.Client
}

func NewRetryTracker(redisClient *redis.Client) *RetryTracker {
    return &RetryTracker{redisClient: redisClient}
}

// GetTriedDIDs returns the list of DIDs already tried for a destination
func (rt *RetryTracker) GetTriedDIDs(ctx context.Context, campaignID, destinationID int64) ([]int64, error) {
    key := fmt.Sprintf(retryTrackerKeyTemplate, campaignID, destinationID)
    members, err := rt.redisClient.SMembers(ctx, key)
    if err != nil {
        return nil, err
    }
    
    triedDIDs := make([]int64, 0, len(members))
    for _, member := range members {
        didID, err := strconv.ParseInt(member, 10, 64)
        if err != nil {
            continue
        }
        triedDIDs = append(triedDIDs, didID)
    }
    
    return triedDIDs, nil
}

// MarkDIDAsTried adds a DID to the tried set for a destination
func (rt *RetryTracker) MarkDIDAsTried(ctx context.Context, campaignID, destinationID, didID int64) error {
    key := fmt.Sprintf(retryTrackerKeyTemplate, campaignID, destinationID)
    return rt.redisClient.SAdd(ctx, key, didID)
}

// ClearTriedDIDs removes the retry tracking for a destination (call succeeded or max retries reached)
func (rt *RetryTracker) ClearTriedDIDs(ctx context.Context, campaignID, destinationID int64) error {
    key := fmt.Sprintf(retryTrackerKeyTemplate, campaignID, destinationID)
    return rt.redisClient.Del(ctx, key)
}
```

---

## 6. Executor Updates

### 5.1 Call Initiation Flow

**File:** `dialer-worker/internal/executor/executor.go`

```go
package executor

import (
    "context"
    "fmt"
    
    "dialer-worker/internal/callerid"
    "dialer-worker/internal/models"
)

type Executor struct {
    apiClient       *api.Client
    redisClient     *redis.Client
    strategyFactory *callerid.StrategyFactory
    retryTracker    *callerid.RetryTracker
}

func NewExecutor(apiClient *api.Client, redisClient *redis.Client) *Executor {
    return &Executor{
        apiClient:       apiClient,
        redisClient:     redisClient,
        strategyFactory: callerid.NewStrategyFactory(redisClient),
        retryTracker:    callerid.NewRetryTracker(redisClient),
    }
}

func (e *Executor) ExecuteCall(
    ctx context.Context,
    campaign *models.Campaign,
    destination *models.Destination,
) error {
    // ... existing CAC check ...
    
    // Check if this is a retry (dial_attempts > 0 means previous attempts failed)
    isRetry := destination.DialAttempts > 0
    
    // Select Caller ID (use different one on retry)
    selectedCallerID, err := e.selectCallerID(ctx, campaign, destination, isRetry)
    if err != nil {
        return fmt.Errorf("failed to select caller ID: %w", err)
    }
    
    // Build initiate request with selected Caller ID
    req := &InitiateCallRequest{
        CampaignID:    campaign.ID,
        DestinationID: destination.ID,
        PhoneNumber:   destination.PhoneNumber,
        CallerID:      selectedCallerID.PhoneNumber,
        CallerDIDID:   selectedCallerID.DIDID,
    }
    
    // Call Laravel API
    resp, err := e.apiClient.InitiateCall(ctx, req)
    if err != nil {
        // Mark this DID as tried for retry tracking
        if campaign.CallerIDPoolEnabled {
            e.retryTracker.MarkDIDAsTried(ctx, campaign.ID, destination.ID, selectedCallerID.DIDID)
        }
        return fmt.Errorf("failed to initiate call: %w", err)
    }
    
    // Store call state with Caller ID info
    callState := &models.CallState{
        SessionToken:  resp.SessionToken,
        CallID:        resp.CallID,
        CampaignID:    campaign.ID,
        DestinationID: destination.ID,
        CallerID:      selectedCallerID.PhoneNumber,
        CallerDIDID:   selectedCallerID.DIDID,
        Status:        "initiated",
    }
    
    if err := e.redisClient.SetCallState(ctx, callState); err != nil {
        return fmt.Errorf("failed to store call state: %w", err)
    }
    
    // Update LRU timestamp if using LRU strategy
    if campaign.CallerIDStrategy == models.StrategyLeastRecentlyUsed {
        lruStrategy := e.strategyFactory.Create(models.StrategyLeastRecentlyUsed).(*callerid.LRUStrategy)
        lruStrategy.MarkAsUsed(ctx, campaign.ID, selectedCallerID.DIDID)
    }
    
    // Mark DID as tried for potential retries
    if campaign.CallerIDPoolEnabled {
        e.retryTracker.MarkDIDAsTried(ctx, campaign.ID, destination.ID, selectedCallerID.DIDID)
    }
    
    // Increment CAC
    if err := e.redisClient.IncrementCAC(ctx, campaign.ID); err != nil {
        return fmt.Errorf("failed to increment CAC: %w", err)
    }
    
    return nil
}

func (e *Executor) selectCallerID(
    ctx context.Context,
    campaign *models.Campaign,
    destination *models.Destination,
    isRetry bool,
) (*models.CallerIDPoolItem, error) {
    // If pool not enabled, use legacy Caller ID
    if !campaign.CallerIDPoolEnabled {
        return &models.CallerIDPoolItem{
            DIDID:       0, // Unknown for legacy
            PhoneNumber: campaign.CallerID,
            Weight:      1,
        }, nil
    }
    
    // Create strategy
    strategy := e.strategyFactory.Create(campaign.CallerIDStrategy)
    
    // If retrying, get tried DIDs and select a different one
    if isRetry && campaign.CallerIDPoolEnabled {
        triedDIDs, err := e.retryTracker.GetTriedDIDs(ctx, campaign.ID, destination.ID)
        if err == nil && len(triedDIDs) > 0 {
            // Use SelectWithRetry to exclude tried DIDs
            return strategy.SelectWithRetry(ctx, campaign.ID, campaign.CallerIDPool, triedDIDs)
        }
    }
    
    // First attempt or no retry tracking available
    return strategy.Select(ctx, campaign.ID, campaign.CallerIDPool)
}
```

---

## 6. API Client Updates

### 6.1 Initiate Call Request

**File:** `dialer-worker/internal/api/client.go`

```go
package api

type InitiateCallRequest struct {
    CampaignID    int64  `json:"campaign_id"`
    DestinationID int64  `json:"destination_id"`
    PhoneNumber   string `json:"phone_number"`
    CallerID      string `json:"caller_id"`
    CallerDIDID   int64  `json:"caller_did_id"`
}

type InitiateCallResponse struct {
    SessionToken string `json:"session_token"`
    CallID       string `json:"call_id"`
    Status       string `json:"status"`
}
```

---

## 7. Configuration

### 7.1 Environment Variables

No new environment variables required. Uses existing Redis configuration.

### 7.2 Redis Key Patterns

| Pattern | Purpose | TTL |
|---------|---------|-----|
| `dialer:rr:{campaign_id}:position` | Round robin counter | 24h |
| `dialer:lru:{campaign_id}:timestamps` | LRU timestamps hash | 24h |
| `dialer:retry:{campaign_id}:{destination_id}` | Retry tracking (tried DIDs) | 1h |

---

## 8. Error Handling

### 8.1 Strategy Errors

| Error | Action |
|-------|--------|
| Empty pool | Log error, skip call |
| Redis connection failure | Fall back to random selection |
| Invalid strategy | Default to round robin |

### 8.2 Recovery

```go
func (e *Executor) ExecuteCallWithRecovery(
    ctx context.Context,
    campaign *models.Campaign,
    destination *models.Destination,
) error {
    err := e.ExecuteCall(ctx, campaign, destination)
    if err != nil {
        // Log error but don't block other calls
        log.Printf("Call execution failed for campaign %d, destination %d: %v",
            campaign.ID, destination.ID, err)
        
        // Update destination status for retry
        e.apiClient.UpdateDestinationStatus(ctx, destination.ID, "failed", err.Error())
    }
    return err
}
```

---

## 9. Testing

### 9.1 Unit Tests

**File:** `dialer-worker/internal/callerid/strategy_test.go`

```go
package callerid

import (
    "context"
    "testing"
    
    "dialer-worker/internal/models"
)

func TestRoundRobinStrategy(t *testing.T) {
    pool := []models.CallerIDPoolItem{
        {DIDID: 1, PhoneNumber: "+1234567890", Weight: 1},
        {DIDID: 2, PhoneNumber: "+1234567891", Weight: 1},
        {DIDID: 3, PhoneNumber: "+1234567892", Weight: 1},
    }
    
    strategy := NewRoundRobinStrategy(mockRedis)
    
    // Should cycle: 1, 2, 3, 1, 2...
    for i := 0; i < 6; i++ {
        selected, err := strategy.Select(context.Background(), 1, pool)
        if err != nil {
            t.Fatalf("unexpected error: %v", err)
        }
        expectedID := pool[i%3].DIDID
        if selected.DIDID != expectedID {
            t.Errorf("iteration %d: expected DID %d, got %d", i, expectedID, selected.DIDID)
        }
    }
}

func TestRandomStrategyDistribution(t *testing.T) {
    pool := []models.CallerIDPoolItem{
        {DIDID: 1, PhoneNumber: "+1234567890", Weight: 1},
        {DIDID: 2, PhoneNumber: "+1234567891", Weight: 1},
    }
    
    strategy := NewRandomStrategy()
    
    // Run 1000 selections and verify both are selected
    counts := make(map[int64]int)
    for i := 0; i < 1000; i++ {
        selected, err := strategy.Select(context.Background(), 1, pool)
        if err != nil {
            t.Fatalf("unexpected error: %v", err)
        }
        counts[selected.DIDID]++
    }
    
    // Both should be selected (with high probability)
    if counts[1] == 0 || counts[2] == 0 {
        t.Error("random strategy did not select all items")
    }
    
    // Should be roughly 50/50 (within 10% tolerance)
    ratio := float64(counts[1]) / float64(counts[1]+counts[2])
    if ratio < 0.4 || ratio > 0.6 {
        t.Errorf("distribution not uniform: got %.2f ratio", ratio)
    }
}
```

---

## 10. Deployment

### 10.1 Order of Deployment

1. Deploy Laravel changes (database migration, API updates)
2. Verify API returns Caller ID pool data
3. Deploy Go worker changes
4. Monitor for errors

### 10.2 Rollback

If issues detected:
1. Revert Go worker to previous version
2. Worker will ignore new fields (backward compatible)
3. Fix issues and redeploy
