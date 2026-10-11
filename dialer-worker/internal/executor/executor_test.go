package executor

import (
	"context"
	"fmt"
	"io"
	"log/slog"
	"testing"

	"opbx/dialer-worker/internal/models"
	apierrors "opbx/dialer-worker/pkg/errors"
	"opbx/dialer-worker/pkg/retry"
)

type fakeAPI struct {
	err  error
	keys []string
}

func (f *fakeAPI) InitiateCallWithCallerID(_ context.Context, _, _ int64, _, _, _ string, _ int64, key string) (*models.InitiateCallResponse, error) {
	f.keys = append(f.keys, key)
	if f.err != nil {
		return nil, f.err
	}
	return &models.InitiateCallResponse{SessionID: 7, CallID: 9}, nil
}

type fakeLimiter struct{ reserved, released, recorded int }

func (l *fakeLimiter) CanDial(context.Context, int64) (bool, int64, error) {
	l.reserved++
	return true, int64(l.reserved), nil
}
func (l *fakeLimiter) DecrementActive(context.Context, int64) (int64, error) {
	l.released++
	return 0, nil
}
func (l *fakeLimiter) RecordCall(int64) { l.recorded++ }

func newTestExecutor(api callInitiator, rl slotLimiter) *Executor {
	// A nil redis client is fine: the caller-ID pool is disabled in these tests.
	return NewExecutor(api, nil, rl, &retry.Manager{}, "w1", slog.New(slog.NewTextHandler(io.Discard, nil)))
}

func TestExecuteCallConflictIsASkipAndFreesTheReservedSlot(t *testing.T) {
	api := &fakeAPI{err: fmt.Errorf("initiate: %w", apierrors.ErrDestinationConflict)}
	rl := &fakeLimiter{}
	err := newTestExecutor(api, rl).ExecuteCall(context.Background(), &models.Campaign{ID: 1}, &models.Destination{ID: 5, DialAttempts: 2})
	if err != nil {
		t.Fatalf("409 must be a skip, got %v", err)
	}
	if rl.released != 1 || rl.recorded != 0 {
		t.Fatalf("slot must be released and no call recorded: %+v", rl)
	}
	if len(api.keys) != 1 || api.keys[0] != "5-2" {
		t.Fatalf("idempotency key: %v", api.keys)
	}
}

func TestExecuteCallFailureReleasesTheSlotAndReturnsTheError(t *testing.T) {
	api := &fakeAPI{err: fmt.Errorf("boom")}
	rl := &fakeLimiter{}
	err := newTestExecutor(api, rl).ExecuteCall(context.Background(), &models.Campaign{ID: 1}, &models.Destination{ID: 5})
	if err == nil || rl.released != 1 {
		t.Fatalf("err=%v released=%d", err, rl.released)
	}
}

func TestExecuteCallSuccessKeepsTheSlot(t *testing.T) {
	rl := &fakeLimiter{}
	if err := newTestExecutor(&fakeAPI{}, rl).ExecuteCall(context.Background(), &models.Campaign{ID: 1}, &models.Destination{ID: 5}); err != nil {
		t.Fatal(err)
	}
	if rl.released != 0 || rl.recorded != 1 {
		t.Fatalf("%+v", rl)
	}
}
