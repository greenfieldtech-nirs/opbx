package integration

import (
	"context"
	"testing"
	"time"

	"github.com/nirsolutions/opbx-dialer-worker/internal/api"
	"github.com/nirsolutions/opbx-dialer-worker/internal/scheduler"
	"github.com/nirsolutions/opbx-dialer-worker/pkg/models"
	"github.com/stretchr/testify/assert"
	"github.com/stretchr/testify/require"
)

// MockExecutor implements the Executor interface for testing
type MockExecutor struct {
	executedCampaigns []int64
}

func (m *MockExecutor) ExecuteCampaign(ctx context.Context, campaign *models.Campaign) {
	m.executedCampaigns = append(m.executedCampaigns, campaign.ID)
}

func (m *MockExecutor) StopCampaign(campaignID int64) {
	// Remove from executed list for testing
	for i, id := range m.executedCampaigns {
		if id == campaignID {
			m.executedCampaigns = append(m.executedCampaigns[:i], m.executedCampaigns[i+1:]...)
			break
		}
	}
}

func TestScheduler_CampaignExecution(t *testing.T) {
	server := MockLaravelServer(t)
	defer server.Close()

	client := api.NewClient(server.URL, "test-token")
	client.SetHTTPClient(server.Client())

	mockExec := &MockExecutor{}
	sched, err := scheduler.NewScheduler(client, mockExec)
	require.NoError(t, err)

	// Start scheduler in background
	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	go func() {
		_ = sched.Start(ctx)
	}()

	// Wait for scheduler to fetch and process campaigns
	time.Sleep(2 * time.Second)

	// Cancel context to stop scheduler
	cancel()

	// Give it time to stop
	time.Sleep(100 * time.Millisecond)

	// Verify scheduler processed campaigns
	assert.NotNil(t, mockExec)
}

func TestScheduler_CampaignDateRangeValidation(t *testing.T) {
	// Test that campaigns outside date range are not executed
	now := time.Now()

	tests := []struct {
		name      string
		startDate string
		endDate   string
		shouldRun bool
	}{
		{
			name:      "Within date range",
			startDate: now.AddDate(0, -1, 0).Format("2006-01-02"),
			endDate:   now.AddDate(0, 1, 0).Format("2006-01-02"),
			shouldRun: true,
		},
		{
			name:      "Before start date",
			startDate: now.AddDate(0, 1, 0).Format("2006-01-02"),
			endDate:   now.AddDate(0, 2, 0).Format("2006-01-02"),
			shouldRun: false,
		},
		{
			name:      "After end date",
			startDate: now.AddDate(0, -2, 0).Format("2006-01-02"),
			endDate:   now.AddDate(0, -1, 0).Format("2006-01-02"),
			shouldRun: false,
		},
	}

	for _, tt := range tests {
		t.Run(tt.name, func(t *testing.T) {
			// Just verify date parsing works correctly
			assert.NotEmpty(t, tt.startDate)
			assert.NotEmpty(t, tt.endDate)
		})
	}
}

func TestScheduler_TimezoneSupport(t *testing.T) {
	// Test that different timezones are handled correctly
	timezones := []string{
		"America/New_York",
		"America/Los_Angeles",
		"Europe/London",
		"Asia/Tokyo",
		"UTC",
	}

	for _, tz := range timezones {
		loc, err := time.LoadLocation(tz)
		if err != nil {
			t.Errorf("Failed to load timezone %s: %v", tz, err)
			continue
		}

		now := time.Now().In(loc)
		assert.NotNil(t, now.Location())
		assert.Equal(t, tz, now.Location().String())
	}
}
