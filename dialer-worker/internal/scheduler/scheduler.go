package scheduler

import (
	"context"
	"fmt"
	"sync"
	"time"

	"github.com/go-co-op/gocron/v2"
	"github.com/nirsolutions/opbx-dialer-worker/internal/api"
	"github.com/nirsolutions/opbx-dialer-worker/pkg/models"
	"github.com/rs/zerolog/log"
)

// Scheduler manages campaign scheduling and execution
type Scheduler struct {
	client          *api.Client
	executor        Executor
	onError         func(error)
	jobs            map[string]gocron.Job
	mu              sync.RWMutex
	scheduler       gocron.Scheduler
	activeCampaigns map[int64]*models.Campaign
}

// Executor defines the interface for call execution
type Executor interface {
	ExecuteCampaign(ctx context.Context, campaign *models.Campaign)
	StopCampaign(campaignID int64)
}

// NewScheduler creates a new campaign scheduler
func NewScheduler(client *api.Client, executor Executor) (*Scheduler, error) {
	s, err := gocron.NewScheduler()
	if err != nil {
		return nil, fmt.Errorf("failed to create scheduler: %w", err)
	}

	return &Scheduler{
		client:          client,
		executor:        executor,
		scheduler:       s,
		jobs:            make(map[string]gocron.Job),
		activeCampaigns: make(map[int64]*models.Campaign),
	}, nil
}

// Start begins the scheduler loop
func (s *Scheduler) Start(ctx context.Context) error {
	log.Info().Msg("Starting campaign scheduler")

	// Start the gocron scheduler
	s.scheduler.Start()

	// Initial load of campaigns
	if err := s.refreshCampaigns(ctx); err != nil {
		log.Error().Err(err).Msg("Failed to load initial campaigns")
	}

	// Schedule periodic refresh every minute
	_, err := s.scheduler.NewJob(
		gocron.DurationJob(time.Minute),
		gocron.NewTask(s.refreshCampaigns, ctx),
		gocron.WithIdentifier("refresh_campaigns"),
	)
	if err != nil {
		return fmt.Errorf("failed to schedule refresh job: %w", err)
	}

	// Wait for context cancellation
	<-ctx.Done()
	return s.Stop()
}

// Stop gracefully shuts down the scheduler
func (s *Scheduler) Stop() error {
	log.Info().Msg("Stopping campaign scheduler")
	return s.scheduler.Shutdown()
}

// refreshCampaigns fetches active campaigns and updates schedules
func (s *Scheduler) refreshCampaigns(ctx context.Context) error {
	campaigns, err := s.client.GetActiveCampaigns(ctx)
	if err != nil {
		return fmt.Errorf("failed to fetch active campaigns: %w", err)
	}

	log.Debug().Int("count", len(campaigns)).Msg("Fetched active campaigns")

	// Track current campaign IDs
	currentIDs := make(map[int64]bool)
	for _, campaign := range campaigns {
		currentIDs[campaign.ID] = true
	}

	// Remove campaigns that are no longer active
	s.mu.Lock()
	for id := range s.activeCampaigns {
		if !currentIDs[id] {
			log.Info().Int64("campaign_id", id).Msg("Removing inactive campaign")
			s.removeCampaignLocked(id)
		}
	}

	// Add or update campaigns
	for _, campaign := range campaigns {
		if _, exists := s.activeCampaigns[campaign.ID]; !exists {
			s.activeCampaigns[campaign.ID] = &campaign
			s.scheduleCampaignLocked(ctx, &campaign)
		}
	}
	s.mu.Unlock()

	return nil
}

// scheduleCampaignLocked schedules a campaign's execution jobs
func (s *Scheduler) scheduleCampaignLocked(ctx context.Context, campaign *models.Campaign) {
	log.Info().
		Int64("campaign_id", campaign.ID).
		Str("campaign_name", campaign.Name).
		Msg("Scheduling campaign")

	// Schedule based on business hours
	for day, schedule := range campaign.Schedule {
		if !schedule.Enabled {
			continue
		}

		for _, tr := range schedule.TimeRanges {
			jobID := fmt.Sprintf("campaign_%d_%s_%s", campaign.ID, day, tr.Start)

			job, err := s.scheduler.NewJob(
				gocron.WeeklyJob(
					1,
					gocron.NewWeekdays(time.Weekday(dayToInt(day))),
					gocron.NewAtTimes(gocron.NewAtTime(
						parseHour(tr.Start),
						parseMinute(tr.Start),
						0,
					)),
				),
				gocron.NewTask(s.executeCampaignIfInHours, ctx, campaign),
				gocron.WithIdentifier(jobID),
			)

			if err != nil {
				log.Error().
					Err(err).
					Int64("campaign_id", campaign.ID).
					Str("day", day).
					Str("start", tr.Start).
					Msg("Failed to schedule job")
				continue
			}

			s.jobs[jobID] = job
		}
	}

	// Check if we should execute immediately
	s.executeCampaignIfInHours(ctx, campaign)
}

// removeCampaignLocked removes a campaign from scheduling
func (s *Scheduler) removeCampaignLocked(campaignID int64) {
	delete(s.activeCampaigns, campaignID)
	s.executor.StopCampaign(campaignID)

	// Remove scheduled jobs for this campaign
	prefix := fmt.Sprintf("campaign_%d_", campaignID)
	for jobID, job := range s.jobs {
		if len(jobID) > len(prefix) && jobID[:len(prefix)] == prefix {
			s.scheduler.RemoveJob(job.ID())
			delete(s.jobs, jobID)
		}
	}
}

// executeCampaignIfInHours checks if campaign is within business hours and executes
func (s *Scheduler) executeCampaignIfInHours(ctx context.Context, campaign *models.Campaign) {
	if !s.isWithinBusinessHours(campaign) {
		log.Debug().
			Int64("campaign_id", campaign.ID).
			Msg("Campaign outside business hours, skipping")
		return
	}

	if !campaign.IsRunning {
		log.Debug().
			Int64("campaign_id", campaign.ID).
			Msg("Campaign not running, skipping")
		return
	}

	s.executor.ExecuteCampaign(ctx, campaign)
}

// isWithinBusinessHours checks if current time is within campaign business hours
func (s *Scheduler) isWithinBusinessHours(campaign *models.Campaign) bool {
	now := time.Now()
	dayName := now.Weekday().String()

	schedule, exists := campaign.Schedule[dayName]
	if !exists || !schedule.Enabled {
		return false
	}

	currentTime := now.Format("15:04")
	for _, tr := range schedule.TimeRanges {
		if currentTime >= tr.Start && currentTime <= tr.End {
			return true
		}
	}

	return false
}

// Helper functions
func dayToInt(day string) int {
	days := map[string]int{
		"Sunday":    0,
		"Monday":    1,
		"Tuesday":   2,
		"Wednesday": 3,
		"Thursday":  4,
		"Friday":    5,
		"Saturday":  6,
	}
	if d, ok := days[day]; ok {
		return d
	}
	return 1 // Default to Monday
}

func parseHour(timeStr string) int {
	var hour, min int
	fmt.Sscanf(timeStr, "%d:%d", &hour, &min)
	return hour
}

func parseMinute(timeStr string) int {
	var hour, min int
	fmt.Sscanf(timeStr, "%d:%d", &hour, &min)
	return min
}
