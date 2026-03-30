package cloudonix

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"time"

	"github.com/rs/zerolog/log"
)

// Client handles all communication with the Cloudonix API
type Client struct {
	baseURL    string
	apiKey     string
	domain     string
	httpClient *http.Client
}

// NewClient creates a new Cloudonix API client
func NewClient(baseURL, apiKey, domain string) *Client {
	return &Client{
		baseURL: baseURL,
		apiKey:  apiKey,
		domain:  domain,
		httpClient: &http.Client{
			Timeout: 30 * time.Second,
		},
	}
}

// MakeOutboundCall initiates an outbound call through Cloudonix
func (c *Client) MakeOutboundCall(ctx context.Context, req *OutboundCallRequest) (*OutboundCallResponse, error) {
	url := fmt.Sprintf("%s/api/v1/calls", c.baseURL)

	payload := map[string]interface{}{
		"from":              req.From,
		"to":                req.To,
		"domain":            c.domain,
		"application_id":    req.ApplicationID,
		"callback_url":      req.CallbackURL,
		"custom_parameters": req.CustomParameters,
		"timeout":           req.Timeout,
	}

	if req.AMDEnabled {
		payload["amd"] = map[string]interface{}{
			"enabled":       true,
			"timeout":       req.AMDTimeout,
			"intro_timeout": req.AMDIntroTimeout,
		}
	}

	body, err := json.Marshal(payload)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal request: %w", err)
	}

	httpReq, err := http.NewRequestWithContext(ctx, http.MethodPost, url, bytes.NewBuffer(body))
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("Authorization", fmt.Sprintf("Bearer %s", c.apiKey))

	start := time.Now()
	resp, err := c.httpClient.Do(httpReq)
	duration := time.Since(start)

	if err != nil {
		log.Error().
			Err(err).
			Str("to", req.To).
			Dur("duration_ms", duration).
			Msg("Cloudonix API call failed")
		return nil, fmt.Errorf("http request failed: %w", err)
	}
	defer resp.Body.Close()

	respBody, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read response body: %w", err)
	}

	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusCreated {
		log.Error().
			Int("status_code", resp.StatusCode).
			Str("response", string(respBody)).
			Str("to", req.To).
			Msg("Cloudonix API returned error")
		return nil, fmt.Errorf("api returned status %d: %s", resp.StatusCode, string(respBody))
	}

	var result OutboundCallResponse
	if err := json.Unmarshal(respBody, &result); err != nil {
		return nil, fmt.Errorf("failed to unmarshal response: %w", err)
	}

	log.Info().
		Str("call_id", result.CallID).
		Str("to", req.To).
		Dur("duration_ms", duration).
		Msg("Outbound call initiated successfully")

	return &result, nil
}

// HangupCall terminates an active call
func (c *Client) HangupCall(ctx context.Context, callID string) error {
	url := fmt.Sprintf("%s/api/v1/calls/%s/hangup", c.baseURL, callID)

	httpReq, err := http.NewRequestWithContext(ctx, http.MethodPost, url, nil)
	if err != nil {
		return fmt.Errorf("failed to create request: %w", err)
	}

	httpReq.Header.Set("Authorization", fmt.Sprintf("Bearer %s", c.apiKey))

	resp, err := c.httpClient.Do(httpReq)
	if err != nil {
		return fmt.Errorf("http request failed: %w", err)
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK && resp.StatusCode != http.StatusNoContent {
		body, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("api returned status %d: %s", resp.StatusCode, string(body))
	}

	log.Info().Str("call_id", callID).Msg("Call hung up successfully")
	return nil
}

// GetCallStatus retrieves the current status of a call
func (c *Client) GetCallStatus(ctx context.Context, callID string) (*CallStatus, error) {
	url := fmt.Sprintf("%s/api/v1/calls/%s", c.baseURL, callID)

	httpReq, err := http.NewRequestWithContext(ctx, http.MethodGet, url, nil)
	if err != nil {
		return nil, fmt.Errorf("failed to create request: %w", err)
	}

	httpReq.Header.Set("Authorization", fmt.Sprintf("Bearer %s", c.apiKey))

	resp, err := c.httpClient.Do(httpReq)
	if err != nil {
		return nil, fmt.Errorf("http request failed: %w", err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		return nil, fmt.Errorf("failed to read response body: %w", err)
	}

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("api returned status %d: %s", resp.StatusCode, string(body))
	}

	var result CallStatus
	if err := json.Unmarshal(body, &result); err != nil {
		return nil, fmt.Errorf("failed to unmarshal response: %w", err)
	}

	return &result, nil
}

// OutboundCallRequest represents a request to make an outbound call
type OutboundCallRequest struct {
	From             string                 `json:"from"`
	To               string                 `json:"to"`
	ApplicationID    string                 `json:"application_id"`
	CallbackURL      string                 `json:"callback_url"`
	CustomParameters map[string]interface{} `json:"custom_parameters,omitempty"`
	Timeout          int                    `json:"timeout,omitempty"`
	AMDEnabled       bool                   `json:"amd_enabled,omitempty"`
	AMDTimeout       int                    `json:"amd_timeout,omitempty"`
	AMDIntroTimeout  int                    `json:"amd_intro_timeout,omitempty"`
}

// OutboundCallResponse represents the response from initiating a call
type OutboundCallResponse struct {
	CallID    string `json:"call_id"`
	Status    string `json:"status"`
	SessionID string `json:"session_id,omitempty"`
	From      string `json:"from,omitempty"`
	To        string `json:"to,omitempty"`
	CreatedAt string `json:"created_at,omitempty"`
}

// CallStatus represents the current status of a call
type CallStatus struct {
	CallID    string `json:"call_id"`
	Status    string `json:"status"`
	From      string `json:"from,omitempty"`
	To        string `json:"to,omitempty"`
	Duration  int    `json:"duration,omitempty"`
	StartedAt string `json:"started_at,omitempty"`
	EndedAt   string `json:"ended_at,omitempty"`
}
