package api

import (
	"context"
	"errors"
	"io"
	"log/slog"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"opbx/dialer-worker/internal/config"
	apierrors "opbx/dialer-worker/pkg/errors"
)

func testClient(url string) *Client {
	return NewClient(&config.Config{LaravelAPIURL: url, LaravelAPIToken: "t"}, slog.New(slog.NewTextHandler(io.Discard, nil)))
}

func TestInitiateMapsConflictAndSendsIdempotencyKey(t *testing.T) {
	var key string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		key = r.Header.Get("Idempotency-Key")
		w.WriteHeader(http.StatusConflict)
	}))
	defer srv.Close()

	_, err := testClient(srv.URL).InitiateCallWithCallerID(context.Background(), 1, 2, "+1555", "w", "", 0, "2-0")
	if !errors.Is(err, apierrors.ErrDestinationConflict) {
		t.Fatalf("want conflict, got %v", err)
	}
	if key != "2-0" {
		t.Fatalf("idempotency key %q", key)
	}
}

func TestErrorBodiesAreTruncatedAndRetryAfterIsHonoured(t *testing.T) {
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/limited" {
			w.Header().Set("Retry-After", "7")
			w.WriteHeader(http.StatusTooManyRequests)
			return
		}
		w.WriteHeader(http.StatusInternalServerError)
		w.Write([]byte(strings.Repeat("x", 100000)))
	}))
	defer srv.Close()
	c := testClient(srv.URL)

	err := c.get(context.Background(), "/big", nil)
	if err == nil || len(err.Error()) > 700 {
		t.Fatalf("error not truncated: %d bytes", len(err.Error()))
	}

	err = c.get(context.Background(), "/limited", nil)
	var rl *apierrors.RetryableError
	if !errors.As(err, &rl) || rl.RetryAfter.Seconds() != 7 {
		t.Fatalf("want 7s retryable, got %v", err)
	}
}
