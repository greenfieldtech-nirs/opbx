package models

// No imports needed - FlexTime handles time parsing

// APIResponse represents the standard Laravel API response wrapper
type APIResponse struct {
	Data    interface{} `json:"data"`
	Message string      `json:"message,omitempty"`
	Meta    interface{} `json:"meta,omitempty"`
}

// Campaign represents a dialer campaign from Laravel
type Campaign struct {
	ID                     int64    `json:"id"`
	OrganizationID         int64    `json:"organization_id"`
	Name                   string   `json:"name"`
	Status                 string   `json:"status"`
	CAC                    int      `json:"concurrent_active_calls"`  // Concurrent Active Calls (1-50)
	CPS                    int      `json:"calls_per_second"`         // Calls Per Second (1-5)
	MaxDialAttempts        int      `json:"max_dial_attempts"`        // Maximum retry attempts
	CallerID               string   `json:"caller_id"`                // Outbound caller ID
	DialTimeout            int      `json:"dial_timeout"`             // Ring timeout in seconds
	RoutingDestinationType string   `json:"routing_destination_type"` // ai_assistant, ring_group, etc.
	RoutingDestinationID   int64    `json:"routing_destination_id"`
	StartDate              string   `json:"start_date"`
	EndDate                string   `json:"end_date"`
	PendingCalls           int      `json:"pending_calls"`
	CompletedCalls         int      `json:"completed_calls"`
	FailedCalls            int      `json:"failed_calls"`
	CreatedAt              FlexTime `json:"created_at"`
	UpdatedAt              FlexTime `json:"updated_at"`

	// Caller ID Pool fields (new)
	CallerIDPoolEnabled bool               `json:"caller_id_pool_enabled"`
	CallerIDPool        []CallerIDPoolItem `json:"caller_id_pool"`
	CallerIDStrategy    CallerIDStrategy   `json:"caller_id_strategy"`
}

// CallerIDPoolItem represents a single Caller ID in the pool
type CallerIDPoolItem struct {
	DIDID       int64  `json:"did_id"`
	PhoneNumber string `json:"phone_number"`
	Weight      int    `json:"weight"`
}

// CallerIDStrategy represents the strategy for selecting Caller IDs
type CallerIDStrategy string

const (
	StrategyRoundRobin        CallerIDStrategy = "round_robin"
	StrategyRandom            CallerIDStrategy = "random"
	StrategyLeastRecentlyUsed CallerIDStrategy = "least_recently_used"
)

// Destination represents a phone number to dial
type Destination struct {
	ID              int64    `json:"id"`
	ListID          int64    `json:"list_id"`
	CampaignID      int64    `json:"campaign_id"`
	PhoneNumber     string   `json:"phone_number"`
	Status          string   `json:"status"`        // pending, dialing, completed, failed
	DialAttempts    int      `json:"dial_attempts"` // Number of attempts made
	LastDialedAt    FlexTime `json:"last_dialed_at,omitempty"`
	LastDisposition string   `json:"last_disposition,omitempty"`
	NextRetryAt     FlexTime `json:"next_retry_at,omitempty"`
	Duration        int      `json:"duration"`
	Billsec         int      `json:"billsec"`
	CreatedAt       FlexTime `json:"created_at"`
	UpdatedAt       FlexTime `json:"updated_at"`
}

// CallSession represents an active call session
type CallSession struct {
	ID             int64    `json:"id"`
	SessionToken   string   `json:"session_token"`
	OrganizationID int64    `json:"organization_id"`
	CampaignID     int64    `json:"campaign_id"`
	DestinationID  int64    `json:"destination_id"`
	PhoneNumber    string   `json:"phone_number"`
	WorkerID       string   `json:"worker_id"`
	Status         string   `json:"status"` // initiated, ringing, answered, completed
	Disposition    string   `json:"disposition,omitempty"`
	Duration       int      `json:"duration"`
	Billsec        int      `json:"billsec"`
	RecordingURL   string   `json:"recording_url,omitempty"`
	InitiatedAt    FlexTime `json:"initiated_at,omitempty"`
	CompletedAt    FlexTime `json:"completed_at,omitempty"`
	CreatedAt      FlexTime `json:"created_at"`
	UpdatedAt      FlexTime `json:"updated_at"`
}

