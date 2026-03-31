package models

import "time"

// Campaign represents an auto-dialer campaign
type Campaign struct {
	ID                     int64                  `json:"id"`
	Name                   string                 `json:"name"`
	OrganizationID         int64                  `json:"organization_id"`
	AIAgentID              int64                  `json:"ai_agent_id"`
	ApplicationID          string                 `json:"application_id"`
	FromNumber             string                 `json:"from_number"`
	CallerID               string                 `json:"caller_id,omitempty"` // Caller ID to present
	IsRunning              bool                   `json:"is_running"`
	Status                 string                 `json:"status,omitempty"`     // active, paused, completed
	Timezone               string                 `json:"timezone"`             // e.g., "America/New_York"
	StartDate              string                 `json:"start_date,omitempty"` // YYYY-MM-DD format
	EndDate                string                 `json:"end_date,omitempty"`   // YYYY-MM-DD format
	Schedule               map[string]DaySchedule `json:"schedule"`
	MaxConcurrent          int                    `json:"max_concurrent_calls"`
	CallsPerSecond         int                    `json:"calls_per_second,omitempty"` // Rate limit per second
	DefaultTimeout         int                    `json:"default_timeout_seconds"`
	MaxDialAttempts        int                    `json:"max_dial_attempts,omitempty"`
	RecordCalls            bool                   `json:"record_calls,omitempty"`
	AMDEnabled             bool                   `json:"amd_enabled"`
	AMDTimeout             int                    `json:"amd_timeout_ms,omitempty"`
	AMDIntroTimeout        int                    `json:"amd_intro_timeout_ms,omitempty"`
	RoutingDestinationType string                 `json:"routing_destination_type,omitempty"` // ai_assistant, url, cxml
	RoutingDestinationID   int64                  `json:"routing_destination_id,omitempty"`
	CustomParameters       map[string]interface{} `json:"custom_parameters,omitempty"`

	// Cloudonix credentials - provided by Laravel backend per organization
	CloudonixAPIKey string `json:"cloudonix_api_key"`           // Organization's Cloudonix API key
	CloudonixDomain string `json:"cloudonix_domain"`            // Organization's Cloudonix domain
	CloudonixAPIURL string `json:"cloudonix_api_url,omitempty"` // Optional custom API URL
}

// DaySchedule represents a day's schedule
type DaySchedule struct {
	Enabled    bool        `json:"enabled"`
	TimeRanges []TimeRange `json:"time_ranges"`
}

// TimeRange represents a time range
type TimeRange struct {
	Start string `json:"start"` // HH:MM format
	End   string `json:"end"`   // HH:MM format
}

// Destination represents a call destination
type Destination struct {
	ID          int64                  `json:"id"`
	CampaignID  int64                  `json:"campaign_id"`
	PhoneNumber string                 `json:"phone_number"`
	ContactName string                 `json:"contact_name,omitempty"`
	ContactData map[string]interface{} `json:"contact_data,omitempty"`
	Priority    int                    `json:"priority"`
	Status      string                 `json:"status"`
	RetryCount  int                    `json:"retry_count"`
	NextRetryAt *time.Time             `json:"next_retry_at,omitempty"`
}

// CallSession represents an active call session
type CallSession struct {
	ID              int64                  `json:"id"`
	SessionID       string                 `json:"session_id"`
	CampaignID      int64                  `json:"campaign_id"`
	DestinationID   int64                  `json:"destination_id"`
	CallID          string                 `json:"call_id,omitempty"`
	Status          string                 `json:"status"`
	FromNumber      string                 `json:"from_number"`
	ToNumber        string                 `json:"to_number"`
	StartedAt       *time.Time             `json:"started_at,omitempty"`
	EndedAt         *time.Time             `json:"ended_at,omitempty"`
	DurationSeconds int                    `json:"duration_seconds,omitempty"`
	Error           string                 `json:"error,omitempty"`
	AMDResult       *AMDResult             `json:"amd_result,omitempty"`
	CustomParams    map[string]interface{} `json:"custom_parameters,omitempty"`
}

