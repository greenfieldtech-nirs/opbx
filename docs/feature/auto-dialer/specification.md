# Auto Dialer Feature Specification

## Overview

The Auto Dialer is an automated outbound calling system that integrates with the Cloudonix platform to initiate calls and connect them to AI Assistant or AI Load Balancer destinations. It supports campaign management, destination lists, answering machine detection, and comprehensive call monitoring.

## Architecture Principles

1. **Reuse Existing Components**: Leverage existing IVR routing strategies, AI Load Balancers, and Cloudonix integration patterns
2. **Single Campaign Execution**: Only one campaign runs at a time per organization
3. **Immutable Lists**: Destination lists are uploaded via CSV and cannot be modified
4. **Strict Tenant Isolation**: No cross-organization visibility
5. **Comprehensive Audit Trail**: All operations logged with dedicated action types

## Core Components

### 1. Campaign Management

A campaign represents a fixed configuration applied to all called destinations.

#### Database Schema

**auto_dialer_campaigns table:**
```sql
CREATE TABLE auto_dialer_campaigns (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    
    -- Basic Info
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    status ENUM('draft', 'active', 'paused', 'completed', 'archived') DEFAULT 'draft',
    auto_start BOOLEAN DEFAULT FALSE,
    
    -- Routing Configuration
    routing_destination_type ENUM('ai_assistant', 'ai_load_balancer', 'hangup') NOT NULL,
    routing_destination_id BIGINT UNSIGNED NULL, -- References ai_assistants or ai_load_balancers
    
    -- Cloudonix API Parameters
    dial_timeout INT DEFAULT 60, -- seconds (1-300)
    destination_connect ENUM('connected', 'immediately') DEFAULT 'connected',
    caller_id VARCHAR(50) NOT NULL, -- Selected from DID numbers
    
    -- Dialing Guidelines
    max_dial_attempts INT DEFAULT 1, -- (1-5)
    calls_per_second INT DEFAULT 1, -- (1-5)
    
    -- Scheduling
    days_active JSON NOT NULL, -- ['monday', 'tuesday', ...]
    start_time INT NOT NULL, -- 0-23 (hour of day)
    end_time INT NOT NULL, -- 0-23 (must be > start_time)
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    timezone VARCHAR(50) DEFAULT 'UTC',
    
    -- Optional Parameters
    time_limit INT DEFAULT 3600, -- seconds (30-14400)
    record_calls BOOLEAN DEFAULT FALSE,
    
    -- Answering Machine Detection
    amd_enabled BOOLEAN DEFAULT FALSE,
    amd_mode ENUM('Enabled', 'DetectMessageEnd') NULL,
    amd_timeout INT DEFAULT 30, -- seconds (5-120)
    amd_speech_threshold INT DEFAULT 1500, -- milliseconds (500-5000)
    amd_speech_end_threshold INT DEFAULT 2500, -- milliseconds (500-5000)
    amd_silence_timeout INT DEFAULT 3500, -- milliseconds (500-10000)
    
    -- Statistics (cached)
    total_destinations INT DEFAULT 0,
    completed_calls INT DEFAULT 0,
    failed_calls INT DEFAULT 0,
    pending_calls INT DEFAULT 0,
    
    -- Timestamps
    started_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_organization_status (organization_id, status),
    INDEX idx_auto_start (organization_id, auto_start, status),
    INDEX idx_dates (start_date, end_date),
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE
);
```

**auto_dialer_lists table:**
```sql
CREATE TABLE auto_dialer_lists (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    
    name VARCHAR(255) NOT NULL,
    status ENUM('pending', 'processing', 'ready', 'failed') DEFAULT 'pending',
    
    -- Upload tracking
    original_filename VARCHAR(255) NULL,
    processed_at TIMESTAMP NULL,
    total_rows INT DEFAULT 0,
    valid_rows INT DEFAULT 0,
    invalid_rows INT DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES auto_dialer_campaigns(id) ON DELETE CASCADE,
    UNIQUE KEY unique_campaign_list (campaign_id)
);
```