// CallStatus represents the status of a call
type CallStatus string

const (
	CallStatusInitiated CallStatus = "initiated"
	CallStatusRinging   CallStatus = "ringing"
	CallStatusAnswered  CallStatus = "answered"
	CallStatusCompleted CallStatus = "completed"
	CallStatusFailed    CallStatus = "failed"
)

// DestinationStatus represents destination status
type DestinationStatus string

const (
	DestinationStatusPending   DestinationStatus = "pending"
	DestinationStatusDialing   DestinationStatus = "dialing"
	DestinationStatusCompleted DestinationStatus = "completed"
	DestinationStatusFailed    DestinationStatus = "failed"
)

// RetryableDispositions are dispositions that should trigger a retry
// Per Laravel: busy, no-answer, cancelled
var RetryableDispositions = map[string]bool{
	"busy":      true,
	"no-answer": true,
	"cancelled": true,
}

// CDREvent represents a Call Detail Record from Cloudonix (via Laravel webhook)
type CDREvent struct {
	SessionID    string   `json:"session_id"`
	Status       string   `json:"status"`      // Laravel's call status
	Disposition  string   `json:"disposition"` // answered, busy, no-answer, failed, cancelled, congestion
	Duration     int      `json:"duration"`
	Billsec      int      `json:"billsec"`
	RecordingURL string   `json:"recording_url,omitempty"`
	CompletedAt  FlexTime `json:"completed_at,omitempty"`
}

// InitiateCallRequest represents a request to initiate a call (matches Laravel expectations)
type InitiateCallRequest struct {
	CampaignID    int64    `json:"campaign_id"`
	DestinationID int64    `json:"destination_id"`
	PhoneNumber   string   `json:"phone_number"`
	WorkerID      string   `json:"worker_id"`
	CallerID      string   `json:"caller_id,omitempty"`     // Selected Caller ID from pool
	CallerDIDID   int64    `json:"caller_did_id,omitempty"` // DID ID of selected Caller ID
	InitiatedAt   FlexTime `json:"initiated_at"`
}

// InitiateCallResponse represents the response from initiating a call
type InitiateCallResponse struct {
	SessionID   int64  `json:"session_id"`   // Internal session ID (Laravel returns as number)
	CallID      int64  `json:"call_id"`      // Cloudonix call ID (Laravel returns as number)
	CallbackURL string `json:"callback_url"` // Webhook URL for Cloudonix
}

// UpdateCallStatusRequest represents a call status update (matches Laravel expectations)
type UpdateCallStatusRequest struct {
	Status       string   `json:"status"`                // initiated, ringing, answered, completed
	Disposition  string   `json:"disposition,omitempty"` // answered, busy, no-answer, failed, etc.
	Duration     int      `json:"duration,omitempty"`
	Billsec      int      `json:"billsec,omitempty"`
	RecordingURL string   `json:"recording_url,omitempty"`
	CompletedAt  FlexTime `json:"completed_at,omitempty"`
}

// SetDispositionRequest represents a disposition update (matches Laravel expectations)
type SetDispositionRequest struct {
	Disposition   string   `json:"disposition"`             // answered, completed, busy, no-answer, failed, cancelled, congestion
	ShouldRetry   bool     `json:"should_retry"`            // Whether to retry this destination
	AttemptNumber int      `json:"attempt_number"`          // Current attempt number
	NextRetryAt   FlexTime `json:"next_retry_at,omitempty"` // When to retry (if should_retry is true)
	Duration      int      `json:"duration,omitempty"`
	Billsec       int      `json:"billsec,omitempty"`
}

// SetDispositionResponse represents the response from setting disposition
type SetDispositionResponse struct {
	SessionID         string `json:"session_id"`
	Disposition       string `json:"disposition"`
	DestinationStatus string `json:"destination_status"`
	WillRetry         bool   `json:"will_retry"`
}

// HealthResponse represents the health check response
type HealthResponse struct {
	Status          string `json:"status"`
	ActiveCampaigns int    `json:"active_campaigns"`
	ActiveCalls     int    `json:"active_calls"`
	QueueDepth      int    `json:"queue_depth"`
}
