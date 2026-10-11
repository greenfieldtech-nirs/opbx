package webhook

import (
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/gin-gonic/gin"
)

func TestOnlyHealthRouteIsServed(t *testing.T) {
	gin.SetMode(gin.TestMode)
	r := gin.New()
	RegisterRoutes(r)

	rec := httptest.NewRecorder()
	r.ServeHTTP(rec, httptest.NewRequest(http.MethodGet, "/webhooks/health", nil))
	if rec.Code != http.StatusOK {
		t.Fatalf("health: got %d", rec.Code)
	}

	rec = httptest.NewRecorder()
	r.ServeHTTP(rec, httptest.NewRequest(http.MethodPost, "/webhooks/cdr", nil))
	if rec.Code != http.StatusNotFound {
		t.Fatalf("cdr route must be gone, got %d", rec.Code)
	}
}
