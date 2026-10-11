package limiter

import (
	"context"
	"sync"
	"testing"
)

// fakeStore mimics the Redis counter semantics (cap on increment, clamped decrement).
type fakeStore struct {
	mu sync.Mutex
	n  int64
}

func (f *fakeStore) IncrementIfBelow(_ context.Context, _ string, max int64) (int64, bool, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.n >= max {
		return f.n, false, nil
	}
	f.n++
	return f.n, true, nil
}

func (f *fakeStore) DecrementActiveCalls(_ context.Context, _ int64) (int64, error) {
	f.mu.Lock()
	defer f.mu.Unlock()
	if f.n > 0 {
		f.n--
	}
	return f.n, nil
}

func TestCanDialStopsAtCACAndRollbackFreesTheSlot(t *testing.T) {
	ctx := context.Background()
	store := &fakeStore{}
	rl := NewCACRateLimiter(store)
	rl.RegisterCampaign(1, 2, 30)
	rl.campaigns[1].MinInterval = 0 // isolate CAC from CPS pacing

	for i := 1; i <= 2; i++ {
		ok, n, err := rl.CanDial(ctx, 1)
		if err != nil || !ok || n != int64(i) {
			t.Fatalf("dial %d: ok=%v n=%d err=%v", i, ok, n, err)
		}
	}
	if ok, _, _ := rl.CanDial(ctx, 1); ok {
		t.Fatal("third dial must be refused at CAC=2")
	}

	if _, err := rl.DecrementActive(ctx, 1); err != nil {
		t.Fatal(err)
	}
	if ok, _, _ := rl.CanDial(ctx, 1); !ok {
		t.Fatal("rollback must free a slot")
	}
}

func TestRollbackNeverGoesNegative(t *testing.T) {
	store := &fakeStore{}
	rl := NewCACRateLimiter(store)
	if n, _ := rl.DecrementActive(context.Background(), 1); n != 0 || store.n != 0 {
		t.Fatalf("counter went to %d", store.n)
	}
}
