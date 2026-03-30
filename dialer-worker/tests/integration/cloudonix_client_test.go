package integration

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/nirsolutions/opbx-dialer-worker/internal/cloudonix"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// MockCloudonixServer creates a mock Cloudonix API server for testing
func MockCloudonixServer(t *testing.T) *httptest.Server {
	mux := http.NewServeMux()

	// POST /calls/{domain}/application - Initiate outbound call
	mux.HandleFunc("/calls/", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodPost {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		// Verify authorization header
		auth := r.Header.Get("Authorization")
		if auth != "Bearer test-cloudonix-key" {
			http.Error(w, "Unauthorized", http.StatusUnauthorized)
			return
		}

		// Parse request body
		var req map[string]interface{}
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			http.Error(w, "Invalid JSON", http.StatusBadRequest)
			return
		}

		// Validate required fields
		destination, ok := req["destination"].(string)
		if !ok || destination == "" {
			http.Error(w, "Missing destination", http.StatusBadRequest)
			return
		}

		// Return Cloudonix response format
		response := map[string]interface{}{
			"domainId":     3,
			"subscriberId": 372,
			"destination":  destination,
			"direction":    "outbound-api",
			"token":        "16a7294c989b11e7b3d32b9edb8660c7",
		}

		w.Header().Set("Content-Type", "application/json")
		w.WriteHeader(http.StatusOK)
		json.NewEncoder(w).Encode(response)
	})

	// DELETE /calls/{domain}/{callId} - Hangup call
	mux.HandleFunc("/calls/test-domain/", func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodDelete {
			http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
			return
		}

		auth := r.Header.Get("Authorization")
		if auth != "Bearer test-cloudonix-key" {
			http.Error(w, "Unauthorized", http.StatusUnauthorized)
			return
		}

		w.WriteHeader(http.StatusNoContent)
	})

	return httptest.NewServer(mux)
}

func TestCloudonixClient_MakeOutboundCall(t *testing.T) {
	server := MockCloudonixServer(t)
	defer server.Close()

	client := cloudonix.NewClient(server.URL, "test-cloudonix-key", "test-domain")
	client.SetHTTPClient(server.Client())

	ctx := context.Background()
	req := &cloudonix.OutboundCallRequest{
		To:                     "+12025551234",
		From:                   "+1234567890",
		CallbackURL:            "http://localhost:8080/webhooks/cloudonix",
		Timeout:                30,
		Record:                 true,
		AMDEnabled:             true,
		RoutingDestinationType: "ai_assistant",
		RoutingDestinationID:   "5",
		CustomParameters: map[string]interface{}{
			"campaign_id":    1,
			"destination_id": 123,
		},
	}

	resp, err := client.MakeOutboundCall(ctx, req)

	require.NoError(t, err)
	assert.NotEmpty(t, resp.CallID)
	assert.Equal(t, "16a7294c989b11e7b3d32b9edb8660c7", resp.CallID)
	assert.Equal(t, "initiated", resp.Status)
}

func TestCloudonixClient_MakeOutboundCall_MissingDestination(t *testing.T) {
	server := MockCloudonixServer(t)
	defer server.Close()

	client := cloudonix.NewClient(server.URL, "test-cloudonix-key", "test-domain")
	client.SetHTTPClient(server.Client())

	ctx := context.Background()
	req := &cloudonix.OutboundCallRequest{
		From:        "+1234567890",
		CallbackURL: "http://localhost:8080/webhooks/cloudonix",
	}

	_, err := client.MakeOutboundCall(ctx, req)

	assert.Error(t, err)
}

func TestCloudonixClient_HangupCall(t *testing.T) {
	server := MockCloudonixServer(t)
	defer server.Close()

	client := cloudonix.NewClient(server.URL, "test-cloudonix-key", "test-domain")
	client.SetHTTPClient(server.Client())

	ctx := context.Background()
	err := client.HangupCall(ctx, "16a7294c989b11e7b3d32b9edb8660c7")

	require.NoError(t, err)
}

func TestCloudonixClient_AuthenticationFailure(t *testing.T) {
	server := MockCloudonixServer(t)
	defer server.Close()

	// Use wrong API key
	client := cloudonix.NewClient(server.URL, "wrong-key", "test-domain")
	client.SetHTTPClient(server.Client())

	ctx := context.Background()
	req := &cloudonix.OutboundCallRequest{
		To:          "+12025551234",
		From:        "+1234567890",
		CallbackURL: "http://localhost:8080/webhooks/cloudonix",
	}

	_, err := client.MakeOutboundCall(ctx, req)

	assert.Error(t, err)
}