**auto_dialer_destinations table:**
```sql
CREATE TABLE auto_dialer_destinations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    list_id BIGINT UNSIGNED NOT NULL,
    
    -- Destination Info
    phone_number VARCHAR(50) NOT NULL, -- E.164 format
    description VARCHAR(255) NULL,
    
    -- Status Tracking
    status ENUM('pending', 'dialing', 'connected', 'failed', 'completed', 'invalid') DEFAULT 'pending',
    dial_attempts INT DEFAULT 0,
    
    -- Cloudonix Session Tracking
    last_session_token VARCHAR(255) NULL,
    last_call_id VARCHAR(255) NULL,
    
    -- CDR Data (denormalized for performance)
    last_dialed_at TIMESTAMP NULL,
    last_disposition VARCHAR(50) NULL,
    duration INT DEFAULT 0, -- seconds
    billsec INT DEFAULT 0, -- seconds
    
    -- Foreign key to CDR (optional, for deep linking)
    last_cdr_id BIGINT UNSIGNED NULL,
    
    -- Error tracking
    last_error VARCHAR(500) NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    -- Indexes
    INDEX idx_list_status (list_id, status),
    INDEX idx_phone_number (organization_id, phone_number),
    INDEX idx_session_token (last_session_token),
    
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (list_id) REFERENCES auto_dialer_lists(id) ON DELETE CASCADE
);
```

**auto_dialer_call_sessions table:**
```sql
CREATE TABLE auto_dialer_call_sessions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_id BIGINT UNSIGNED NOT NULL,
    campaign_id BIGINT UNSIGNED NOT NULL,
    destination_id BIGINT UNSIGNED NOT NULL,
    
    -- Cloudonix Session Data
    session_token VARCHAR(255) NOT NULL,
    call_id VARCHAR(255) NULL,
    
    -- Call State
    status ENUM('initiated', 'ringing', 'answered', 'completed', 'failed') DEFAULT 'initiated',
    
    -- Timestamps
    initiated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    answered_at TIMESTAMP NULL,
    completed_at TIMESTAMP NULL,
    
    -- AMD Results
    amd_result ENUM('human', 'machine', 'unknown') NULL,
    amd_confidence DECIMAL(5,2) NULL,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_session_token (session_token),
    INDEX idx_campaign_status (campaign_id, status),
    INDEX idx_destination (destination_id),
    
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    FOREIGN KEY (campaign_id) REFERENCES auto_dialer_campaigns(id) ON DELETE CASCADE,
    FOREIGN KEY (destination_id) REFERENCES auto_dialer_destinations(id) ON DELETE CASCADE
);
```

### 2. Campaign States

```
DRAFT → ACTIVE → PAUSED → ACTIVE → COMPLETED
  ↓       ↓        ↓         ↓
ARCHIVED  COMPLETED ARCHIVED  COMPLETED
```

**State Definitions:**
- **DRAFT**: Campaign created, list not uploaded or campaign not ready
- **ACTIVE**: Campaign is running and dialing numbers
- **PAUSED**: Campaign temporarily stopped (manual action)
- **COMPLETED**: All destinations processed or end date reached
- **ARCHIVED**: Campaign hidden from main list (soft delete)

### 3. Destination States

```
PENDING → DIALING → CONNECTED → COMPLETED
   ↓         ↓           ↓
INVALID   FAILED      (AMD results)
```

**State Definitions:**
- **PENDING**: Ready to be dialed
- **DIALING**: Call initiated, waiting for answer
- **CONNECTED**: Call answered, connected to destination
- **COMPLETED**: Call finished successfully
- **FAILED**: Call failed (busy, no answer, error)
- **INVALID**: Number failed whitelist validation

### 4. Cloudonix API Integration

#### New Method in CloudonixClient

Add to `/app/Services/CloudonixClient/CloudonixClient.php`:

