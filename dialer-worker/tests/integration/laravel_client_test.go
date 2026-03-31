package integration

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/nirsolutions/opbx-dialer-worker/internal/api"
	"github.com/nirsolutions/opbx-dialer-worker/pkg/models"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// MockLaravelServer creates a mock Laravel API server for testing
func MockLaravelServer(t *testing.T) *httptest.Server {
	mux := http.NewServeMux()

	// GET /api/v1/dialer/worker/campaigns/active
	mux.HandleFunc("/api/v1/dialer/worker/campaigns/active", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		// Verify authorization header
		auth := r.Header.Get("Authorization")
		if auth != "Bearer test-token" {
			http.Error(w, "Unauthorized", http.StatusUnauthorized)
			return
		}

		response := map[string]interface{}{
			"data": []models.Campaign{
				{
					ID:                     1,
					Name:                   "Test Campaign",
					OrganizationID:         1,
					AIAgentID:              5,
					ApplicationID:          "app-123",
					FromNumber:             "+1234567890",
					CallerID:               "+1234567890",
					Status:                 "active",
					Timezone:               "America/New_York",
					StartDate:              time.Now().Format("2006-01-02"),
					EndDate:                time.Now().AddDate(0, 1, 0).Format("2006-01-02"),
					MaxConcurrent:          10,
					CallsPerSecond:         2,
					DefaultTimeout:         30,
					MaxDialAttempts:        3,
					RecordCalls:            true,
					AMDEnabled:             true,
					AMDTimeout:             5000,
					RoutingDestinationType: "ai_assistant",
					Schedule: map[string]models.DaySchedule{
						"monday": {
							Enabled: true,
							TimeRanges: []models.TimeRange{
								{Start: "09:00", End: "17:00"},
							},
						},
						"tuesday": {
							Enabled: true,
							TimeRanges: []models.TimeRange{
								{Start: "09:00", End: "17:00"},
							},
						},
					},
				},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	})

	// GET /api/v1/dialer/worker/campaigns/{id}/destinations/pending
	mux.HandleFunc("/api/v1/dialer/worker/campaigns/", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		// Parse URL to extract campaign ID and path
		// Format: /api/v1/dialer/worker/campaigns/{id}/destinations/pending
		response := map[string]interface{}{
			"data": []models.Destination{
				{
					ID:          1,
					CampaignID:  1,
					PhoneNumber: "+12025551234",
					ContactName: "John Doe",
					Status:      "pending",
					Priority:    1,
					RetryCount:  0,
				},
				{
					ID:          2,
					CampaignID:  1,
					PhoneNumber: "+12025555678",
					ContactName: "Jane Smith",
					Status:      "pending",
					Priority:    2,
					RetryCount:  0,
				},
			},
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	})

	// POST /api/v1/dialer/worker/calls/initiate
	mux.HandleFunc("/api/v1/dialer/worker/calls/initiate", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		var req models.InitiateCallRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			http.Error(w, "Invalid JSON", http.StatusBadRequest)
			return
		}

		response := map[string]interface{}{
			"data": models.InitiateCallResponse{
				SessionID:   123,
				CallbackURL: "http://localhost:8080/webhooks/cloudonix",
			},
		}

		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusCreated)
		json.NewEncoder(w).Encode(response)
	})

	// PATCH /api/v1/dialer/worker/calls/{session}/status
	mux.HandleFunc("/api/v1/dialer/worker/calls/", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPatch {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]string{"status": "updated"})
	})

	// POST /api/v1/dialer/worker/campaigns/{id}/pause
	mux.HandleFunc("/api/v1/dialer/worker/campaigns/", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(map[string]string{"status": "paused"})
	})

	// GET /api/v1/dialer/worker/health
	mux.HandleFunc("/api/v1/dialer/worker/health", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		response := map[string]interface{}{
			"status":           "healthy",
			"active_campaigns": 1,
			"active_calls":     0,
			"queue_depth":      0,
		}

		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(response)
	})

	return httptest.NewServer(mux)
}

func TestLaravelAPIClient_GetActiveCampaigns(t *testing.T) {
	server := MockLaravelServer(t)
	defer server.Close()

	client := api.NewClient(server.URL, "test-token")
	client.SetHTTPClient(server.Client())

	ctx := context.Background()
	campaigns, err := client.GetActiveCampaigns(ctx)

	require.NoError(t, err)
	assert.Len(t, campaigns, 1)
	assert.Equal(t, int64(1), campaigns[0].ID)
	assert.Equal(t, "Test Campaign", campaigns[0].Name)
	assert.Equal(t, "America/New_York", campaigns[0].Timezone)
}

func TestLaravelAPIClient_GetPendingDestinations(t *testing.T) {
	server := MockLaravelServer(t)
	defer server.Close()

	client := api.NewClient(server.URL, "test-token")
	client.SetHTTPClient(server.Client())

	ctx := context.Background()
	destinations, err := client.GetPendingDestinations(ctx, 1, 10)

	require.NoError(t, err)
	assert.Len(t, destinations, 2)
	assert.Equal(t, "+12025551234", destinations[0].PhoneNumber)
}

func TestLaravelAPIClient_InitiateCallSession(t *testing.T) {
	server := MockLaravelServer(t)
	defer server.Close()

	client := api.NewClient(server.URL, "test-token")
	client.SetHTTPClient(server.Client())

	ctx := context.Background()
	req := &models.InitiateCallRequest{
		CampaignID:    1,
		DestinationID: 1,
		PhoneNumber:   "+12025551234",
		WorkerID:      "test-worker-1",
		InitiatedAt:   time.Now().UTC(),
	}

	resp, err := client.InitiateCallSession(ctx, 1, req)

	require.NoError(t, err)
	assert.Equal(t, int64(123), resp.SessionID)
	assert.NotEmpty(t, resp.CallbackURL)
}

func TestLaravelAPIClient_Health(t *testing.T) {
	server := MockLaravelServer(t)
	defer server.Close()

	client := api.NewClient(server.URL, "test-token")
	client.SetHTTPClient(server.Client())

	ctx := context.Background()
	err := client.Health(ctx)

	require.NoError(t, err)
}

func TestLaravelAPIClient_AuthenticationFailure(t *testing.T) {
	server := MockLaravelServer(t)
	defer server.Close()

	// Use wrong token
	client := api.NewClient(server.URL, "wrong-token")
	client.SetHTTPClient(server.Client())

	ctx := context.Background()
	_, err := client.GetActiveCampaigns(ctx)

	assert.Error(t, err)
}
