package webhook

import (
	"net/http"
	"time"

	"github.com/gin-gonic/gin"
)

// RegisterRoutes registers the only HTTP route the worker serves: an
// unauthenticated, read-only health check. All call events flow through Laravel.
func RegisterRoutes(router *gin.Engine) {
	router.GET("/webhooks/health", handleHealth)
}

func handleHealth(c *gin.Context) {
	c.JSON(http.StatusOK, gin.H{
		"status":    "healthy",
		"timestamp": time.Now().UTC(),
	})
}
