package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"os"
	"testing"
	"time"

	"opbx/dialer-worker/internal/models"
	apierrors "opbx/dialer-worker/pkg/errors"
)

// The aggregation stack parses these exact keys; a regression here silently
// breaks label extraction for every dialer log line.
func TestNewLoggerEmitsContractKeys(t *testing.T) {
	r, w, err := os.Pipe()
	if err != nil {
		t.Fatal(err)
	}
	defer r.Close()

	newLogger(w).Info("test message", "session_token", "sess-x", "call_id", "42")
	w.Close()

	var line map[string]any
	if err := json.NewDecoder(r).Decode(&line); err != nil {
		t.Fatal(err)
	}

	for _, key := range []string{"ts", "level", "msg", "service", "log_type", "session_token", "call_id"} {
		if _, ok := line[key]; !ok {
			t.Errorf("missing contract key %q in %v", key, line)
		}
	}
	if line["level"] != "info" {
		t.Errorf("level should be lowercase, got %v", line["level"])
	}
	if line["service"] != "dialer-worker" || line["log_type"] != "call_flow" {
		t.Errorf("wrong fixed dims: %v", line)
	}
}

type fakeExec struct {
	calls int
	errs  []error
}

func (f *fakeExec) ExecuteCall(context.Context, *models.Campaign, *models.Destination) error {
	i := f.calls
	f.calls++
	if i < len(f.errs) {
		return f.errs[i]
	}
	return nil
}

type noPacer struct{}

func (noPacer) RegisterCampaign(int64, int, int) {}
func (noPacer) WaitTime(int64) time.Duration     { return 0 }

func testWorker(exec callExecutor) *Worker {
	return &Worker{
		logger:   slog.New(slog.NewTextHandler(io.Discard, nil)),
		executor: exec,
		limiter:  noPacer{},
		shutdown: make(chan struct{}),
	}
}

func TestBatchAbortsAfterBackingOffOn429(t *testing.T) {
	exec := &fakeExec{errs: []error{
		nil,
		fmt.Errorf("failed to initiate call: %w", &apierrors.RetryableError{Err: apierrors.ErrRateLimitExceeded, RetryAfter: 5 * time.Millisecond}),
	}}
	w := testWorker(exec)
	start := time.Now()
	w.dialBatch(context.Background(), w.logger, &models.Campaign{ID: 1}, make([]models.Destination, 5))

	if exec.calls != 2 {
		t.Fatalf("batch must stop after the 429: %d calls", exec.calls)
	}
	if time.Since(start) < 5*time.Millisecond {
		t.Fatal("did not honour Retry-After")
	}
}

func TestBatchContinuesAfterOrdinaryError(t *testing.T) {
	exec := &fakeExec{errs: []error{fmt.Errorf("boom")}}
	w := testWorker(exec)
	w.dialBatch(context.Background(), w.logger, &models.Campaign{ID: 1}, make([]models.Destination, 3))
	if exec.calls != 3 {
		t.Fatalf("calls=%d", exec.calls)
	}
}

func TestBackoffIsInterruptedByShutdown(t *testing.T) {
	w := testWorker(&fakeExec{errs: []error{&apierrors.RetryableError{Err: apierrors.ErrRateLimitExceeded, RetryAfter: time.Hour}}})
	close(w.shutdown)
	done := make(chan struct{})
	go func() {
		w.dialBatch(context.Background(), w.logger, &models.Campaign{ID: 1}, make([]models.Destination, 2))
		close(done)
	}()
	select {
	case <-done:
	case <-time.After(time.Second):
		t.Fatal("shutdown did not interrupt the backoff")
	}
}