```php
/**
 * Initiate an outbound call
 *
 * @param string $from Caller ID (E.164)
 * @param string $to Destination number (E.164)
 * @param string $trunk Outbound trunk name
 * @param array $options Additional options
 * @return array|null
 */
public function initiateCall(
    string $from, 
    string $to, 
    string $trunk,
    array $options = []
): ?array {
    $payload = [
        'from' => $from,
        'to' => $to,
        'trunk' => $trunk,
    ];
    
    // Optional parameters
    if (isset($options['timeout'])) {
        $payload['timeout'] = $options['timeout'];
    }
    
    if (isset($options['execute'])) {
        $payload['execute'] = $options['execute'];
    }
    
    if (isset($options['timeLimit'])) {
        $payload['timeLimit'] = $options['timeLimit'];
    }
    
    if (isset($options['recording'])) {
        $payload['recording'] = $options['recording'];
        $payload['recordingStatusCallback'] = $options['recordingStatusCallback'];
        $payload['recordingStatusCallbackEvent'] = $options['recordingStatusCallbackEvent'] ?? 'completed';
        $payload['trim'] = $options['trim'] ?? 'do-not-trim';
    }
    
    // Answering Machine Detection
    if (isset($options['machineDetection'])) {
        $payload['machineDetection'] = $options['machineDetection'];
        $payload['machineDetectionTimeout'] = $options['machineDetectionTimeout'] ?? 30;
        $payload['machineDetectionSpeechThreshold'] = $options['machineDetectionSpeechThreshold'] ?? 1500;
        $payload['machineDetectionSpeechEndThreshold'] = $options['machineDetectionSpeechEndThreshold'] ?? 2500;
        $payload['machineDetectionSilenceTimeout'] = $options['machineDetectionSilenceTimeout'] ?? 3500;
    }
    
    return $this->request('POST', '/calls', $payload);
}
```

### 5. Routing to AI Destinations

When a call is answered, Cloudonix will make a webhook request to our application. The Auto Dialer controller will handle this and route to the appropriate AI destination.

#### CXML Response for Auto Dialer

Create `/app/Services/CxmlBuilder/AutoDialerCxmlBuilder.php`:

```php
<?php

declare(strict_types=1);

namespace App\Services\CxmlBuilder;

use App\Models\AutoDialerCampaign;

class AutoDialerCxmlBuilder
{
    /**
     * Build CXML for connecting to AI Assistant
     */
    public static function connectToAiAssistant(
        AutoDialerCampaign $campaign,
        array $aiAssistantConfig
    ): string {
        $cxml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><Response/>');
        
        // Add AMD detection if enabled
        if ($campaign->amd_enabled) {
            $amd = $cxml->addChild('amd');
            $amd->addAttribute('timeout', (string) $campaign->amd_timeout);
            $amd->addAttribute('speechThreshold', (string) $campaign->amd_speech_threshold);
            $amd->addAttribute('speechEndThreshold', (string) $campaign->amd_speech_end_threshold);
            $amd->addAttribute('silenceTimeout', (string) $campaign->amd_silence_timeout);
        }
        
        // Connect to AI
        $connect = $cxml->addChild('connect');
        $connect->addAttribute('action', $aiAssistantConfig['action_url']);
        
        // Add stream or dial based on AI configuration
        if ($aiAssistantConfig['protocol'] === 'websocket') {
            $stream = $connect->addChild('stream');
            $stream->addAttribute('url', $aiAssistantConfig['websocket_url']);
        } else {
            $dial = $connect->addChild('dial');
            $dial->addAttribute('target', $aiAssistantConfig['sip_uri']);
        }
        
        return $cxml->asXML();
    }
    
    /**
     * Build CXML for connecting to AI Load Balancer
     */
    public static function connectToAiLoadBalancer(
        AutoDialerCampaign $campaign,
        array $loadBalancerConfig
    ): string {
        // Similar to AI Assistant but with load balancer logic
        // Uses AiLoadBalancerRoutingStrategy internally
    }
}
```

### 6. Webhook Endpoints

#### Call Status Webhook

Route: `POST /webhooks/auto-dialer/call-status`

Handles:
- Call initiated
- Call ringing
- Call answered
- Call completed

#### CDR Webhook Enhancement

Modify existing CDR webhook to handle Auto Dialer calls:

In `/app/Http/Controllers/Webhooks/CloudonixWebhookController.php`:

Add detection of Auto Dialer calls and update `auto_dialer_destinations` table.

### 7. Background Job Processing

#### Campaign Worker Job

Create `/app/Jobs/ProcessAutoDialerCampaignJob.php`:

```php
<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AutoDialerCampaign;
use App\Services\AutoDialer\CampaignProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessAutoDialerCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    
    public function __construct(
        public int $campaignId
    ) {}
    
    public function handle(CampaignProcessor $processor): void
    {
        $campaign = AutoDialerCampaign::find($this->campaignId);
        
        if (!$campaign || !$campaign->isRunnable()) {
            return;
        }
        
        $processor->process($campaign);
    }
}
```

