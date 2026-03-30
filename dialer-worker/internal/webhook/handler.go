package webhook

import (
	"encoding/json"
	"io"
	"net/http"
	"time"

	"github.com/nirsolutions/opbx-dialer-worker/internal/executor"
	"github.com/nirsolutions/opbx-dialer-worker/pkg/models"
	"github.com/rs/zerolog/log"
)

// Handler processes incoming webhooks from Cloudonix
type Handler struct {
	executor *executor.Executor
}

// NewHandler creates a new webhook handler
func NewHandler(executor *executor.Executor) *Handler {
	return &Handler{
		executor: executor,
	}
}

// RegisterRoutes registers webhook endpoints with the HTTP mux
func (h *Handler) RegisterRoutes(mux *http.ServeMux) {
	mux.HandleFunc("/webhooks/cloudonix", h.handleCloudonixWebhook)
	mux.HandleFunc("/health", h.handleHealth)
}

// handleCloudonixWebhook processes Cloudonix webhook events
func (h *Handler) handleCloudonixWebhook(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodPost {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	// Read body
	body, err := io.ReadAll(r.Body)
	if err != nil {
		log.Error().Err(err).Msg("Failed to read webhook body")
		http.Error(w, "Bad request", http.StatusBadRequest)
		return
	}
	defer r.Body.Close()

	// Parse event
	var event models.CloudonixWebhookEvent
	if err := json.Unmarshal(body, &event); err != nil {
		log.Error().Err(err).Str("body", string(body)).Msg("Failed to parse webhook event")
		http.Error(w, "Bad request", http.StatusBadRequest)
		return
	}

	log.Info().
		Str("event_type", event.EventType).
		Str("call_id", event.CallID).
		Str("session_id", event.SessionID).
		Msg("Received Cloudonix webhook")

	// Process the event
	if err := h.executor.HandleCallEvent(r.Context(), &event); err != nil {
		log.Error().
			Err(err).
			Str("event_type", event.EventType).
			Str("call_id", event.CallID).
			Msg("Failed to process webhook event")

		// Return 500 so Cloudonix will retry
		http.Error(w, "Internal error", http.StatusInternalServerError)
		return
	}

	// Return success
	w.WriteHeader(http.StatusOK)
	json.NewEncoder(w).Encode(map[string]string{
		"status": "processed",
	})
}

// handleHealth returns health status
func (h *Handler) handleHealth(w http.ResponseWriter, r *http.Request) {
	if r.Method != http.MethodGet {
		http.Error(w, "Method not allowed", http.StatusMethodNotAllowed)
		return
	}

	w.WriteHeader(http.StatusOK)
	json.NewEncoder(w).Encode(map[string]interface{}{
		"status":    "healthy",
		"service":   "dialer-worker",
		"timestamp": time.Now().Unix(),
	})
}
