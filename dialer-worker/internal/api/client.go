package api

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log/slog"
	"net/http"
	"time"

	"opbx/dialer-worker/internal/config"
	"opbx/dialer-worker/internal/models"
	"opbx/dialer-worker/pkg/errors"
)

// Client handles communication with the Laravel API
type Client struct {
	baseURL    string
	apiToken   string
	httpClient *http.Client
	logger     *slog.Logger
}

// NewClient creates a new Laravel API client
func NewClient(cfg *config.Config, logger *slog.Logger) *Client {
	return &Client{
		baseURL:  cfg.LaravelAPIURL,
		apiToken: cfg.LaravelAPIToken,
		httpClient: &http.Client{
			Timeout: 30 * time.Second,
			Transport: &http.Transport{
				DisableKeepAlives: true, // Disable connection pooling to avoid DNS caching issues
			},
		},
		logger: logger,
	}
}

// GetActiveCampaigns fetches all active campaigns ready for dialing
func (c *Client) GetActiveCampaigns(ctx context.Context) ([]models.Campaign, error) {
	var response models.APIResponse
	err := c.get(ctx, "/api/v1/dialer/worker/campaigns/active", &response)
	if err != nil {
		return nil, fmt.Errorf("failed to get active campaigns: %w", err)
	}

	// Debug: log the data type
	c.logger.Info("GetActiveCampaigns response", "data_type", fmt.Sprintf("%T", response.Data))

	// Extract campaigns from data wrapper
	campaignsData, err := json.Marshal(response.Data)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal campaigns data: %w", err)
	}

	var campaigns []models.Campaign
	if err := json.Unmarshal(campaignsData, &campaigns); err != nil {
		return nil, fmt.Errorf("failed to unmarshal campaigns: %w", err)
	}

	c.logger.Info("GetActiveCampaigns result", "count", len(campaigns))
	for i, camp := range campaigns {
		c.logger.Info("Campaign details", "index", i, "id", camp.ID, "name", camp.Name, "status", camp.Status, "cac", camp.CAC)
	}

	return campaigns, nil
}

// GetPendingDestinations fetches pending destinations for a campaign
func (c *Client) GetPendingDestinations(ctx context.Context, campaignID int64, limit int) ([]models.Destination, error) {
	var response models.APIResponse
	url := fmt.Sprintf("/api/v1/dialer/worker/campaigns/%d/destinations/pending?limit=%d", campaignID, limit)
	err := c.get(ctx, url, &response)
	if err != nil {
		return nil, fmt.Errorf("failed to get pending destinations: %w", err)
	}

	// Extract destinations from data wrapper
	destinationsData, err := json.Marshal(response.Data)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal destinations data: %w", err)
	}

	var destinations []models.Destination
	if err := json.Unmarshal(destinationsData, &destinations); err != nil {
		return nil, fmt.Errorf("failed to unmarshal destinations: %w", err)
	}

	return destinations, nil
}

// InitiateCall creates a new call session via Laravel
func (c *Client) InitiateCall(ctx context.Context, campaignID int64, destinationID int64, phoneNumber string, workerID string) (*models.InitiateCallResponse, error) {
	return c.InitiateCallWithCallerID(ctx, campaignID, destinationID, phoneNumber, workerID, "", 0)
}

// InitiateCallWithCallerID creates a new call session via Laravel with a specific Caller ID
func (c *Client) InitiateCallWithCallerID(ctx context.Context, campaignID int64, destinationID int64, phoneNumber string, workerID string, callerID string, callerDIDID int64) (*models.InitiateCallResponse, error) {
	req := models.InitiateCallRequest{
		CampaignID:    campaignID,
		DestinationID: destinationID,
		PhoneNumber:   phoneNumber,
		WorkerID:      workerID,
		CallerID:      callerID,
		CallerDIDID:   callerDIDID,
		InitiatedAt:   models.FlexTime(time.Now()),
	}

	var response models.APIResponse
	err := c.post(ctx, "/api/v1/dialer/worker/calls/initiate", req, &response)
	if err != nil {
		return nil, fmt.Errorf("failed to initiate call: %w", err)
	}

	// Extract response data
	responseData, err := json.Marshal(response.Data)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal response data: %w", err)
	}

	var callResp models.InitiateCallResponse
	if err := json.Unmarshal(responseData, &callResp); err != nil {
		return nil, fmt.Errorf("failed to unmarshal response: %w", err)
	}

	return &callResp, nil
}