#### Rate Limiting

Use Laravel's Rate Limiter for calls per second:

```php
RateLimiter::for('auto-dialer', function (object $job) {
    return Limit::perSecond($job->campaign->calls_per_second);
});
```

### 8. Outbound Whitelist Validation

Before dialing each number:

```php
public function validateDestination(string $phoneNumber, int $organizationId): array
{
    $whitelist = OutboundWhitelist::getBestMatch($phoneNumber, $organizationId);
    
    if (!$whitelist) {
        return [
            'valid' => false,
            'error' => 'Number does not match any outbound whitelist rule',
            'trunk' => null,
        ];
    }
    
    return [
        'valid' => true,
        'error' => null,
        'trunk' => $whitelist->outbound_trunk_name,
    ];
}
```

### 9. CSV List Upload

Format:
```csv
phone_number,description
+14155551212,John Doe
+14155551213,Jane Smith
```

Processing:
1. Validate CSV format
2. Remove duplicates (keep first occurrence)
3. Validate E.164 format
4. Validate against whitelist
5. Create destinations with status

## API Endpoints

### Campaign CRUD

```
GET    /api/v1/auto-dialer-campaigns
POST   /api/v1/auto-dialer-campaigns
GET    /api/v1/auto-dialer-campaigns/{campaign}
PUT    /api/v1/auto-dialer-campaigns/{campaign}
DELETE /api/v1/auto-dialer-campaigns/{campaign}

PATCH  /api/v1/auto-dialer-campaigns/{campaign}/start
PATCH  /api/v1/auto-dialer-campaigns/{campaign}/pause
PATCH  /api/v1/auto-dialer-campaigns/{campaign}/resume
PATCH  /api/v1/auto-dialer-campaigns/{campaign}/archive
```

### List Management

```
POST   /api/v1/auto-dialer-campaigns/{campaign}/list/upload
GET    /api/v1/auto-dialer-campaigns/{campaign}/list
DELETE /api/v1/auto-dialer-campaigns/{campaign}/list
```

### Destinations

```
GET    /api/v1/auto-dialer-campaigns/{campaign}/destinations
GET    /api/v1/auto-dialer-destinations/{destination}
POST   /api/v1/auto-dialer-destinations/{destination}/retry
```

### Statistics & Reporting

```
GET    /api/v1/auto-dialer-campaigns/{campaign}/statistics
GET    /api/v1/auto-dialer-campaigns/{campaign}/progress
GET    /api/v1/auto-dialer-campaigns/{campaign}/export
```

## Frontend Components

### 1. Campaign List Page

Features:
- Table with: Name, Status, Progress Bar, Start/End Dates, Actions
- Filters: Status, Date Range
- Create Campaign button
- Real-time status updates (WebSocket or polling)

### 2. Campaign Create/Edit Form

Tabs:
1. **Basic Info**: Name, Description, Auto-start toggle
2. **Routing**: Destination type selector (AI Assistant/Load Balancer/Hangup)
3. **Dialing Settings**: Timeout, Connect mode, Caller ID, CPS, Max attempts
4. **Schedule**: Days, Hours, Date range, Timezone
5. **Recording**: Enable/disable, retention settings
6. **AMD**: Enable/disable, thresholds

### 3. List Upload Dialog

- CSV file upload
- Preview before processing
- Validation results
- Duplicate detection warning

### 4. Campaign Detail Page

Sections:
- **Overview**: Statistics cards (Total, Completed, Failed, Pending)
- **Progress**: Visual progress bar with real-time updates
- **Destinations**: Table with status, dial attempts, last call info
- **Activity Log**: Recent calls with disposition
- **Actions**: Start/Pause/Archive buttons

### 5. Call Monitor Module

Separate page: `/ui/call-monitor`

Features:
- Filter by campaign, date, disposition
- Audio player for recordings
- CDR details view
- Export to CSV

## Security & Access Control

### Roles & Permissions

**Owner:**
- Full CRUD on campaigns
- View all call records
- Archive campaigns

**PBX Admin:**
- CRUD on campaigns
- View call records for their campaigns
- Cannot archive (only Owner)

**PBX User / Reporter:**
- Read-only access to campaign statistics
- View call monitor (if permitted)

### Authorization Policy

Create `/app/Policies/AutoDialerCampaignPolicy.php`:

