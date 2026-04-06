package errors

import (
	"errors"
	"time"
)

// Worker errors
var (
	ErrCampaignNotFound    = errors.New("campaign not found")
	ErrDestinationNotFound = errors.New("destination not found")
	ErrSessionNotFound     = errors.New("session not found")
	ErrRateLimitExceeded   = errors.New("rate limit exceeded")
	ErrCACLimitReached     = errors.New("CAC limit reached")
	ErrCampaignNotRunnable = errors.New("campaign is not in runnable state")
	ErrMaxRetriesExceeded  = errors.New("maximum retries exceeded")
	ErrWebhookInvalid      = errors.New("invalid webhook payload")
	ErrWebhookUnauthorized = errors.New("unauthorized webhook request")
	ErrRedisConnection     = errors.New("redis connection error")
	ErrLaravelAPIError     = errors.New("laravel api error")
)

// RetryableError represents an error that can be retried
type RetryableError struct {
	Err        error
	RetryAfter time.Duration
}

func (e *RetryableError) Error() string {
	return e.Err.Error()
}

func (e *RetryableError) Unwrap() error {
	return e.Err
}

// IsRetryable checks if an error is retryable
func IsRetryable(err error) bool {
	var retryableErr *RetryableError
	return errors.As(err, &retryableErr)
}