// InitiateCallRequest represents a request to initiate a call
type InitiateCallRequest struct {
	DestinationID    int64                  `json:"destination_id"`
	CampaignID       int64                  `json:"campaign_id"`
	OrganizationID   int64                  `json:"organization_id"`
	AIAgentID        int64                  `json:"ai_agent_id"`
	FromNumber       string                 `json:"from_number"`
	ToNumber         string                 `json:"to_number"`
	CustomParameters map[string]interface{} `json:"custom_parameters,omitempty"`
	AMDEnabled       bool                   `json:"amd_enabled"`
	AMDTimeout       int                    `json:"amd_timeout,omitempty"`
	AMDIntroTimeout  int                    `json:"amd_intro_timeout,omitempty"`
}

// InitiateCallResponse represents the response from initiating a call
type InitiateCallResponse struct {
	SessionID   string `json:"session_id"`
	CallbackURL string `json:"callback_url"`
}

// UpdateCallStatusRequest represents a request to update call status
type UpdateCallStatusRequest struct {
	Status    string     `json:"status"`
	Error     string     `json:"error,omitempty"`
	AMDResult *AMDResult `json:"amd_result,omitempty"`
}

// DispositionRequest represents a request to set call disposition
type DispositionRequest struct {
	SessionID       string     `json:"session_id"`
	Status          string     `json:"status"`
	DurationSeconds int        `json:"duration_seconds,omitempty"`
	AMDResult       *AMDResult `json:"amd_result,omitempty"`
	RecordingURL    string     `json:"recording_url,omitempty"`
	Transcript      string     `json:"transcript,omitempty"`
}

// AMDResult represents the result of AMD detection
type AMDResult struct {
	Result       string  `json:"result"` // "human", "machine", "unknown"
	Confidence   float64 `json:"confidence,omitempty"`
	BeepDetected bool    `json:"beep_detected,omitempty"`
}

// CloudonixWebhookEvent represents a webhook event from Cloudonix
type CloudonixWebhookEvent struct {
	EventType    string    `json:"event_type"`
	CallID       string    `json:"call_id"`
	SessionID    string    `json:"session_id,omitempty"`
	Status       string    `json:"status,omitempty"`
	Error        string    `json:"error,omitempty"`
	Duration     int       `json:"duration,omitempty"`
	AMDResult    AMDResult `json:"amd_result,omitempty"`
	RecordingURL string    `json:"recording_url,omitempty"`
	Transcript   string    `json:"transcript,omitempty"`
}

// RetryState represents the state of a retry attempt
type RetryState struct {
	DestinationID int64     `json:"destination_id"`
	Attempt       int       `json:"attempt"`
	NextRetryAt   time.Time `json:"next_retry_at"`
	Reason        string    `json:"reason"`
}

// CampaignState represents the state of an active campaign
type CampaignState struct {
	CampaignID        int64                  `json:"campaign_id"`
	ActiveCallCount   int                    `json:"active_call_count"`
	LastDestinationID int64                  `json:"last_destination_id"`
	StartedAt         time.Time              `json:"started_at"`
	CustomState       map[string]interface{} `json:"custom_state,omitempty"`
}

// WorkerState represents the full state of a worker
type WorkerState struct {
	WorkerID        string                   `json:"worker_id"`
	ActiveCampaigns map[int64]*CampaignState `json:"active_campaigns"`
	RetryQueueState map[string]*RetryState   `json:"retry_queue_state"`
	LastProcessedAt time.Time                `json:"last_processed_at,omitempty"`
	UpdatedAt       time.Time                `json:"updated_at"`
}

// PersistedState represents state to be persisted
type PersistedState struct {
	WorkerID  string      `json:"worker_id"`
	State     WorkerState `json:"state"`
	Timestamp time.Time   `json:"timestamp"`
}