```php
public function viewAny(User $user): bool
{
    return $user->role->canManageUsers(); // Owner or PBX Admin
}

public function view(User $user, AutoDialerCampaign $campaign): bool
{
    return $user->organization_id === $campaign->organization_id;
}

public function create(User $user): bool
{
    return $user->role->canManageUsers();
}

public function update(User $user, AutoDialerCampaign $campaign): bool
{
    return $user->organization_id === $campaign->organization_id 
        && $user->role->canManageUsers();
}

public function delete(User $user, AutoDialerCampaign $campaign): bool
{
    // Only Owner can delete
    return $user->organization_id === $campaign->organization_id 
        && $user->role->isOwner();
}
```

## Error Handling

### Retry Logic

```php
public function shouldRetry(AutoDialerDestination $destination): bool
{
    if ($destination->dial_attempts >= $destination->campaign->max_dial_attempts) {
        return false;
    }
    
    if ($destination->status === 'invalid') {
        return false;
    }
    
    return true;
}
```

### Error Categories

1. **Validation Errors**: Invalid phone format, whitelist rejection
2. **API Errors**: Cloudonix API failures, network issues
3. **Call Failures**: Busy, no answer, disconnected
4. **System Errors**: Database failures, queue issues

## Audit Logging

Create dedicated audit action types:

```php
const AUDIT_CAMPAIGN_CREATED = 'auto_dialer.campaign.created';
const AUDIT_CAMPAIGN_UPDATED = 'auto_dialer.campaign.updated';
const AUDIT_CAMPAIGN_STARTED = 'auto_dialer.campaign.started';
const AUDIT_CAMPAIGN_PAUSED = 'auto_dialer.campaign.paused';
const AUDIT_CAMPAIGN_COMPLETED = 'auto_dialer.campaign.completed';
const AUDIT_LIST_UPLOADED = 'auto_dialer.list.uploaded';
const AUDIT_CALL_INITIATED = 'auto_dialer.call.initiated';
const AUDIT_CALL_COMPLETED = 'auto_dialer.call.completed';
const AUDIT_CALL_FAILED = 'auto_dialer.call.failed';
```

## Performance Considerations

1. **Database Indexes**: All foreign keys and frequently queried fields indexed
2. **Pagination**: All list endpoints paginated (default 25, max 100)
3. **Caching**: Campaign statistics cached for 5 minutes
4. **Queue Workers**: Dedicated queue for auto-dialer jobs
5. **Rate Limiting**: Enforced at API and job levels
6. **Batch Processing**: CSV uploads processed in batches

## Testing Strategy

### Unit Tests
- Campaign state transitions
- Whitelist validation logic
- CSV parsing and validation
- Rate limiting

### Integration Tests
- Cloudonix API mocking
- CDR webhook handling
- End-to-end campaign flow

### Feature Tests
- Campaign CRUD operations
- List upload
- Call scheduling

## Future Enhancements

1. **Time Zone Awareness**: Per-destination timezone dialing
2. **Dynamic Caller ID**: Rotate through multiple DIDs
3. **Do Not Call List**: Check against DNC registry
4. **Predictive Dialing**: ML-based answer prediction
5. **Voice Broadcasting**: Play message without AI
6. **Multi-Channel**: SMS follow-up after calls

## Migration Files Needed

1. `2026_03_16_000001_create_auto_dialer_campaigns_table.php`
2. `2026_03_16_000002_create_auto_dialer_lists_table.php`
3. `2026_03_16_000003_create_auto_dialer_destinations_table.php`
4. `2026_03_16_000004_create_auto_dialer_call_sessions_table.php`
5. `2026_03_16_000005_add_auto_dialer_flag_to_cdr_table.php`

## Appendix: CSV Upload Template

```csv
phone_number,description
+14155551212,John Doe - Sales Lead
+14155551213,Jane Smith - Support Case
+14155551214,Bob Johnson - Follow-up
```

**Validation Rules:**
- phone_number: Required, E.164 format (+ followed by 10-15 digits)
- description: Optional, max 255 characters
- Duplicate phone_numbers: First occurrence kept, duplicates ignored
- Invalid rows: Logged but not imported

---

## Document Information

**Version:** 1.0  
**Last Updated:** 2026-03-16  
**Author:** AI Assistant  
**Review Status:** Pending Review