// UpdateCallStatus updates the status of a call session
func (c *Client) UpdateCallStatus(ctx context.Context, sessionID int64, req models.UpdateCallStatusRequest) error {
	url := fmt.Sprintf("/api/v1/dialer/worker/calls/%d/status", sessionID)
	return c.patch(ctx, url, req, nil)
}

// SetCallDisposition sets the disposition for a completed call
func (c *Client) SetCallDisposition(ctx context.Context, sessionID int64, req models.SetDispositionRequest) (*models.SetDispositionResponse, error) {
	url := fmt.Sprintf("/api/v1/dialer/worker/calls/%d/disposition", sessionID)

	var response models.APIResponse
	err := c.post(ctx, url, req, &response)
	if err != nil {
		return nil, fmt.Errorf("failed to set disposition: %w", err)
	}

	// Extract response data
	responseData, err := json.Marshal(response.Data)
	if err != nil {
		return nil, fmt.Errorf("failed to marshal response data: %w", err)
	}

	var dispResp models.SetDispositionResponse
	if err := json.Unmarshal(responseData, &dispResp); err != nil {
		return nil, fmt.Errorf("failed to unmarshal response: %w", err)
	}

	return &dispResp, nil
}

// HTTP helper methods

func (c *Client) get(ctx context.Context, path string, result interface{}) error {
	return c.doRequest(ctx, http.MethodGet, path, nil, result)
}

func (c *Client) post(ctx context.Context, path string, body, result interface{}) error {
	return c.doRequest(ctx, http.MethodPost, path, body, result)
}

func (c *Client) patch(ctx context.Context, path string, body, result interface{}) error {
	return c.doRequest(ctx, http.MethodPatch, path, body, result)
}

func (c *Client) doRequest(ctx context.Context, method, path string, body, result interface{}) error {
	url := c.baseURL + path

	var bodyReader io.Reader
	if body != nil {
		jsonBody, err := json.Marshal(body)
		if err != nil {
			return fmt.Errorf("failed to marshal request body: %w", err)
		}
		bodyReader = bytes.NewReader(jsonBody)
	}

	req, err := http.NewRequestWithContext(ctx, method, url, bodyReader)
	if err != nil {
		return fmt.Errorf("failed to create request: %w", err)
	}

	req.Header.Set("Authorization", "Bearer "+c.apiToken)
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("Accept", "application/json")

	resp, err := c.httpClient.Do(req)
	if err != nil {
		return fmt.Errorf("request failed: %w", err)
	}
	defer resp.Body.Close()

	// Handle rate limiting (HTTP 429)
	if resp.StatusCode == http.StatusTooManyRequests {
		retryAfter := resp.Header.Get("Retry-After")
		duration := 300 * time.Second // Default 5 minutes per spec
		if retryAfter != "" {
			if seconds, err := time.ParseDuration(retryAfter + "s"); err == nil {
				duration = seconds
			}
		}
		return &errors.RetryableError{
			Err:        errors.ErrRateLimitExceeded,
			RetryAfter: duration,
		}
	}

	if resp.StatusCode >= 400 {
		bodyBytes, _ := io.ReadAll(resp.Body)
		return fmt.Errorf("API error (status %d): %s", resp.StatusCode, string(bodyBytes))
	}

	if result != nil {
		if err := json.NewDecoder(resp.Body).Decode(result); err != nil {
			return fmt.Errorf("failed to decode response: %w", err)
		}
	}

	return nil
}
