package cloudonix

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"time"

	"github.com/rs/zerolog/log"
)

// ErrRateLimited is returned when Cloudonix API returns HTTP 429 (Too Many Requests)
var ErrRateLimited = errors.New("rate limited by Cloudonix API")

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

// SetHTTPClient sets a custom HTTP client (useful for testing)
func (c *Client) SetHTTPClient(client *http.Client) {
	c.httpClient = client
}

// MakeOutboundCall initiates an outbound call through Cloudonix
// Uses the endpoint: POST /calls/{domain}/application
// See: https://developers.cloudonix.com/Documentation/apiWorkflow/callControlAndSessionManagement
func (c *Client) MakeOutboundCall(ctx context.Context, req *OutboundCallRequest) (*OutboundCallResponse, error) {
	// Cloudonix API endpoint format: POST /calls/{domain}/application
	url := fmt.Sprintf("%s/calls/%s/application", c.baseURL, c.domain)

	// Build payload according to Cloudonix API spec
	payload := map[string]interface{}{
		"destination": req.To,          // E.164 phone number to dial
		"caller-id":   req.From,        // Caller ID to present
		"timeout":     req.Timeout,     // Seconds to wait for answer (default: 60)
		"callback":    req.CallbackURL, // URL for session status callbacks
	}

	// Optional: Enable call recording
	if req.Record {
		payload["record"] = true
	}

	// Optional: Enable Answering Machine Detection
	// Valid values: "Enable" or "DetectMessageEnd"
	if req.AMDEnabled {
		payload["machineDetection"] = "Enable"
		if req.AMDTimeout > 0 {
			payload["machineDetectionTimeout"] = req.AMDTimeout
		}
	}

	// Optional: Maximum call duration in seconds
	if req.TimeLimit > 0 {
		payload["timeLimit"] = req.TimeLimit
	}

	// Optional: Schedule the call for a future time (ISO-8601 timestamp)
	if req.ScheduleAt != "" {
		payload["schedule"] = req.ScheduleAt
	}

	// Add destination routing - specify ONE of: application, url, or cxml
	// Note: routing_destination_type comes from campaign.routing_destination_type
	switch req.RoutingDestinationType {
	case "ai_assistant", "application":
		// Application ID from Cloudonix voice application configuration
		if req.RoutingDestinationID != 0 {
			payload["application"] = req.RoutingDestinationID
		}
	case "url":
		// URL to CXML application
		if req.RoutingURL != "" {
			payload["url"] = req.RoutingURL
		}
	case "cxml":
		// Inline CXML code
		if req.RoutingCXML != "" {
			payload["cxml"] = req.RoutingCXML
		}
	}

	// Add custom parameters if provided
	if len(req.CustomParameters) > 0 {
		payload["customData"] = req.CustomParameters
	}

	body, err := json.Marshal(payload)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal request: %w", err)
	}

	// Log the full request for debugging
	log.Info().
		Str("url", url).
		Str("method", "POST").
		Str("payload", string(body)).
		Str("routing_type", req.RoutingDestinationType).
		Str("has_cxml", fmt.Sprintf("%t", req.RoutingCXML != "")).
		Str("cxml_preview", func() string {
			if req.RoutingCXML != "" && len(req.RoutingCXML) > 100 {
				return req.RoutingCXML[:100] + "..."
			}
			return req.RoutingCXML
		}()).
		Msg("Cloudonix API request payload")

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

	if resp.StatusCode != http.StatusOK {
		log.Error().
			Int("status_code", resp.StatusCode).
			Str("response", string(respBody)).
			Str("to", req.To).
			Msg("Cloudonix API returned error")

		// Detect rate limiting (HTTP 429) - caller should pause campaign
		if resp.StatusCode == http.StatusTooManyRequests {
			return nil, ErrRateLimited
		}

		return nil, fmt.Errorf("api returned status %d: %s", resp.StatusCode, string(respBody))
	}

	// Parse Cloudonix response
	// Expected: {"domainId": 3, "subscriberId": 372, "destination": "...", "direction": "outbound-api", "token": "..."}
	var cloudonixResp struct {
		DomainID     int    `json:"domainId"`
		SubscriberID int    `json:"subscriberId"`
		Destination  string `json:"destination"`
		Direction    string `json:"direction"`
		Token        string `json:"token"`
	}

	if err := json.Unmarshal(respBody, &cloudonixResp); err != nil {
		return nil, fmt.Errorf("failed to unmarshal response: %w", err)
	}

	// The 'token' field is the session token that will be sent in webhook callbacks as 'call_id'
	result := &OutboundCallResponse{
		CallID:    cloudonixResp.Token,
		Status:    "initiated",
		SessionID: cloudonixResp.Token,
		From:      req.From,
		To:        req.To,
		CreatedAt: time.Now().UTC().Format(time.RFC3339),
	}

	log.Info().
		Str("call_id", result.CallID).
		Str("to", req.To).
		Str("direction", cloudonixResp.Direction).
		Dur("duration_ms", duration).
		Msg("Outbound call initiated successfully")

	return result, nil
}

// HangupCall terminates an active call
// Uses the Cloudonix hangup endpoint
func (c *Client) HangupCall(ctx context.Context, callID string) error {
	// Cloudonix hangup endpoint: DELETE /calls/{domain}/{callId}
	url := fmt.Sprintf("%s/calls/%s/%s", c.baseURL, c.domain, callID)

	httpReq, err := http.NewRequestWithContext(ctx, http.MethodDelete, url, nil)
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
// Uses the Cloudonix session status endpoint
func (c *Client) GetCallStatus(ctx context.Context, callID string) (*CallStatus, error) {
	// Cloudonix status endpoint: GET /calls/{domain}/{callId}
	url := fmt.Sprintf("%s/calls/%s/%s", c.baseURL, c.domain, callID)

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
	To                     string                 `json:"to"`
	From                   string                 `json:"from"`
	CallbackURL            string                 `json:"callback_url"`
	Timeout                int                    `json:"timeout,omitempty"`
	Record                 bool                   `json:"record,omitempty"`
	AMDEnabled             bool                   `json:"amd_enabled,omitempty"`
	AMDTimeout             int                    `json:"amd_timeout,omitempty"`
	TimeLimit              int                    `json:"time_limit,omitempty"`
	ScheduleAt             string                 `json:"schedule_at,omitempty"`
	RoutingDestinationType string                 `json:"routing_destination_type,omitempty"` // ai_assistant, url, cxml
	RoutingDestinationID   int64                  `json:"routing_destination_id,omitempty"`
	RoutingURL             string                 `json:"routing_url,omitempty"`
	RoutingCXML            string                 `json:"routing_cxml,omitempty"`
	CustomParameters       map[string]interface{} `json:"custom_parameters,omitempty"`
}

// OutboundCallResponse represents the response from initiating a call
// The CallID is the Cloudonix session token that will be sent in webhook callbacks
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
	Direction string `json:"direction,omitempty"`
	From      string `json:"from,omitempty"`
	To        string `json:"to,omitempty"`
	Duration  int    `json:"duration,omitempty"`
	StartedAt string `json:"started_at,omitempty"`
	EndedAt   string `json:"ended_at,omitempty"`
}
