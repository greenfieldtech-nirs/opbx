package webhook

import (
	"context"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"io"
	"net/http"
	"time"

	"github.com/gin-gonic/gin"
	"opbx/dialer-worker/internal/executor"
	"opbx/dialer-worker/internal/models"
)

// Handler handles incoming webhooks from Laravel
type Handler struct {
	executor      *executor.Executor
	webhookSecret string
}

// NewHandler creates a new webhook handler
func NewHandler(exec *executor.Executor, webhookSecret string) *Handler {
	return &Handler{
		executor:      exec,
		webhookSecret: webhookSecret,
	}
}

// RegisterRoutes registers webhook routes with the Gin router
func (h *Handler) RegisterRoutes(router *gin.Engine) {
	webhookGroup := router.Group("/webhooks")
	{
		// CDR webhook from Laravel (relayed from Cloudonix)
		webhookGroup.POST("/cdr", h.handleCDR)

		// Health check
		webhookGroup.GET("/health", h.handleHealth)
	}
}

// handleCDR processes CDR webhooks from Laravel
func (h *Handler) handleCDR(c *gin.Context) {
	// Read body first (must be done before signature verification)
	body, err := io.ReadAll(c.Request.Body)
	if err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "failed to read body"})
		return
	}

	// Verify webhook signature if secret is configured
	if h.webhookSecret != "" {
		if !h.verifySignature(c, body) {
			c.JSON(http.StatusUnauthorized, gin.H{"error": "invalid signature"})
			return
		}
	}

	// Parse CDR event
	var event models.CDREvent
	if err := json.Unmarshal(body, &event); err != nil {
		c.JSON(http.StatusBadRequest, gin.H{"error": "invalid JSON"})
		return
	}

	// Validate required fields
	if event.SessionID == "" {
		c.JSON(http.StatusBadRequest, gin.H{"error": "session_id is required"})
		return
	}

	// Process CDR asynchronously with a new context (not the HTTP request context)
	go func() {
		ctx, cancel := context.WithTimeout(context.Background(), 30*time.Second)
		defer cancel()
		if err := h.executor.HandleCDR(ctx, &event); err != nil {
			// Log error but still return 200 to acknowledge receipt
			// Laravel should handle retries if needed
		}
	}()

	// Return 202 Accepted immediately
	c.JSON(http.StatusAccepted, gin.H{
		"status":     "accepted",
		"session_id": event.SessionID,
	})
}

// handleHealth returns health status
func (h *Handler) handleHealth(c *gin.Context) {
	c.JSON(http.StatusOK, gin.H{
		"status":    "healthy",
		"timestamp": time.Now().UTC(),
	})
}

// verifySignature verifies the webhook signature
func (h *Handler) verifySignature(c *gin.Context, body []byte) bool {
	signature := c.GetHeader("X-Webhook-Signature")
	if signature == "" {
		return false
	}

	mac := hmac.New(sha256.New, []byte(h.webhookSecret))
	mac.Write(body)
	expectedSignature := hex.EncodeToString(mac.Sum(nil))

	return hmac.Equal([]byte(signature), []byte(expectedSignature))
}
