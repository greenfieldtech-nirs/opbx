package scheduler

import (
	"context"
	"fmt"
	"strings"
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
		Str("timezone", campaign.Timezone).
		Str("start_date", campaign.StartDate).
		Str("end_date", campaign.EndDate).
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
						uint(parseHour(tr.Start)),
						uint(parseMinute(tr.Start)),
						0,
					)),
				),
				gocron.NewTask(s.executeCampaignIfInHours, ctx, campaign),
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
	log.Info().
		Int64("campaign_id", campaign.ID).
		Str("status", campaign.Status).
		Msg("Checking campaign execution eligibility")

	// Check if campaign status is active (the primary control flag)
	if campaign.Status != "active" {
		log.Info().
			Int64("campaign_id", campaign.ID).
			Str("status", campaign.Status).
			Msg("Campaign not active, skipping")
		return
	}

	if !s.isWithinSchedule(campaign) {
		log.Info().
			Int64("campaign_id", campaign.ID).
			Msg("Campaign outside schedule, skipping")
		return
	}

	log.Info().
		Int64("campaign_id", campaign.ID).
		Str("campaign_name", campaign.Name).
		Msg("Executing campaign - within schedule and active")

	s.executor.ExecuteCampaign(ctx, campaign)
}

// isWithinSchedule checks if current time is within campaign schedule
// including date range, timezone, day of week, and time ranges
func (s *Scheduler) isWithinSchedule(campaign *models.Campaign) bool {
	// Load campaign timezone
	loc, err := time.LoadLocation(campaign.Timezone)
	if err != nil {
		log.Error().
			Err(err).
			Str("timezone", campaign.Timezone).
			Int64("campaign_id", campaign.ID).
			Msg("Invalid timezone, using UTC")
		loc = time.UTC
	}

	now := time.Now().In(loc)

	// Check date range (start_date to end_date)
	if campaign.StartDate != "" && campaign.EndDate != "" {
		today := now.Format("2006-01-02")
		if today < campaign.StartDate || today > campaign.EndDate {
			log.Info().
				Int64("campaign_id", campaign.ID).
				Str("today", today).
				Str("start_date", campaign.StartDate).
				Str("end_date", campaign.EndDate).
				Msg("Current date outside campaign date range")
			return false
		}
	}

	// Get day name in lowercase (monday, tuesday, etc.)
	dayName := strings.ToLower(now.Format("Monday"))
	currentTime := now.Format("15:04")

	log.Info().
		Int64("campaign_id", campaign.ID).
		Str("day", dayName).
		Str("current_time", currentTime).
		Int("schedule_days", len(campaign.Schedule)).
		Msg("Checking schedule for day")

	schedule, exists := campaign.Schedule[dayName]
	if !exists {
		log.Info().
			Int64("campaign_id", campaign.ID).
			Str("day", dayName).
			Msg("Day not found in schedule")
		return false
	}
	if !schedule.Enabled {
		log.Info().
			Int64("campaign_id", campaign.ID).
			Str("day", dayName).
			Bool("enabled", schedule.Enabled).
			Msg("Day not enabled in schedule")
		return false
	}

	// Check if current time falls within any time range
	log.Info().
		Int64("campaign_id", campaign.ID).
		Int("time_ranges", len(schedule.TimeRanges)).
		Msg("Checking time ranges")

	for _, tr := range schedule.TimeRanges {
		log.Info().
			Int64("campaign_id", campaign.ID).
			Str("current_time", currentTime).
			Str("range_start", tr.Start).
			Str("range_end", tr.End).
			Msg("Comparing with time range")
		if currentTime >= tr.Start && currentTime <= tr.End {
			log.Info().
				Int64("campaign_id", campaign.ID).
				Str("current_time", currentTime).
				Str("range_start", tr.Start).
				Str("range_end", tr.End).
				Msg("Within schedule time range")
			return true
		}
	}

	log.Info().
		Int64("campaign_id", campaign.ID).
		Str("current_time", currentTime).
		Msg("Current time not within any schedule range")

	return false
}

// Helper functions
func dayToInt(day string) int {
	days := map[string]int{
		"sunday":    0,
		"monday":    1,
		"tuesday":   2,
		"wednesday": 3,
		"thursday":  4,
		"friday":    5,
		"saturday":  6,
	}
	if d, ok := days[strings.ToLower(day)]; ok {
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
