# Call Recording Feature - Technical Specification & Implementation Workplan

**Version:** 1.0  
**Date:** February 10, 2026  
**Status:** Ready for Implementation  
**Complexity:** High  
**Estimated Duration:** 3-4 weeks  

---

## 1. Overview

### 1.1 Purpose

The Call Recording feature enables OpBX to capture, store, and manage bi-directional audio streams from live phone calls using Cloudonix's WebSocket streaming capability. This feature provides organizations with the ability to:

- Record all inbound calls or selectively record based on business rules
- Store recordings securely with multi-tenant isolation
- Play back recordings through a web interface
- Manage retention policies and storage lifecycle
- Maintain compliance with regulatory requirements

### 1.2 Architecture Summary

```
┌─────────────────────────────────────────────────────────────────────┐
│                      Cloudonix CPaaS Platform                       │
│  (Handles VoIP, generates bi-directional audio WebSocket streams)  │
└──────────────────────────┬──────────────────────────────────────────┘
                           │ WebSocket Stream (mu-law audio)
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                Stream Receiver Service (Node.js/TypeScript)         │
│  • Accepts WebSocket connections from Cloudonix                     │
│  • Processes mu-law audio chunks                                    │
│  • Converts to WAV/MP3 format                                       │
│  • Writes to MinIO (S3-compatible storage)                          │
│  • Updates MySQL via internal API                                   │
└──────────────────────────┬──────────────────────────────────────────┘
                           │ Internal HTTP API
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    Laravel Backend (PHP)                            │
│  • CXML generation (adds <Connect><Stream> to voice routing)        │
│  • Recording metadata management (MySQL)                            │
│  • Playback endpoints (pre-signed URLs)                             │
│  • Settings & permissions (multi-tenant RBAC)                       │
└──────────────────────────┬──────────────────────────────────────────┘
                           │ REST API
                           ▼
┌─────────────────────────────────────────────────────────────────────┐
│                     React Frontend (SPA)                            │
│  • Recording toggle in Settings                                     │
│  • Playback interface in Call Logs                                  │
│  • Live recording indicators                                        │
│  • Recording management UI                                          │
└─────────────────────────────────────────────────────────────────────┘

Storage Layer:
┌─────────────────────────────────────────────────────────────────────┐
│  MinIO (S3-compatible):  recordings/{org_id}/{year}/{month}/{call_id}│
│  MySQL: call_recordings table (metadata)                            │
│  Redis: Stream session state, locks                                 │
└─────────────────────────────────────────────────────────────────────┘
```

### 1.3 Key Technical Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Stream receiver | Node.js/TypeScript | Better WebSocket performance, async I/O |
| Audio format (storage) | WAV (initial), MP3 (future) | WAV is simple, uncompressed; MP3 for efficiency |
| Storage backend | MinIO (S3-compatible) | Already in docker-compose, scalable, industry standard |
| Session management | Redis | Ephemeral state, distributed locks, low latency |
| Metadata storage | MySQL | Durable, transactional, already used for call_logs |
| Multi-tenant isolation | organization_id everywhere | Enforced at every layer (DB, storage paths, API) |

---

## 2. Objectives

### 2.1 Functional Objectives

1. **Recording Capture**: Successfully capture bi-directional audio from Cloudonix WebSocket streams
2. **Multi-Tenant Isolation**: Guarantee zero cross-tenant data leakage at all layers
3. **Storage Management**: Store recordings in MinIO with predictable paths and lifecycle policies
4. **Playback**: Provide secure playback via pre-signed URLs with expiration
5. **UI Integration**: Seamless integration into existing Call Logs and Settings pages

### 2.2 Non-Functional Objectives

1. **Performance**: Handle 100+ concurrent recording sessions per server
2. **Reliability**: 99.9% success rate for recording capture
3. **Security**: RBAC enforcement, audit logging, encryption at rest
4. **Scalability**: Horizontal scaling of stream receiver service
5. **Observability**: Full tracing of recording lifecycle with correlation IDs

### 2.3 Success Metrics

- **Technical**:
  - < 500ms latency from stream start to first chunk written
  - < 1% packet loss tolerance
  - 100% of completed calls have recordings if enabled
  - Zero cross-tenant access violations

- **User Experience**:
  - One-click enable/disable recording per organization
  - Playback starts within 2 seconds of clicking play
  - Clear visual indicators for active recordings

---

## 3. Architecture Overview

### 3.1 System Components

#### 3.1.1 Stream Receiver Service (New)

**Technology**: Node.js 20+ with TypeScript  
**Purpose**: Real-time audio stream ingestion and processing  
**Key Responsibilities**:
- Accept WebSocket connections from Cloudonix
- Authenticate incoming streams using shared secrets
- Process mu-law audio chunks in real-time
- Convert to WAV format (PCM 16-bit, 8kHz)
- Write to MinIO with organization isolation
- Update MySQL metadata via internal Laravel API
- Handle reconnections and error recovery

**Deployment**: Standalone Docker container, horizontally scalable

#### 3.1.2 Laravel Backend Extensions (Existing + New)

**New Components**:
- `CallRecording` model + migration
- `RecordingSettings` model + migration (org-level config)
- `RecordingController` (playback, metadata, delete)
- `RecordingService` (business logic)
- CXML generator updates (inject `<Connect><Stream>` when enabled)
- Internal API endpoint for stream receiver to update metadata

**Modified Components**:
- `VoiceRoutingService`: Add recording stream directives to CXML
- `CallLogController`: Add recording metadata and playback URLs
- Settings page API: Add recording toggle

#### 3.1.3 React Frontend Extensions (Existing + New)

**New Components**:
- `RecordingSettings.tsx`: Toggle, storage info
- `RecordingPlayer.tsx`: Audio player with waveform
- `RecordingIndicator.tsx`: Live "REC" badge

**Modified Components**:
- `CallLogs.tsx`: Add recording column, playback button
- `Settings.tsx`: Add recording settings section

#### 3.1.4 Storage Layer

**MinIO**:
- Bucket: `recordings`
- Path structure: `recordings/{organization_id}/{year}/{month}/{call_id}.wav`
- Lifecycle policy: Optional auto-delete after N days

**MySQL**:
- `call_recordings` table: Metadata (call_id, org_id, path, duration, size, status)
- `recording_settings` table: Org-level config (enabled, format, retention_days)

**Redis**:
- Keys:
  - `recording:session:{call_id}`: Stream session state
  - `recording:lock:{call_id}`: Distributed lock during processing
  - `recording:metadata:{call_id}`: Temporary metadata cache (TTL: 1 hour)

### 3.2 Multi-Tenant Architecture (CRITICAL)

Multi-tenant isolation is enforced at **every layer** to prevent data leakage:

#### Layer 1: Database Schema

```sql
-- EVERY query MUST include organization_id in WHERE clause
-- Enforced by Laravel scopes

CREATE TABLE call_recordings (
    id BIGINT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
    organization_id BIGINT UNSIGNED NOT NULL,  -- MANDATORY
    call_id VARCHAR(255) NOT NULL UNIQUE,
    ...
    FOREIGN KEY (organization_id) REFERENCES organizations(id) ON DELETE CASCADE,
    INDEX idx_org_call (organization_id, call_id),
    INDEX idx_org_created (organization_id, created_at)
);

-- Global scopes applied to ALL models:
$query->where('organization_id', auth()->user()->organization_id);
```

#### Layer 2: Storage Paths

```
MinIO Bucket: recordings/

Path Pattern: {organization_id}/{year}/{month}/{call_id}.wav

Examples:
  recordings/42/2026/02/call-abc123.wav  (Org 42)
  recordings/57/2026/02/call-xyz789.wav  (Org 57)

Pre-signed URL generation:
  - MUST include organization_id check before generating URL
  - MUST set short expiration (default: 15 minutes)
  - MUST NOT expose organization_id in public URLs (use UUIDs)
```

#### Layer 3: API Endpoints

```php
// ALL recording endpoints require authentication
// ALL queries scoped to user's organization_id

Route::middleware(['auth:sanctum'])->group(function () {
    Route::get('/recordings/{recording}', [RecordingController::class, 'show']);
    // Laravel policy ensures: $recording->organization_id === auth()->user()->organization_id
});

// Internal API for stream receiver (different auth)
Route::middleware(['internal.api.auth'])->group(function () {
    Route::post('/internal/recordings/metadata', [InternalRecordingController::class, 'updateMetadata']);
    // Validates organization_id from JWT payload
});
```

#### Layer 4: Stream Receiver Service

```typescript
// Stream URL includes organization_id in JWT payload
// JWT verified before accepting WebSocket connection

interface StreamAuthPayload {
  call_id: string;
  organization_id: number;
  timestamp: number;
  signature: string; // HMAC of above fields
}

// Storage path constructed from verified org_id
const storagePath = `${orgId}/${year}/${month}/${callId}.wav`;
```

#### Layer 5: Frontend

```typescript
// API client always includes auth token (Sanctum)
// Server enforces organization_id from token

// NO organization_id in URLs or query params
// Use opaque IDs (e.g., recording UUID)

// Example:
GET /api/v1/recordings/f47ac10b-58cc-4372-a567-0e02b2c3d479
// Server resolves: recording.organization_id === auth().user.organization_id
```

### 3.3 Data Flow Diagram

```
┌─────────────────────────────────────────────────────────────────────┐
│ PHASE 1: Call Initiated                                             │
└─────────────────────────────────────────────────────────────────────┘
1. Cloudonix sends webhook to Laravel: /api/voice/route
2. Laravel VoiceRoutingService checks: is recording enabled for org?
3. If YES:
   a. Generate JWT token for stream receiver (contains call_id + org_id)
   b. Generate stream WebSocket URL: wss://opbx.example.com/stream/{token}
   c. Insert CXML: <Connect><Stream url="wss://..." /></Connect>
4. Laravel returns CXML to Cloudonix
5. Laravel creates call_recordings row: status='pending'

┌─────────────────────────────────────────────────────────────────────┐
│ PHASE 2: Stream Established                                         │
└─────────────────────────────────────────────────────────────────────┘
6. Cloudonix opens WebSocket to stream receiver
7. Stream receiver validates JWT token
8. Stream receiver acquires Redis lock: lock:recording:{call_id}
9. Stream receiver updates call_recordings: status='recording'
10. Stream receiver opens MinIO write stream: {org_id}/{year}/{month}/{call_id}.wav
11. Stream receiver sends "started" event to Laravel (via internal API)
12. Laravel broadcasts to frontend: "Recording started for call X"

┌─────────────────────────────────────────────────────────────────────┐
│ PHASE 3: Audio Streaming (Real-time)                                │
└─────────────────────────────────────────────────────────────────────┘
13. Cloudonix sends mu-law audio chunks (WebSocket binary frames)
14. Stream receiver decodes mu-law → PCM
15. Stream receiver writes WAV chunks to MinIO
16. Stream receiver updates Redis: recording:session:{call_id} (bytes written, duration)
17. Loop until call ends or WebSocket closes

┌─────────────────────────────────────────────────────────────────────┐
│ PHASE 4: Stream Finalization                                        │
└─────────────────────────────────────────────────────────────────────┘
18. WebSocket closes (call ended or error)
19. Stream receiver finalizes WAV file (write footer, close stream)
20. Stream receiver calculates: file_size, duration, checksum
21. Stream receiver calls Laravel internal API:
    POST /api/internal/recordings/finalize
    { call_id, status: 'completed', file_size, duration, storage_path }
22. Laravel updates call_recordings:
    status='completed', file_size, duration, storage_path, completed_at=now()
23. Laravel releases Redis lock
24. Laravel broadcasts to frontend: "Recording completed for call X"

┌─────────────────────────────────────────────────────────────────────┐
│ PHASE 5: Playback Request                                           │
└─────────────────────────────────────────────────────────────────────┘
25. User clicks "Play" in Call Logs
26. Frontend requests: GET /api/v1/recordings/{uuid}/playback-url
27. Laravel checks: recording.organization_id === auth.user.organization_id
28. Laravel generates pre-signed MinIO URL (expires in 15 min)
29. Laravel returns: { url: "https://minio/...", expires_at: "..." }
30. Frontend plays audio using HTML5 <audio> element
```

---

## 4. Technology Stack

### 4.1 Stream Receiver Service

| Component | Technology | Version | Purpose |
|-----------|-----------|---------|---------|
| Runtime | Node.js | 20.x LTS | JavaScript execution |
| Language | TypeScript | 5.x | Type safety |
| WebSocket Server | ws | 8.x | WebSocket protocol |
| Audio Processing | node-wav | 0.0.2 | WAV file encoding |
| Audio Codec | @ronomon/audio | 1.x | mu-law decoding |
| S3 Client | @aws-sdk/client-s3 | 3.x | MinIO uploads |
| HTTP Client | axios | 1.x | Internal API calls |
| JWT | jsonwebtoken | 9.x | Token validation |
| Redis Client | ioredis | 5.x | Session state, locks |
| Logging | winston | 3.x | Structured logging |
| Testing | vitest | 1.x | Unit tests |

### 4.2 Laravel Backend

| Component | Technology | Purpose |
|-----------|-----------|---------|
| CXML Generation | Custom builder | Inject `<Connect><Stream>` |
| File Storage | Laravel Storage (S3 driver) | MinIO integration |
| Queue Jobs | Laravel Queues (Redis) | Async processing |
| Events | Laravel Broadcasting | Real-time UI updates |
| Policies | Laravel Policies | RBAC authorization |

### 4.3 Frontend

| Component | Technology | Purpose |
|-----------|-----------|---------|
| Audio Player | react-h5-audio-player | 3.x | Playback UI |
| Waveform Visualization | wavesurfer.js | 7.x | Audio waveform |
| State Management | React Context | Recording state |

### 4.4 Infrastructure

| Component | Technology | Notes |
|-----------|-----------|-------|
| Container Runtime | Podman (docker compose) | Existing setup |
| Storage | MinIO | Already in docker-compose.yml |
| Database | MySQL 8.0 | Existing |
| Cache/Queue | Redis 7 | Existing |
| Reverse Proxy | Nginx | WebSocket proxy config required |

---

## 5. Multi-Tenant Architecture (CRITICAL)

### 5.1 Isolation Enforcement Checklist

#### Database Layer
- [x] `organization_id` column in `call_recordings` table (NOT NULL, INDEXED)
- [x] Foreign key constraint to `organizations` table with CASCADE DELETE
- [x] Laravel global scope on `CallRecording` model:
  ```php
  protected static function booted()
  {
      static::addGlobalScope('organization', function (Builder $query) {
          if (auth()->check()) {
              $query->where('organization_id', auth()->user()->organization_id);
          }
      });
  }
  ```
- [x] Policy checks in `RecordingPolicy`:
  ```php
  public function view(User $user, CallRecording $recording): bool
  {
      return $user->organization_id === $recording->organization_id;
  }
  ```

#### Storage Layer
- [x] Path prefix ALWAYS starts with `organization_id`
- [x] Pre-signed URL generation includes organization check:
  ```php
  public function getPlaybackUrl(CallRecording $recording): string
  {
      // Policy already checked organization_id
      $client = Storage::disk('s3')->getClient();
      $command = $client->getCommand('GetObject', [
          'Bucket' => config('filesystems.disks.s3.bucket'),
          'Key' => $recording->storage_path, // already has org_id prefix
      ]);
      $request = $client->createPresignedRequest($command, '+15 minutes');
      return (string) $request->getUri();
  }
  ```

#### API Layer
- [x] ALL routes require `auth:sanctum` middleware
- [x] ALL controllers use policy authorization:
  ```php
  public function show(CallRecording $recording)
  {
      $this->authorize('view', $recording);
      // ...
  }
  ```
- [x] Internal API uses separate authentication:
  ```php
  // Middleware: VerifyInternalApiToken
  // Validates JWT with organization_id in payload
  ```

#### Stream Receiver Layer
- [x] JWT payload includes `organization_id`
- [x] Storage path constructed from JWT org_id (not from request body)
- [x] Internal API calls include `organization_id` in payload
- [x] Laravel validates org_id matches call_log.organization_id

#### Frontend Layer
- [x] NO organization_id exposed in URLs
- [x] Use recording UUIDs (not sequential IDs)
- [x] All API calls include Sanctum auth token
- [x] No client-side storage of sensitive org data

### 5.2 Access Control Matrix

| Role | Create Recording | View Own Org Recordings | Play Recording | Delete Recording | Configure Settings |
|------|------------------|-------------------------|----------------|------------------|--------------------|
| Owner | Auto (system) | ✅ All | ✅ All | ✅ All | ✅ Yes |
| Admin | Auto (system) | ✅ All | ✅ All | ✅ All | ✅ Yes |
| Agent | Auto (system) | ✅ Own calls only | ✅ Own calls only | ❌ No | ❌ No |
| Guest | ❌ No | ❌ No | ❌ No | ❌ No | ❌ No |

**Laravel Policy Implementation**:
```php
class RecordingPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, ['owner', 'admin', 'agent']);
    }

    public function view(User $user, CallRecording $recording): bool
    {
        // Organization check (via global scope)
        if ($user->organization_id !== $recording->organization_id) {
            return false;
        }

        // Role-based access
        if (in_array($user->role, ['owner', 'admin'])) {
            return true;
        }

        // Agents can only view their own call recordings
        if ($user->role === 'agent') {
            return $recording->callLog->extension_id === $user->extension_id;
        }

        return false;
    }

    public function delete(User $user, CallRecording $recording): bool
    {
        return $user->organization_id === $recording->organization_id
            && in_array($user->role, ['owner', 'admin']);
    }

    public function updateSettings(User $user): bool
    {
        return in_array($user->role, ['owner', 'admin']);
    }
}
```

### 5.3 Testing Multi-Tenant Isolation

**Required Test Cases**:

```php
// Test 1: Cannot access another org's recordings
public function test_cannot_view_other_organization_recording()
{
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    
    $userOrg1 = User::factory()->for($org1)->create(['role' => 'admin']);
    $userOrg2 = User::factory()->for($org2)->create(['role' => 'admin']);
    
    $recordingOrg1 = CallRecording::factory()->for($org1)->create();
    
    $this->actingAs($userOrg2)
         ->get("/api/v1/recordings/{$recordingOrg1->id}")
         ->assertForbidden();
}

// Test 2: Storage paths include organization_id
public function test_storage_path_includes_organization_id()
{
    $org = Organization::factory()->create(['id' => 42]);
    $recording = CallRecording::factory()->for($org)->create();
    
    $this->assertTrue(str_starts_with($recording->storage_path, '42/'));
}

// Test 3: Pre-signed URLs only generated for own org
public function test_playback_url_requires_same_organization()
{
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    
    $userOrg1 = User::factory()->for($org1)->create(['role' => 'admin']);
    $recordingOrg2 = CallRecording::factory()->for($org2)->create();
    
    $this->actingAs($userOrg1)
         ->get("/api/v1/recordings/{$recordingOrg2->id}/playback-url")
         ->assertForbidden();
}

// Test 4: Global scope filters queries
public function test_global_scope_filters_by_organization()
{
    $org1 = Organization::factory()->create();
    $org2 = Organization::factory()->create();
    
    CallRecording::factory()->for($org1)->count(5)->create();
    CallRecording::factory()->for($org2)->count(3)->create();
    
    $userOrg1 = User::factory()->for($org1)->create();
    
    $this->actingAs($userOrg1);
    $this->assertEquals(5, CallRecording::count()); // Only org1 recordings
}
```

---

## 6. Database Schema

### 6.1 Migration: `call_recordings` Table

**File**: `database/migrations/2026_02_10_000001_create_call_recordings_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            
            // Multi-tenant isolation (CRITICAL)
            $table->foreignId('organization_id')
                  ->constrained()
                  ->cascadeOnDelete();
            
            // Relationships
            $table->string('call_id')->unique();
            $table->foreign('call_id')
                  ->references('call_id')
                  ->on('call_logs')
                  ->cascadeOnDelete();
            
            // Recording metadata
            $table->enum('status', [
                'pending',      // CXML sent, waiting for stream
                'recording',    // Active stream
                'processing',   // Finalizing file
                'completed',    // Ready for playback
                'failed',       // Error occurred
            ])->default('pending');
            
            $table->string('storage_path')->nullable(); // {org_id}/{year}/{month}/{call_id}.wav
            $table->enum('format', ['wav', 'mp3'])->default('wav');
            $table->unsignedInteger('file_size_bytes')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('checksum')->nullable(); // MD5 hash
            
            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes(); // Soft delete for retention policies
            
            // Indexes for performance
            $table->index(['organization_id', 'created_at']);
            $table->index(['organization_id', 'status']);
            $table->index('call_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_recordings');
    }
};
```

### 6.2 Migration: `recording_settings` Table

**File**: `database/migrations/2026_02_10_000002_create_recording_settings_table.php`

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recording_settings', function (Blueprint $table) {
            $table->id();
            
            // One row per organization
            $table->foreignId('organization_id')
                  ->unique()
                  ->constrained()
                  ->cascadeOnDelete();
            
            // Settings
            $table->boolean('enabled')->default(false);
            $table->enum('format', ['wav', 'mp3'])->default('wav');
            $table->unsignedInteger('retention_days')->nullable(); // null = keep forever
            $table->boolean('auto_delete')->default(false);
            
            // Storage limits (optional)
            $table->unsignedBigInteger('max_storage_mb')->nullable();
            
            // Selective recording (future)
            $table->json('record_rules')->nullable(); // e.g., { "record_extensions": [1,2,3] }
            
            $table->timestamps();
            
            $table->index('organization_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_settings');
    }
};
```

### 6.3 Database Relationships

```
organizations (existing)
    ├─1:N─→ call_recordings
    └─1:1─→ recording_settings

call_logs (existing)
    └─1:1─→ call_recordings (via call_id)

users (existing)
    └─N:1─→ organizations (checked against call_recordings.organization_id)
```

### 6.4 Indexes Strategy

| Table | Index | Purpose |
|-------|-------|---------|
| call_recordings | (organization_id, created_at) | Org-scoped listing with date filter |
| call_recordings | (organization_id, status) | Filter by status within org |
| call_recordings | call_id (UNIQUE) | Fast call log joins |
| call_recordings | uuid (UNIQUE) | Public-facing opaque IDs |
| recording_settings | organization_id (UNIQUE) | One setting per org |

---

## 7. Stream Receiver Service

### 7.1 Project Structure

```
stream-receiver/
├── src/
│   ├── index.ts                 # Main entry point
│   ├── server.ts                # WebSocket server setup
│   ├── handlers/
│   │   ├── stream.handler.ts   # WebSocket message handling
│   │   └── auth.handler.ts     # JWT validation
│   ├── services/
│   │   ├── audio.service.ts    # Audio processing (mu-law → WAV)
│   │   ├── storage.service.ts  # MinIO uploads
│   │   ├── metadata.service.ts # Laravel API calls
│   │   └── redis.service.ts    # Session state & locks
│   ├── types/
│   │   ├── stream.types.ts     # TypeScript interfaces
│   │   └── config.types.ts
│   └── utils/
│       ├── logger.ts            # Winston logger
│       └── errors.ts            # Custom error classes
├── tests/
│   ├── integration/
│   │   └── stream.test.ts
│   └── unit/
│       ├── audio.test.ts
│       └── storage.test.ts
├── Dockerfile
├── package.json
├── tsconfig.json
└── .env.example
```

### 7.2 Core Implementation

#### 7.2.1 WebSocket Server (`server.ts`)

```typescript
import WebSocket from 'ws';
import { createServer } from 'http';
import { StreamHandler } from './handlers/stream.handler';
import { AuthHandler } from './handlers/auth.handler';
import { logger } from './utils/logger';

const PORT = process.env.STREAM_RECEIVER_PORT || 8080;

export class StreamReceiverServer {
  private wss: WebSocket.Server;
  private authHandler: AuthHandler;
  private streamHandler: StreamHandler;

  constructor() {
    const httpServer = createServer();
    this.wss = new WebSocket.Server({ server: httpServer });
    this.authHandler = new AuthHandler();
    this.streamHandler = new StreamHandler();

    this.setupWebSocketHandlers();
    httpServer.listen(PORT, () => {
      logger.info(`Stream receiver listening on port ${PORT}`);
    });
  }

  private setupWebSocketHandlers(): void {
    this.wss.on('connection', async (ws: WebSocket, req) => {
      const token = this.extractTokenFromUrl(req.url);
      
      try {
        // Authenticate stream
        const payload = await this.authHandler.validateToken(token);
        const { call_id, organization_id } = payload;

        logger.info('Stream connected', { call_id, organization_id });

        // Initialize stream handler
        await this.streamHandler.initialize(ws, call_id, organization_id);

        // Handle incoming audio chunks
        ws.on('message', async (data: Buffer) => {
          await this.streamHandler.processChunk(call_id, data);
        });

        // Handle stream end
        ws.on('close', async () => {
          await this.streamHandler.finalize(call_id);
          logger.info('Stream closed', { call_id });
        });

        ws.on('error', async (error) => {
          logger.error('WebSocket error', { call_id, error: error.message });
          await this.streamHandler.handleError(call_id, error);
        });

      } catch (error) {
        logger.error('Authentication failed', { error: error.message });
        ws.close(4001, 'Unauthorized');
      }
    });
  }

  private extractTokenFromUrl(url: string | undefined): string {
    if (!url) throw new Error('No URL provided');
    const match = url.match(/\/stream\/([^?]+)/);
    if (!match) throw new Error('Invalid URL format');
    return match[1];
  }
}
```

#### 7.2.2 Stream Handler (`handlers/stream.handler.ts`)

```typescript
import WebSocket from 'ws';
import { AudioService } from '../services/audio.service';
import { StorageService } from '../services/storage.service';
import { MetadataService } from '../services/metadata.service';
import { RedisService } from '../services/redis.service';
import { logger } from '../utils/logger';

interface StreamSession {
  callId: string;
  organizationId: number;
  startedAt: Date;
  bytesWritten: number;
  chunksProcessed: number;
  storageStream: NodeJS.WritableStream | null;
}

export class StreamHandler {
  private sessions: Map<string, StreamSession> = new Map();
  private audioService: AudioService;
  private storageService: StorageService;
  private metadataService: MetadataService;
  private redisService: RedisService;

  constructor() {
    this.audioService = new AudioService();
    this.storageService = new StorageService();
    this.metadataService = new MetadataService();
    this.redisService = new RedisService();
  }

  async initialize(ws: WebSocket, callId: string, organizationId: number): Promise<void> {
    // Acquire distributed lock
    const lockAcquired = await this.redisService.acquireLock(
      `lock:recording:${callId}`,
      30000 // 30 second timeout
    );

    if (!lockAcquired) {
      throw new Error('Failed to acquire recording lock');
    }

    // Create storage path: {org_id}/{year}/{month}/{call_id}.wav
    const now = new Date();
    const year = now.getFullYear();
    const month = String(now.getMonth() + 1).padStart(2, '0');
    const storagePath = `${organizationId}/${year}/${month}/${callId}.wav`;

    // Open storage stream
    const storageStream = await this.storageService.createWriteStream(storagePath);

    // Initialize WAV header (will be updated on finalization)
    await this.audioService.writeWavHeader(storageStream);

    // Create session
    const session: StreamSession = {
      callId,
      organizationId,
      startedAt: now,
      bytesWritten: 0,
      chunksProcessed: 0,
      storageStream,
    };

    this.sessions.set(callId, session);

    // Store session state in Redis
    await this.redisService.setSessionState(callId, {
      status: 'recording',
      organizationId,
      storagePath,
      startedAt: now.toISOString(),
    });

    // Notify Laravel: recording started
    await this.metadataService.updateStatus(callId, 'recording', {
      started_at: now.toISOString(),
      storage_path: storagePath,
    });

    logger.info('Stream initialized', { callId, organizationId, storagePath });
  }

  async processChunk(callId: string, data: Buffer): Promise<void> {
    const session = this.sessions.get(callId);
    if (!session || !session.storageStream) {
      logger.warn('No active session for chunk', { callId });
      return;
    }

    try {
      // Decode mu-law to PCM
      const pcmData = this.audioService.decodeMuLaw(data);

      // Write to storage stream
      const written = await this.audioService.writePcmData(session.storageStream, pcmData);

      session.bytesWritten += written;
      session.chunksProcessed += 1;

      // Update Redis session state every 10 chunks (reduce overhead)
      if (session.chunksProcessed % 10 === 0) {
        await this.redisService.updateSessionState(callId, {
          bytesWritten: session.bytesWritten,
          chunksProcessed: session.chunksProcessed,
        });
      }

    } catch (error) {
      logger.error('Chunk processing error', { callId, error: error.message });
      throw error;
    }
  }

  async finalize(callId: string): Promise<void> {
    const session = this.sessions.get(callId);
    if (!session) {
      logger.warn('No session to finalize', { callId });
      return;
    }

    try {
      // Finalize WAV file (update header with actual size)
      if (session.storageStream) {
        await this.audioService.finalizeWavFile(session.storageStream, session.bytesWritten);
        session.storageStream.end();
      }

      // Calculate duration (8kHz sample rate, 16-bit PCM)
      const durationSeconds = Math.floor(session.bytesWritten / (8000 * 2));

      // Calculate checksum
      const checksum = await this.storageService.calculateChecksum(session.organizationId, callId);

      // Notify Laravel: recording completed
      await this.metadataService.updateStatus(callId, 'completed', {
        completed_at: new Date().toISOString(),
        file_size_bytes: session.bytesWritten,
        duration_seconds: durationSeconds,
        checksum,
      });

      // Clean up
      this.sessions.delete(callId);
      await this.redisService.deleteSessionState(callId);
      await this.redisService.releaseLock(`lock:recording:${callId}`);

      logger.info('Stream finalized', {
        callId,
        bytesWritten: session.bytesWritten,
        durationSeconds,
      });

    } catch (error) {
      logger.error('Finalization error', { callId, error: error.message });
      await this.handleError(callId, error);
    }
  }

  async handleError(callId: string, error: Error): Promise<void> {
    const session = this.sessions.get(callId);

    if (session?.storageStream) {
      session.storageStream.end();
    }

    await this.metadataService.updateStatus(callId, 'failed', {
      error: error.message,
    });

    this.sessions.delete(callId);
    await this.redisService.deleteSessionState(callId);
    await this.redisService.releaseLock(`lock:recording:${callId}`);

    logger.error('Stream error handled', { callId, error: error.message });
  }
}
```

#### 7.2.3 Audio Service (`services/audio.service.ts`)

```typescript
import { Readable, Writable } from 'stream';
import { decode as muLawDecode } from '@ronomon/audio';

export class AudioService {
  private readonly SAMPLE_RATE = 8000; // 8kHz
  private readonly BITS_PER_SAMPLE = 16;
  private readonly CHANNELS = 1; // Mono

  decodeMuLaw(muLawData: Buffer): Buffer {
    // Decode mu-law to 16-bit PCM
    const pcmData = Buffer.alloc(muLawData.length * 2);
    
    for (let i = 0; i < muLawData.length; i++) {
      const sample = this.muLawToPcm(muLawData[i]);
      pcmData.writeInt16LE(sample, i * 2);
    }
    
    return pcmData;
  }

  private muLawToPcm(muLawByte: number): number {
    // Standard mu-law decode algorithm
    const sign = (muLawByte & 0x80) >> 7;
    const exponent = (muLawByte & 0x70) >> 4;
    const mantissa = muLawByte & 0x0F;
    
    let sample = ((mantissa << 3) + 0x84) << exponent;
    if (sign === 0) sample = -sample;
    
    return sample;
  }

  async writeWavHeader(stream: Writable): Promise<void> {
    // Write minimal WAV header (will be updated on finalization)
    const header = Buffer.alloc(44);
    
    // "RIFF" chunk descriptor
    header.write('RIFF', 0);
    header.writeUInt32LE(0, 4); // Placeholder for file size
    header.write('WAVE', 8);
    
    // "fmt " sub-chunk
    header.write('fmt ', 12);
    header.writeUInt32LE(16, 16); // Sub-chunk size
    header.writeUInt16LE(1, 20); // Audio format (1 = PCM)
    header.writeUInt16LE(this.CHANNELS, 22);
    header.writeUInt32LE(this.SAMPLE_RATE, 24);
    header.writeUInt32LE(this.SAMPLE_RATE * this.CHANNELS * this.BITS_PER_SAMPLE / 8, 28); // Byte rate
    header.writeUInt16LE(this.CHANNELS * this.BITS_PER_SAMPLE / 8, 32); // Block align
    header.writeUInt16LE(this.BITS_PER_SAMPLE, 34);
    
    // "data" sub-chunk
    header.write('data', 36);
    header.writeUInt32LE(0, 40); // Placeholder for data size
    
    stream.write(header);
  }

  async writePcmData(stream: Writable, pcmData: Buffer): Promise<number> {
    return new Promise((resolve, reject) => {
      const canContinue = stream.write(pcmData);
      
      if (canContinue) {
        resolve(pcmData.length);
      } else {
        stream.once('drain', () => resolve(pcmData.length));
        stream.once('error', reject);
      }
    });
  }

  async finalizeWavFile(stream: Writable, totalDataBytes: number): Promise<void> {
    // WAV files need the total size in the header
    // In a real implementation, we'd seek back and update the header
    // For now, we'll just close the stream
    // MinIO doesn't support seeking, so we'd need to:
    // 1. Write to temp file
    // 2. Finalize header
    // 3. Upload to MinIO
    // OR: Use a WAV library that handles streaming properly
    
    // TODO: Implement proper WAV finalization
    // For MVP, we'll accept slightly malformed WAV headers
  }
}
```

#### 7.2.4 Storage Service (`services/storage.service.ts`)

```typescript
import { S3Client, PutObjectCommand, GetObjectCommand } from '@aws-sdk/client-s3';
import { Writable } from 'stream';
import * as crypto from 'crypto';

export class StorageService {
  private s3Client: S3Client;
  private bucket: string;

  constructor() {
    this.bucket = process.env.MINIO_BUCKET || 'recordings';
    
    this.s3Client = new S3Client({
      endpoint: process.env.MINIO_ENDPOINT || 'http://minio:9000',
      region: process.env.MINIO_REGION || 'us-east-1',
      credentials: {
        accessKeyId: process.env.MINIO_ACCESS_KEY || 'minioadmin',
        secretAccessKey: process.env.MINIO_SECRET_KEY || 'minioadmin',
      },
      forcePathStyle: true, // Required for MinIO
    });
  }

  async createWriteStream(storagePath: string): Writable {
    // Create a pass-through stream that accumulates data
    // and uploads to MinIO on finalization
    const chunks: Buffer[] = [];
    
    const stream = new Writable({
      write(chunk, encoding, callback) {
        chunks.push(Buffer.from(chunk));
        callback();
      },
      final: async (callback) => {
        try {
          const buffer = Buffer.concat(chunks);
          
          await this.s3Client.send(new PutObjectCommand({
            Bucket: this.bucket,
            Key: storagePath,
            Body: buffer,
            ContentType: 'audio/wav',
          }));
          
          callback();
        } catch (error) {
          callback(error);
        }
      },
    });

    return stream;
  }

  async calculateChecksum(organizationId: number, callId: string): Promise<string> {
    const year = new Date().getFullYear();
    const month = String(new Date().getMonth() + 1).padStart(2, '0');
    const storagePath = `${organizationId}/${year}/${month}/${callId}.wav`;

    const response = await this.s3Client.send(new GetObjectCommand({
      Bucket: this.bucket,
      Key: storagePath,
    }));

    const hash = crypto.createHash('md5');
    
    return new Promise((resolve, reject) => {
      response.Body.on('data', (chunk) => hash.update(chunk));
      response.Body.on('end', () => resolve(hash.digest('hex')));
      response.Body.on('error', reject);
    });
  }
}
```

### 7.3 Environment Variables

**File**: `stream-receiver/.env.example`

```env
# Server
STREAM_RECEIVER_PORT=8080
NODE_ENV=production

# Authentication
JWT_SECRET=your-jwt-secret-shared-with-laravel

# MinIO / S3
MINIO_ENDPOINT=http://minio:9000
MINIO_ACCESS_KEY=minioadmin
MINIO_SECRET_KEY=minioadmin
MINIO_BUCKET=recordings
MINIO_REGION=us-east-1

# Redis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=your-redis-password

# Laravel Internal API
LARAVEL_INTERNAL_API_URL=http://app/api/internal
LARAVEL_INTERNAL_API_TOKEN=your-internal-api-token

# Logging
LOG_LEVEL=info
```

---

## 8. Laravel Backend Integration

### 8.1 Models

#### 8.1.1 CallRecording Model

**File**: `app/Models/CallRecording.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class CallRecording extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'uuid',
        'organization_id',
        'call_id',
        'status',
        'storage_path',
        'format',
        'file_size_bytes',
        'duration_seconds',
        'checksum',
        'started_at',
        'completed_at',
    ];

    protected $casts = [
        'file_size_bytes' => 'integer',
        'duration_seconds' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    protected $hidden = [
        'storage_path', // Never expose raw paths to frontend
    ];

    protected static function booted(): void
    {
        // Auto-generate UUID
        static::creating(function (CallRecording $recording) {
            if (!$recording->uuid) {
                $recording->uuid = (string) Str::uuid();
            }
        });

        // Global scope: ALWAYS filter by organization_id
        static::addGlobalScope('organization', function (Builder $query) {
            if (auth()->check()) {
                $query->where('organization_id', auth()->user()->organization_id);
            }
        });
    }

    // Relationships
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function callLog(): BelongsTo
    {
        return $this->belongsTo(CallLog::class, 'call_id', 'call_id');
    }

    // Accessors
    public function getFileSizeMbAttribute(): float
    {
        return round($this->file_size_bytes / 1024 / 1024, 2);
    }

    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_seconds) {
            return '00:00';
        }
        
        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;
        
        return sprintf('%02d:%02d', $minutes, $seconds);
    }

    public function isPlayable(): bool
    {
        return $this->status === 'completed' && $this->storage_path;
    }
}
```

#### 8.1.2 RecordingSettings Model

**File**: `app/Models/RecordingSettings.php`

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecordingSettings extends Model
{
    protected $fillable = [
        'organization_id',
        'enabled',
        'format',
        'retention_days',
        'auto_delete',
        'max_storage_mb',
        'record_rules',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'auto_delete' => 'boolean',
        'retention_days' => 'integer',
        'max_storage_mb' => 'integer',
        'record_rules' => 'array',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function forOrganization(int $organizationId): self
    {
        return static::firstOrCreate(
            ['organization_id' => $organizationId],
            ['enabled' => false, 'format' => 'wav']
        );
    }
}
```

### 8.2 Controllers

#### 8.2.1 RecordingController (Public API)

**File**: `app/Http/Controllers/Api/V1/RecordingController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\CallRecordingResource;
use App\Models\CallRecording;
use App\Services\RecordingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RecordingController extends Controller
{
    public function __construct(
        private RecordingService $recordingService
    ) {}

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', CallRecording::class);

        $recordings = CallRecording::query()
            ->with(['callLog'])
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->latest()
            ->paginate($request->per_page ?? 25);

        return response()->json([
            'data' => CallRecordingResource::collection($recordings->items()),
            'meta' => [
                'current_page' => $recordings->currentPage(),
                'total' => $recordings->total(),
                'per_page' => $recordings->perPage(),
            ],
        ]);
    }

    public function show(CallRecording $recording): JsonResponse
    {
        Gate::authorize('view', $recording);

        return response()->json([
            'data' => new CallRecordingResource($recording->load('callLog')),
        ]);
    }

    public function playbackUrl(CallRecording $recording): JsonResponse
    {
        Gate::authorize('view', $recording);

        if (!$recording->isPlayable()) {
            return response()->json([
                'message' => 'Recording is not available for playback',
            ], 422);
        }

        $url = $this->recordingService->generatePlaybackUrl($recording);
        $expiresAt = now()->addMinutes(15);

        return response()->json([
            'url' => $url,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function destroy(CallRecording $recording): JsonResponse
    {
        Gate::authorize('delete', $recording);

        $this->recordingService->deleteRecording($recording);

        return response()->json([
            'message' => 'Recording deleted successfully',
        ]);
    }
}
```

#### 8.2.2 RecordingSettingsController

**File**: `app/Http/Controllers/Api/V1/RecordingSettingsController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\RecordingSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RecordingSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        $settings = RecordingSettings::forOrganization(auth()->user()->organization_id);

        return response()->json(['data' => $settings]);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('updateSettings', RecordingSettings::class);

        $validated = $request->validate([
            'enabled' => 'boolean',
            'format' => Rule::in(['wav', 'mp3']),
            'retention_days' => 'nullable|integer|min:1|max:3650',
            'auto_delete' => 'boolean',
            'max_storage_mb' => 'nullable|integer|min:100',
        ]);

        $settings = RecordingSettings::forOrganization(auth()->user()->organization_id);
        $settings->update($validated);

        return response()->json([
            'data' => $settings,
            'message' => 'Recording settings updated successfully',
        ]);
    }
}
```

#### 8.2.3 Internal Recording Controller

**File**: `app/Http/Controllers/Internal/RecordingController.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\CallRecording;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Internal API for stream receiver service
 * Authentication: Internal API token (different from user auth)
 */
class InternalRecordingController extends Controller
{
    public function updateMetadata(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'call_id' => 'required|string',
            'status' => 'required|in:pending,recording,processing,completed,failed',
            'started_at' => 'nullable|date',
            'completed_at' => 'nullable|date',
            'storage_path' => 'nullable|string',
            'file_size_bytes' => 'nullable|integer',
            'duration_seconds' => 'nullable|integer',
            'checksum' => 'nullable|string',
            'error' => 'nullable|string',
        ]);

        $recording = CallRecording::withoutGlobalScope('organization')
            ->where('call_id', $validated['call_id'])
            ->firstOrFail();

        DB::transaction(function () use ($recording, $validated) {
            $recording->update($validated);

            // Broadcast event to frontend
            if ($validated['status'] === 'completed') {
                event(new \App\Events\RecordingCompleted($recording));
            }
        });

        return response()->json([
            'message' => 'Metadata updated successfully',
        ]);
    }
}
```

### 8.3 Services

#### 8.3.1 RecordingService

**File**: `app/Services/RecordingService.php`

```php
<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CallRecording;
use App\Models\RecordingSettings;
use Illuminate\Support\Facades\Storage;
use Aws\S3\S3Client;

class RecordingService
{
    public function isRecordingEnabled(int $organizationId): bool
    {
        $settings = RecordingSettings::forOrganization($organizationId);
        return $settings->enabled;
    }

    public function generateStreamUrl(string $callId, int $organizationId): string
    {
        // Generate JWT token for stream receiver authentication
        $payload = [
            'call_id' => $callId,
            'organization_id' => $organizationId,
            'timestamp' => now()->timestamp,
            'exp' => now()->addMinutes(5)->timestamp,
        ];

        $token = \Firebase\JWT\JWT::encode(
            $payload,
            config('recording.jwt_secret'),
            'HS256'
        );

        $streamReceiverUrl = config('recording.stream_receiver_url');
        
        return "{$streamReceiverUrl}/stream/{$token}";
    }

    public function createPendingRecording(string $callId, int $organizationId): CallRecording
    {
        return CallRecording::create([
            'call_id' => $callId,
            'organization_id' => $organizationId,
            'status' => 'pending',
        ]);
    }

    public function generatePlaybackUrl(CallRecording $recording): string
    {
        $s3Client = Storage::disk('s3')->getClient();
        
        $command = $s3Client->getCommand('GetObject', [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key' => $recording->storage_path,
        ]);

        $request = $s3Client->createPresignedRequest($command, '+15 minutes');
        
        return (string) $request->getUri();
    }

    public function deleteRecording(CallRecording $recording): void
    {
        // Delete from storage
        if ($recording->storage_path) {
            Storage::disk('s3')->delete($recording->storage_path);
        }

        // Soft delete from database
        $recording->delete();
    }
}
```

### 8.4 CXML Integration

**File**: `app/Services/Cxml/VoiceRoutingService.php` (modifications)

```php
public function generateConnectCxml(Extension $extension, string $callId): string
{
    $cxml = new CxmlBuilder();

    // Check if recording is enabled
    if ($this->recordingService->isRecordingEnabled($extension->organization_id)) {
        // Create pending recording
        $this->recordingService->createPendingRecording($callId, $extension->organization_id);

        // Generate stream URL
        $streamUrl = $this->recordingService->generateStreamUrl($callId, $extension->organization_id);

        // Add Stream directive FIRST (must be before Dial)
        $cxml->connect()
             ->stream($streamUrl, [
                 'track' => 'both', // Record both inbound and outbound
             ]);
    }

    // Add Dial directive
    $cxml->connect()
         ->dial($extension->sip_address, [
             'timeout' => config('voice.no_answer_timeout', 30),
         ]);

    return $cxml->toXml();
}
```

### 8.5 Routes

**File**: `routes/api.php` (additions)

```php
// Public API (requires auth:sanctum)
Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    // Recordings
    Route::get('/recordings', [RecordingController::class, 'index']);
    Route::get('/recordings/{recording:uuid}', [RecordingController::class, 'show']);
    Route::get('/recordings/{recording:uuid}/playback-url', [RecordingController::class, 'playbackUrl']);
    Route::delete('/recordings/{recording:uuid}', [RecordingController::class, 'destroy']);

    // Recording Settings
    Route::get('/settings/recording', [RecordingSettingsController::class, 'show']);
    Route::put('/settings/recording', [RecordingSettingsController::class, 'update']);
});

// Internal API (requires internal.api.auth middleware)
Route::middleware(['internal.api.auth'])->prefix('internal')->group(function () {
    Route::post('/recordings/metadata', [InternalRecordingController::class, 'updateMetadata']);
});
```

### 8.6 Middleware

**File**: `app/Http/Middleware/VerifyInternalApiToken.php`

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class VerifyInternalApiToken
{
    public function handle(Request $request, Closure $next)
    {
        $token = $request->bearerToken();
        $expectedToken = config('recording.internal_api_token');

        if (!$token || !hash_equals($expectedToken, $token)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
```

### 8.7 Configuration

**File**: `config/recording.php`

```php
<?php

return [
    'enabled' => env('RECORDING_ENABLED', true),
    'stream_receiver_url' => env('STREAM_RECEIVER_URL', 'ws://stream-receiver:8080'),
    'jwt_secret' => env('RECORDING_JWT_SECRET'),
    'internal_api_token' => env('RECORDING_INTERNAL_API_TOKEN'),
    'default_format' => 'wav',
    'default_retention_days' => 90,
];
```

---

## 9. Frontend Implementation

### 9.1 Components

#### 9.1.1 Recording Settings

**File**: `frontend/src/pages/Settings/RecordingSettings.tsx`

```tsx
import React, { useState, useEffect } from 'react';
import { Card, CardHeader, CardTitle, CardContent } from '@/components/ui/card';
import { Switch } from '@/components/ui/switch';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useToast } from '@/hooks/useToast';
import { api } from '@/lib/api';
import { Mic, HardDrive, Clock } from 'lucide-react';

interface RecordingSettings {
  enabled: boolean;
  format: 'wav' | 'mp3';
  retention_days: number | null;
  auto_delete: boolean;
  max_storage_mb: number | null;
}

export const RecordingSettingsPage: React.FC = () => {
  const [settings, setSettings] = useState<RecordingSettings | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const { toast } = useToast();

  useEffect(() => {
    fetchSettings();
  }, []);

  const fetchSettings = async () => {
    try {
      const response = await api.get('/settings/recording');
      setSettings(response.data.data);
    } catch (error) {
      toast({ title: 'Error loading settings', variant: 'destructive' });
    } finally {
      setIsLoading(false);
    }
  };

  const handleSave = async () => {
    if (!settings) return;

    setIsSaving(true);
    try {
      await api.put('/settings/recording', settings);
      toast({ title: 'Settings saved successfully' });
    } catch (error) {
      toast({ title: 'Error saving settings', variant: 'destructive' });
    } finally {
      setIsSaving(false);
    }
  };

  if (isLoading) {
    return <div>Loading...</div>;
  }

  return (
    <div className="space-y-6">
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Mic className="h-5 w-5" />
            Call Recording
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-6">
          {/* Enable/Disable Toggle */}
          <div className="flex items-center justify-between">
            <div>
              <Label htmlFor="enabled">Enable Call Recording</Label>
              <p className="text-sm text-muted-foreground">
                Automatically record all inbound calls
              </p>
            </div>
            <Switch
              id="enabled"
              checked={settings?.enabled ?? false}
              onCheckedChange={(enabled) =>
                setSettings((prev) => ({ ...prev!, enabled }))
              }
            />
          </div>

          {/* Retention Settings */}
          <div className="space-y-2">
            <Label htmlFor="retention_days" className="flex items-center gap-2">
              <Clock className="h-4 w-4" />
              Retention Period (days)
            </Label>
            <Input
              id="retention_days"
              type="number"
              min="1"
              max="3650"
              placeholder="90 (leave empty for unlimited)"
              value={settings?.retention_days ?? ''}
              onChange={(e) =>
                setSettings((prev) => ({
                  ...prev!,
                  retention_days: e.target.value ? parseInt(e.target.value) : null,
                }))
              }
            />
            <p className="text-sm text-muted-foreground">
              Recordings older than this will be automatically deleted if auto-delete is enabled
            </p>
          </div>

          {/* Auto-delete Toggle */}
          <div className="flex items-center justify-between">
            <div>
              <Label htmlFor="auto_delete">Auto-delete Old Recordings</Label>
              <p className="text-sm text-muted-foreground">
                Automatically delete recordings after retention period expires
              </p>
            </div>
            <Switch
              id="auto_delete"
              checked={settings?.auto_delete ?? false}
              onCheckedChange={(auto_delete) =>
                setSettings((prev) => ({ ...prev!, auto_delete }))
              }
            />
          </div>

          {/* Storage Limit */}
          <div className="space-y-2">
            <Label htmlFor="max_storage_mb" className="flex items-center gap-2">
              <HardDrive className="h-4 w-4" />
              Storage Limit (MB)
            </Label>
            <Input
              id="max_storage_mb"
              type="number"
              min="100"
              placeholder="5000 (leave empty for unlimited)"
              value={settings?.max_storage_mb ?? ''}
              onChange={(e) =>
                setSettings((prev) => ({
                  ...prev!,
                  max_storage_mb: e.target.value ? parseInt(e.target.value) : null,
                }))
              }
            />
          </div>

          {/* Save Button */}
          <div className="flex justify-end">
            <Button onClick={handleSave} disabled={isSaving}>
              {isSaving ? 'Saving...' : 'Save Settings'}
            </Button>
          </div>
        </CardContent>
      </Card>
    </div>
  );
};
```

#### 9.1.2 Recording Player

**File**: `frontend/src/components/recordings/RecordingPlayer.tsx`

```tsx
import React, { useState, useEffect } from 'react';
import { Play, Pause, Download, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { api } from '@/lib/api';
import { useToast } from '@/hooks/useToast';

interface RecordingPlayerProps {
  recordingId: string;
  callId: string;
  duration: string;
  fileSize: string;
  onDelete?: () => void;
}

export const RecordingPlayer: React.FC<RecordingPlayerProps> = ({
  recordingId,
  callId,
  duration,
  fileSize,
  onDelete,
}) => {
  const [isPlaying, setIsPlaying] = useState(false);
  const [playbackUrl, setPlaybackUrl] = useState<string | null>(null);
  const [isLoading, setIsLoading] = useState(false);
  const audioRef = React.useRef<HTMLAudioElement>(null);
  const { toast } = useToast();

  const fetchPlaybackUrl = async () => {
    setIsLoading(true);
    try {
      const response = await api.get(`/recordings/${recordingId}/playback-url`);
      setPlaybackUrl(response.data.url);
    } catch (error) {
      toast({ title: 'Error loading recording', variant: 'destructive' });
    } finally {
      setIsLoading(false);
    }
  };

  const handlePlay = async () => {
    if (!playbackUrl) {
      await fetchPlaybackUrl();
    }

    if (audioRef.current) {
      if (isPlaying) {
        audioRef.current.pause();
      } else {
        audioRef.current.play();
      }
      setIsPlaying(!isPlaying);
    }
  };

  const handleDelete = async () => {
    if (!confirm('Are you sure you want to delete this recording?')) {
      return;
    }

    try {
      await api.delete(`/recordings/${recordingId}`);
      toast({ title: 'Recording deleted' });
      onDelete?.();
    } catch (error) {
      toast({ title: 'Error deleting recording', variant: 'destructive' });
    }
  };

  return (
    <div className="flex items-center gap-2 p-2 border rounded">
      {playbackUrl && (
        <audio
          ref={audioRef}
          src={playbackUrl}
          onEnded={() => setIsPlaying(false)}
          onPause={() => setIsPlaying(false)}
          onPlay={() => setIsPlaying(true)}
        />
      )}

      <Button
        size="sm"
        variant="outline"
        onClick={handlePlay}
        disabled={isLoading}
      >
        {isPlaying ? <Pause className="h-4 w-4" /> : <Play className="h-4 w-4" />}
      </Button>

      <div className="flex-1 text-sm">
        <div className="font-medium">Call {callId}</div>
        <div className="text-muted-foreground">
          {duration} • {fileSize}
        </div>
      </div>

      {playbackUrl && (
        <Button size="sm" variant="ghost" asChild>
          <a href={playbackUrl} download>
            <Download className="h-4 w-4" />
          </a>
        </Button>
      )}

      <Button size="sm" variant="ghost" onClick={handleDelete}>
        <Trash2 className="h-4 w-4 text-destructive" />
      </Button>
    </div>
  );
};
```

#### 9.1.3 Call Logs Integration

**File**: `frontend/src/pages/CallLogs/CallLogs.tsx` (modifications)

```tsx
// Add recording column to table
<TableCell>
  {call.recording ? (
    <RecordingPlayer
      recordingId={call.recording.uuid}
      callId={call.call_id}
      duration={call.recording.formatted_duration}
      fileSize={call.recording.file_size_mb + ' MB'}
      onDelete={() => refetchCalls()}
    />
  ) : (
    <span className="text-muted-foreground text-sm">No recording</span>
  )}
</TableCell>
```

### 9.2 API Client Updates

**File**: `frontend/src/lib/api/recordings.ts`

```typescript
import { api } from './client';

export interface Recording {
  uuid: string;
  call_id: string;
  status: string;
  format: string;
  file_size_bytes: number;
  file_size_mb: number;
  duration_seconds: number;
  formatted_duration: string;
  started_at: string;
  completed_at: string;
}

export const recordingsApi = {
  list: (params?: { status?: string; page?: number; per_page?: number }) =>
    api.get<{ data: Recording[]; meta: any }>('/recordings', { params }),

  get: (uuid: string) =>
    api.get<{ data: Recording }>(`/recordings/${uuid}`),

  getPlaybackUrl: (uuid: string) =>
    api.get<{ url: string; expires_at: string }>(`/recordings/${uuid}/playback-url`),

  delete: (uuid: string) =>
    api.delete(`/recordings/${uuid}`),
};
```

---

## 10. Docker/Podman Configuration

### 10.1 Stream Receiver Dockerfile

**File**: `stream-receiver/Dockerfile`

```dockerfile
FROM node:20-alpine

WORKDIR /app

# Install dependencies
COPY package*.json ./
RUN npm ci --only=production

# Copy source
COPY . .

# Build TypeScript
RUN npm run build

# Expose WebSocket port
EXPOSE 8080

# Health check
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD node -e "require('http').get('http://localhost:8080/health', (r) => process.exit(r.statusCode === 200 ? 0 : 1))"

CMD ["node", "dist/index.js"]
```

### 10.2 Docker Compose Updates

**File**: `docker-compose.yml` (additions)

```yaml
services:
  # ... existing services ...

  stream-receiver:
    build:
      context: ./stream-receiver
      dockerfile: Dockerfile
    container_name: opbx_stream_receiver
    ports:
      - "${STREAM_RECEIVER_PORT:-8080}:8080"
    environment:
      - NODE_ENV=${APP_ENV:-production}
      - STREAM_RECEIVER_PORT=8080
      - JWT_SECRET=${RECORDING_JWT_SECRET}
      - MINIO_ENDPOINT=http://minio:9000
      - MINIO_ACCESS_KEY=${MINIO_ACCESS_KEY:-minioadmin}
      - MINIO_SECRET_KEY=${MINIO_SECRET_KEY:-minioadmin}
      - MINIO_BUCKET=recordings
      - MINIO_REGION=us-east-1
      - REDIS_HOST=redis
      - REDIS_PORT=6379
      - REDIS_PASSWORD=${REDIS_PASSWORD}
      - LARAVEL_INTERNAL_API_URL=http://app/api/internal
      - LARAVEL_INTERNAL_API_TOKEN=${RECORDING_INTERNAL_API_TOKEN}
      - LOG_LEVEL=info
    depends_on:
      - redis
      - minio
      - app
    networks:
      - opbx
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "node", "-e", "require('http').get('http://localhost:8080/health', (r) => process.exit(r.statusCode === 200 ? 0 : 1))"]
      interval: 30s
      timeout: 5s
      retries: 3

  # ... existing services ...
```

### 10.3 Nginx Configuration (WebSocket Proxy)

**File**: `docker/nginx/conf.d/default.conf` (additions)

```nginx
# WebSocket proxy for stream receiver
location /stream {
    proxy_pass http://stream-receiver:8080;
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    
    # Timeouts for long-lived connections
    proxy_connect_timeout 7d;
    proxy_send_timeout 7d;
    proxy_read_timeout 7d;
}
```

### 10.4 Environment Variables

**File**: `.env.example` (additions)

```env
# ═══════════════════════════════════════════════════════════
# CALL RECORDING
# ═══════════════════════════════════════════════════════════
RECORDING_ENABLED=true
STREAM_RECEIVER_PORT=8080
STREAM_RECEIVER_URL=wss://your-domain.com/stream

# JWT secret for stream authentication (shared between Laravel and stream receiver)
RECORDING_JWT_SECRET=                 # Generate: openssl rand -base64 64

# Internal API token for stream receiver → Laravel communication
RECORDING_INTERNAL_API_TOKEN=         # Generate: openssl rand -base64 32
```

### 10.5 Deployment Commands

```bash
# Use "docker compose" (Podman-compatible)

# Build and start all services
docker compose up -d --build

# Start only stream receiver
docker compose up -d stream-receiver

# View stream receiver logs
docker compose logs -f stream-receiver

# Restart after code changes
docker compose restart stream-receiver

# Health check
docker compose ps stream-receiver

# Scale stream receivers (horizontal scaling)
docker compose up -d --scale stream-receiver=3
```

---

## 11. Security & Access Control

### 11.1 Authentication Layers

| Layer | Method | Validation |
|-------|--------|-----------|
| Frontend → Laravel | Laravel Sanctum | Bearer token from cookies/localStorage |
| Laravel → Stream Receiver | JWT | Signed token with call_id + org_id |
| Stream Receiver → Laravel | Internal API Token | Shared secret in Authorization header |
| User → MinIO Playback | Pre-signed URL | Temporary URL with expiration (15 min) |

### 11.2 Authorization Matrix

See section 5.2 (Access Control Matrix)

### 11.3 Data Protection

#### Encryption at Rest
- **MinIO**: Enable server-side encryption (SSE-S3)
  ```bash
  # Set in MinIO environment
  MINIO_SERVER_SIDE_ENCRYPTION_ENABLED=true
  ```

#### Encryption in Transit
- **WebSocket**: Use WSS (TLS) in production
- **MinIO**: Access via HTTPS only
- **Internal API**: Use internal Docker network (no external exposure)

### 11.4 Audit Logging

**Required Audit Events**:
```php
// In RecordingService
Log::info('Recording started', [
    'call_id' => $callId,
    'organization_id' => $organizationId,
    'user_id' => auth()->id(),
    'timestamp' => now(),
]);

Log::info('Recording played', [
    'recording_id' => $recording->uuid,
    'call_id' => $recording->call_id,
    'organization_id' => $recording->organization_id,
    'user_id' => auth()->id(),
    'timestamp' => now(),
]);

Log::warning('Recording deleted', [
    'recording_id' => $recording->uuid,
    'call_id' => $recording->call_id,
    'organization_id' => $recording->organization_id,
    'user_id' => auth()->id(),
    'timestamp' => now(),
]);
```

### 11.5 Rate Limiting

```php
// routes/api.php
Route::middleware(['auth:sanctum', 'throttle:recording-playback'])->group(function () {
    Route::get('/recordings/{recording:uuid}/playback-url', [RecordingController::class, 'playbackUrl']);
});

// app/Providers/RouteServiceProvider.php
RateLimiter::for('recording-playback', function (Request $request) {
    return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
});
```

---

## 12. Error Handling & Edge Cases

### 12.1 Race Conditions

#### Scenario 1: Duplicate Stream Connections

**Problem**: Cloudonix retries webhook, two streams open for same call  
**Solution**:
```typescript
// In StreamHandler.initialize()
const lockAcquired = await this.redisService.acquireLock(
  `lock:recording:${callId}`,
  30000 // 30 second timeout
);

if (!lockAcquired) {
  throw new Error('Recording already in progress');
}
```

#### Scenario 2: Out-of-Order Events

**Problem**: "completed" event arrives before "started" event  
**Solution**:
```php
// In InternalRecordingController
if ($validated['status'] === 'completed' && $recording->status === 'pending') {
    // Automatically transition pending → recording → completed
    $recording->update(['status' => 'recording', 'started_at' => now()]);
    $recording->update(['status' => 'completed', 'completed_at' => $validated['completed_at']]);
}
```

### 12.2 Network Failures

#### Scenario 1: WebSocket Disconnect Mid-Call

**Solution**:
```typescript
// In StreamHandler
ws.on('close', async (code, reason) => {
  if (code !== 1000) { // Not normal closure
    logger.warn('Abnormal WebSocket close', { callId, code, reason });
    
    // Mark as failed if recording was active
    if (session.bytesWritten > 0) {
      await this.metadataService.updateStatus(callId, 'failed', {
        error: `WebSocket closed abnormally (code: ${code})`,
      });
    }
  }
});
```

#### Scenario 2: MinIO Unavailable

**Solution**:
```typescript
// In StorageService
try {
  await this.s3Client.send(command);
} catch (error) {
  if (error.name === 'ServiceUnavailable' || error.name === 'RequestTimeout') {
    // Retry with exponential backoff
    await this.retryWithBackoff(() => this.s3Client.send(command), 3);
  } else {
    throw error;
  }
}
```

### 12.3 Storage Issues

#### Scenario 1: Disk Full

**Solution**:
```php
// Laravel scheduled job: Check storage usage
Schedule::command('recordings:check-storage')->hourly();

// Command implementation
public function handle()
{
    $totalSize = CallRecording::where('status', 'completed')->sum('file_size_bytes');
    $maxSize = config('recording.max_storage_bytes');
    
    if ($totalSize > $maxSize * 0.9) {
        // Alert admins
        Notification::send($admins, new StorageAlmostFullNotification($totalSize, $maxSize));
        
        // Auto-delete oldest recordings if enabled
        if (config('recording.auto_delete_on_full')) {
            $this->deleteOldestRecordings();
        }
    }
}
```

#### Scenario 2: Orphaned Files

**Problem**: Recording in DB but file missing in MinIO (or vice versa)  
**Solution**:
```php
// Laravel scheduled job: Reconcile storage
Schedule::command('recordings:reconcile')->daily();

// Command implementation
public function handle()
{
    // Find recordings in DB with missing files
    $recordings = CallRecording::where('status', 'completed')->get();
    
    foreach ($recordings as $recording) {
        if (!Storage::disk('s3')->exists($recording->storage_path)) {
            $recording->update(['status' => 'failed', 'error' => 'File not found in storage']);
        }
    }
    
    // Find orphaned files in MinIO (future enhancement)
    // ...
}
```

### 12.4 Concurrent Recording Limits

**Solution**:
```typescript
// In StreamHandler
private readonly MAX_CONCURRENT_STREAMS = 100;

async initialize(...) {
  const activeCount = this.sessions.size;
  
  if (activeCount >= this.MAX_CONCURRENT_STREAMS) {
    throw new Error(`Maximum concurrent streams reached (${this.MAX_CONCURRENT_STREAMS})`);
  }
  
  // ... proceed with initialization
}
```

### 12.5 Idempotency

**Webhook Retry Protection**:
```php
// In InternalRecordingController
$idempotencyKey = $request->header('X-Idempotency-Key');

if ($idempotencyKey) {
    $cached = Cache::get("recording:idem:{$idempotencyKey}");
    
    if ($cached) {
        return response()->json(['message' => 'Already processed'], 200);
    }
    
    Cache::put("recording:idem:{$idempotencyKey}", true, 3600);
}
```

---

## 13. Testing Strategy

### 13.1 Unit Tests

#### Laravel Tests

**File**: `tests/Unit/Services/RecordingServiceTest.php`

```php
<?php

namespace Tests\Unit\Services;

use App\Models\CallRecording;
use App\Models\Organization;
use App\Services\RecordingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_generates_stream_url_with_valid_jwt()
    {
        $service = new RecordingService();
        $url = $service->generateStreamUrl('call-123', 42);
        
        $this->assertStringContainsString('/stream/', $url);
        
        // Decode JWT and verify payload
        $token = substr($url, strrpos($url, '/') + 1);
        $payload = \Firebase\JWT\JWT::decode($token, config('recording.jwt_secret'), ['HS256']);
        
        $this->assertEquals('call-123', $payload->call_id);
        $this->assertEquals(42, $payload->organization_id);
    }

    public function test_creates_pending_recording()
    {
        $org = Organization::factory()->create();
        $service = new RecordingService();
        
        $recording = $service->createPendingRecording('call-123', $org->id);
        
        $this->assertEquals('pending', $recording->status);
        $this->assertEquals('call-123', $recording->call_id);
        $this->assertEquals($org->id, $recording->organization_id);
    }
}
```

#### Node.js Tests

**File**: `stream-receiver/tests/unit/audio.test.ts`

```typescript
import { describe, it, expect } from 'vitest';
import { AudioService } from '../../src/services/audio.service';

describe('AudioService', () => {
  const audioService = new AudioService();

  it('decodes mu-law to PCM', () => {
    const muLawData = Buffer.from([0x00, 0xFF, 0x80]);
    const pcmData = audioService.decodeMuLaw(muLawData);
    
    expect(pcmData).toBeInstanceOf(Buffer);
    expect(pcmData.length).toBe(muLawData.length * 2); // 16-bit PCM
  });

  it('writes WAV header', async () => {
    const chunks: Buffer[] = [];
    const mockStream = {
      write: (chunk: Buffer) => chunks.push(chunk),
    };
    
    await audioService.writeWavHeader(mockStream as any);
    
    const header = Buffer.concat(chunks);
    expect(header.length).toBe(44); // WAV header size
    expect(header.toString('ascii', 0, 4)).toBe('RIFF');
    expect(header.toString('ascii', 8, 12)).toBe('WAVE');
  });
});
```

### 13.2 Integration Tests

**File**: `tests/Feature/RecordingApiTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\CallRecording;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_own_organization_recordings()
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        
        $user = User::factory()->for($org1)->create(['role' => 'admin']);
        
        CallRecording::factory()->for($org1)->count(5)->create();
        CallRecording::factory()->for($org2)->count(3)->create();
        
        $response = $this->actingAs($user)->getJson('/api/v1/recordings');
        
        $response->assertOk();
        $response->assertJsonCount(5, 'data');
    }

    public function test_cannot_view_other_organization_recording()
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        
        $userOrg1 = User::factory()->for($org1)->create(['role' => 'admin']);
        $recordingOrg2 = CallRecording::factory()->for($org2)->create();
        
        $response = $this->actingAs($userOrg1)
                         ->getJson("/api/v1/recordings/{$recordingOrg2->uuid}");
        
        $response->assertForbidden();
    }

    public function test_agent_can_only_view_own_call_recordings()
    {
        $org = Organization::factory()->create();
        $agent = User::factory()->for($org)->create(['role' => 'agent']);
        
        $ownRecording = CallRecording::factory()->for($org)->create([
            'call_id' => 'call-123',
        ]);
        $otherRecording = CallRecording::factory()->for($org)->create([
            'call_id' => 'call-456',
        ]);
        
        // Mock callLog relationship to return agent's extension for ownRecording
        // ...
        
        $response = $this->actingAs($agent)
                         ->getJson("/api/v1/recordings/{$ownRecording->uuid}");
        $response->assertOk();
        
        $response = $this->actingAs($agent)
                         ->getJson("/api/v1/recordings/{$otherRecording->uuid}");
        $response->assertForbidden();
    }

    public function test_can_generate_playback_url()
    {
        $org = Organization::factory()->create();
        $user = User::factory()->for($org)->create(['role' => 'admin']);
        $recording = CallRecording::factory()->for($org)->create([
            'status' => 'completed',
            'storage_path' => '42/2026/02/call-123.wav',
        ]);
        
        $response = $this->actingAs($user)
                         ->getJson("/api/v1/recordings/{$recording->uuid}/playback-url");
        
        $response->assertOk();
        $response->assertJsonStructure(['url', 'expires_at']);
    }
}
```

### 13.3 Multi-Tenant Isolation Tests

**File**: `tests/Feature/MultiTenantRecordingTest.php`

```php
<?php

namespace Tests\Feature;

use App\Models\CallRecording;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MultiTenantRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_storage_paths_include_organization_id()
    {
        $org = Organization::factory()->create(['id' => 42]);
        $recording = CallRecording::factory()->for($org)->create();
        
        $this->assertTrue(str_starts_with($recording->storage_path, '42/'));
    }

    public function test_global_scope_filters_by_organization()
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        
        CallRecording::factory()->for($org1)->count(5)->create();
        CallRecording::factory()->for($org2)->count(3)->create();
        
        $user = User::factory()->for($org1)->create();
        
        $this->actingAs($user);
        $this->assertEquals(5, CallRecording::count());
    }

    public function test_cannot_delete_other_organization_recording()
    {
        $org1 = Organization::factory()->create();
        $org2 = Organization::factory()->create();
        
        $userOrg1 = User::factory()->for($org1)->create(['role' => 'admin']);
        $recordingOrg2 = CallRecording::factory()->for($org2)->create();
        
        $response = $this->actingAs($userOrg1)
                         ->deleteJson("/api/v1/recordings/{$recordingOrg2->uuid}");
        
        $response->assertForbidden();
        $this->assertDatabaseHas('call_recordings', ['uuid' => $recordingOrg2->uuid]);
    }
}
```

### 13.4 Load Testing

**File**: `tests/load/stream-receiver.js` (using k6)

```javascript
import ws from 'k6/ws';
import { check } from 'k6';

export const options = {
  stages: [
    { duration: '1m', target: 50 },  // Ramp up to 50 concurrent streams
    { duration: '5m', target: 50 },  // Stay at 50
    { duration: '1m', target: 100 }, // Ramp up to 100
    { duration: '5m', target: 100 }, // Stay at 100
    { duration: '1m', target: 0 },   // Ramp down
  ],
};

export default function () {
  const url = 'ws://localhost:8080/stream/test-token';
  
  const res = ws.connect(url, {}, function (socket) {
    socket.on('open', () => {
      // Send mu-law audio chunks (simulated)
      const interval = setInterval(() => {
        socket.send(new Uint8Array(160).buffer); // 20ms of audio at 8kHz
      }, 20);

      socket.setTimeout(() => {
        clearInterval(interval);
        socket.close();
      }, 60000); // 1 minute call
    });

    socket.on('error', (e) => {
      console.error('WebSocket error:', e);
    });
  });

  check(res, { 'status is 101': (r) => r && r.status === 101 });
}
```

---

## 14. Implementation Phases

### Phase 1: Database & Models (Week 1, Days 1-2)

**Goal**: Complete database schema and Laravel models with multi-tenant isolation

**Tasks**:
1. Create migrations for `call_recordings` and `recording_settings` tables
2. Create `CallRecording` model with global scope and policies
3. Create `RecordingSettings` model
4. Write unit tests for models and policies
5. Create factories and seeders for testing

**Git Commits**:
```
feat(recording): add call_recordings migration with organization_id
feat(recording): add recording_settings migration
feat(recording): add CallRecording model with global scope
feat(recording): add RecordingPolicy with RBAC rules
feat(recording): add RecordingSettings model
test(recording): add model and policy unit tests
```

**Validation**:
- [ ] All migrations run successfully
- [ ] Global scope filters by organization_id
- [ ] Policies enforce RBAC rules
- [ ] All unit tests pass

---

### Phase 2: Stream Receiver Core (Week 1-2, Days 3-7)

**Goal**: Build standalone Node.js WebSocket server for audio streaming

**Tasks**:
1. Initialize Node.js project with TypeScript
2. Implement WebSocket server with JWT authentication
3. Implement AudioService (mu-law → PCM → WAV)
4. Implement StorageService (MinIO uploads)
5. Implement RedisService (locks + session state)
6. Implement MetadataService (Laravel API calls)
7. Write unit tests for all services
8. Create Dockerfile and docker-compose integration
9. Add Nginx WebSocket proxy configuration

**Git Commits**:
```
feat(recording): initialize stream-receiver Node.js project
feat(recording): add WebSocket server with JWT auth
feat(recording): add AudioService with mu-law decoding
feat(recording): add StorageService for MinIO uploads
feat(recording): add RedisService for distributed locks
feat(recording): add MetadataService for Laravel API calls
feat(recording): add StreamHandler with session management
test(recording): add stream-receiver unit tests
chore(recording): add stream-receiver Dockerfile
chore(recording): integrate stream-receiver into docker-compose
chore(recording): add Nginx WebSocket proxy config
```

**Validation**:
- [ ] Stream receiver accepts WebSocket connections
- [ ] JWT tokens validated correctly
- [ ] Audio chunks processed and written to MinIO
- [ ] Redis locks prevent race conditions
- [ ] All unit tests pass

---

### Phase 3: Storage & API (Week 2, Days 8-10)

**Goal**: Implement Laravel backend APIs and storage integration

**Tasks**:
1. Create `RecordingService` with playback URL generation
2. Create `RecordingController` (list, show, playback, delete)
3. Create `RecordingSettingsController` (get, update)
4. Create `InternalRecordingController` (metadata updates from stream receiver)
5. Create API routes with authentication middleware
6. Create `VerifyInternalApiToken` middleware
7. Add configuration file `config/recording.php`
8. Write integration tests for all endpoints

**Git Commits**:
```
feat(recording): add RecordingService with MinIO integration
feat(recording): add RecordingController for public API
feat(recording): add RecordingSettingsController
feat(recording): add InternalRecordingController for stream receiver
feat(recording): add API routes with authentication
feat(recording): add VerifyInternalApiToken middleware
feat(recording): add recording configuration file
test(recording): add API integration tests
test(recording): add multi-tenant isolation tests
```

**Validation**:
- [ ] All API endpoints return correct data
- [ ] Pre-signed URLs generated successfully
- [ ] Multi-tenant isolation enforced
- [ ] Internal API secured with token
- [ ] All integration tests pass

---

### Phase 4: Voice Routing (Week 3, Days 11-14)

**Goal**: Integrate recording into CXML voice routing

**Tasks**:
1. Update `VoiceRoutingService` to inject `<Connect><Stream>` when enabled
2. Create CXML builder method for Stream directive
3. Update voice routing webhook to create pending recordings
4. Add recording status to call logs
5. Test end-to-end call flow with recording
6. Handle recording failures gracefully
7. Add audit logging for recording events

**Git Commits**:
```
feat(recording): inject Stream directive in voice routing CXML
feat(recording): add CXML builder for Stream directive
feat(recording): create pending recordings on call initiation
feat(recording): add recording status to call logs
feat(recording): add error handling for recording failures
feat(recording): add audit logging for recording events
test(recording): add voice routing integration tests
```

**Validation**:
- [ ] CXML includes `<Connect><Stream>` when enabled
- [ ] Cloudonix connects to stream receiver
- [ ] Recordings created and stored successfully
- [ ] Call logs show recording status
- [ ] Failures handled gracefully

---

### Phase 5: Frontend & Polish (Week 3-4, Days 15-21)

**Goal**: Complete frontend UI and final polish

**Tasks**:
1. Create `RecordingSettingsPage` component
2. Create `RecordingPlayer` component
3. Update `CallLogs` page to show recordings
4. Create `recordingsApi` client
5. Add WebSocket events for real-time updates
6. Add recording indicators during active calls
7. Write frontend tests (Vitest + React Testing Library)
8. Update documentation
9. Create deployment guide
10. Final end-to-end testing

**Git Commits**:
```
feat(recording): add RecordingSettings page component
feat(recording): add RecordingPlayer component
feat(recording): integrate recordings into CallLogs page
feat(recording): add recordings API client
feat(recording): add WebSocket real-time updates
feat(recording): add recording indicators for active calls
test(recording): add frontend component tests
docs(recording): add feature documentation
docs(recording): add deployment guide
test(recording): complete end-to-end testing
```

**Validation**:
- [ ] Settings page allows enable/disable recording
- [ ] Call logs show playback buttons
- [ ] Audio playback works correctly
- [ ] Real-time updates show recording status
- [ ] All frontend tests pass
- [ ] Documentation complete

---

## 15. Deployment Checklist

### 15.1 Pre-Deployment

- [ ] All tests pass (unit, integration, multi-tenant, load)
- [ ] Code reviewed and approved
- [ ] Documentation updated
- [ ] Environment variables configured
- [ ] Secrets generated and stored securely

### 15.2 Deployment Steps

1. **Generate Secrets**:
   ```bash
   # JWT secret (shared between Laravel and stream receiver)
   openssl rand -base64 64
   
   # Internal API token
   openssl rand -base64 32
   ```

2. **Update Environment Variables**:
   ```bash
   # Add to .env
   RECORDING_ENABLED=true
   RECORDING_JWT_SECRET=<generated-secret>
   RECORDING_INTERNAL_API_TOKEN=<generated-token>
   STREAM_RECEIVER_URL=wss://your-domain.com/stream
   ```

3. **Build and Deploy Containers**:
   ```bash
   # Use docker compose (Podman-compatible)
   docker compose build stream-receiver
   docker compose up -d stream-receiver
   ```

4. **Run Migrations**:
   ```bash
   docker compose exec app php artisan migrate --force
   ```

5. **Verify MinIO Bucket**:
   ```bash
   # Check if recordings bucket exists
   docker compose exec minio mc ls minio/recordings
   
   # Create if not exists
   docker compose exec minio mc mb minio/recordings
   ```

6. **Test WebSocket Connection**:
   ```bash
   # Test WebSocket proxy
   wscat -c wss://your-domain.com/stream/test-token
   ```

7. **Enable Recording for Test Organization**:
   ```bash
   docker compose exec app php artisan tinker
   >>> $settings = RecordingSettings::forOrganization(1);
   >>> $settings->update(['enabled' => true]);
   ```

8. **Test End-to-End Call**:
   - Make a test call to a DID
   - Verify recording appears in call logs
   - Test playback

9. **Monitor Logs**:
   ```bash
   docker compose logs -f stream-receiver
   docker compose logs -f app
   ```

### 15.3 Post-Deployment

- [ ] Test recording creation
- [ ] Test playback
- [ ] Verify multi-tenant isolation
- [ ] Check storage usage
- [ ] Monitor error rates
- [ ] Verify WebSocket connections stable
- [ ] Test recording deletion

### 15.4 Rollback Plan

If deployment fails:
```bash
# Stop stream receiver
docker compose stop stream-receiver

# Disable recording in settings
docker compose exec app php artisan tinker
>>> RecordingSettings::query()->update(['enabled' => false]);

# Rollback migrations (if necessary)
docker compose exec app php artisan migrate:rollback --step=2
```

---

## 16. Monitoring & Operations

### 16.1 Metrics to Monitor

| Metric | Threshold | Action |
|--------|-----------|--------|
| Active streams | > 80% of max capacity | Scale horizontally |
| Stream errors | > 5% | Investigate logs |
| Storage usage | > 90% of quota | Clean old recordings |
| Recording failures | > 2% | Check Cloudonix connectivity |
| MinIO latency | > 1s | Check storage performance |
| WebSocket disconnects | > 10% | Check network/proxy config |

### 16.2 Logging Strategy

**Structured Logging Format**:
```json
{
  "timestamp": "2026-02-10T12:34:56Z",
  "level": "info",
  "service": "stream-receiver",
  "call_id": "call-abc123",
  "organization_id": 42,
  "event": "stream_started",
  "metadata": {
    "storage_path": "42/2026/02/call-abc123.wav",
    "duration_seconds": 0,
    "bytes_written": 0
  }
}
```

**Key Log Events**:
- `stream_connected`: WebSocket opened
- `stream_started`: Recording initialized
- `stream_chunk_processed`: Audio chunk written
- `stream_completed`: Recording finalized
- `stream_failed`: Error occurred
- `playback_url_generated`: User requested playback
- `recording_deleted`: User deleted recording

### 16.3 Alerts

**Critical Alerts** (PagerDuty/Slack):
- Stream receiver service down
- MinIO unavailable
- Recording failure rate > 10%
- Storage quota exceeded

**Warning Alerts** (Email):
- Storage usage > 80%
- Active streams > 70% of capacity
- Recording failure rate > 5%

### 16.4 Operational Runbooks

#### Runbook 1: High Recording Failure Rate

**Symptoms**: Recording failure rate > 5%  
**Investigation**:
1. Check stream receiver logs: `docker compose logs stream-receiver | grep ERROR`
2. Check MinIO health: `docker compose ps minio`
3. Check Redis connectivity: `docker compose exec redis redis-cli ping`
4. Check Laravel logs: `docker compose logs app | grep recording`

**Resolution**:
- If MinIO down: `docker compose restart minio`
- If Redis down: `docker compose restart redis`
- If stream receiver overloaded: Scale horizontally

#### Runbook 2: Storage Quota Exceeded

**Symptoms**: New recordings fail with "storage full" error  
**Investigation**:
1. Check total storage: `SELECT SUM(file_size_bytes) FROM call_recordings WHERE status='completed'`
2. Check oldest recordings: `SELECT * FROM call_recordings ORDER BY created_at LIMIT 100`

**Resolution**:
1. Enable auto-delete: Update `recording_settings.auto_delete = true`
2. Manually delete old recordings: `php artisan recordings:cleanup --days=90`
3. Increase storage quota (if available)

---

## 17. Approved Decisions & Requirements

### 17.1 Audio Format & Transcoding ✅

**Decision**: 
- **MVP**: WAV (uncompressed, simple)
- **Future**: MP3 (compressed, smaller files)
- **Transcoding Strategy**: Implement as Laravel queue post-processing job

**Implementation**:
- Stream receiver writes WAV files directly
- After recording completion, queue job `TranscodeRecordingToMp3` runs asynchronously
- Keep both WAV (archival) and MP3 (playback) versions
- MP3 transcoding uses FFmpeg in a separate worker container

---

### 17.2 Storage Lifecycle & Tiering ✅

**Hot/Cold Storage**: **YES** - Implement tiered storage

**Policy**:
1. **Hot Storage** (0-6 months): Recordings stored in MinIO primary bucket
   - Path: `recordings/{org_id}/{year}/{month}/{call_id}.wav`
   - Fast retrieval, SSD-backed

2. **Cold Storage** (6+ months): Automatically moved to cold tier
   - Path: `recordings-archive/{org_id}/{year}/{month}/{call_id}.wav`
   - Slower retrieval, HDD-backed or external S3

**Implementation**:
- Cron job runs daily: `php artisan recordings:archive-old`
- Moves recordings older than 6 months to cold storage
- Updates `recordings.storage_tier` column: `hot` → `cold`
- Playback URLs adapt based on tier (pre-signed URL expiration varies)

**Glacier/Long-Term Archival**: **NOT NOW**
- Leave interface abstraction for future storage backends
- Design `StorageDriverInterface` to support S3 Glacier later

---

### 17.3 Selective Recording Configuration ✅

**Recording can be enabled at multiple levels** (priority order):

1. **Phone Numbers** (DID) - **HIGHEST PRIORITY**
   - Table: `recording_settings` (polymorphic: `recordable_type` = `DidNumber`)
   - If DID has recording enabled, it supersedes all other settings

2. **Extensions**
   - Table: `recording_settings` (polymorphic: `recordable_type` = `Extension`)

3. **Ring Groups**
   - Table: `recording_settings` (polymorphic: `recordable_type` = `RingGroup`)

4. **AI Assistants**
   - Table: `recording_settings` (polymorphic: `recordable_type` = `AiAssistant`)

5. **Conference Rooms**
   - Table: `recording_settings` (polymorphic: `recordable_type` = `ConferenceRoom`)

**Call Types Supported**:
- ✅ Inbound calls to phone numbers
- ✅ Inbound calls to extensions
- ✅ Inbound calls to ring groups
- ✅ Inbound calls to AI Assistants
- ✅ Inbound calls to Conference Rooms
- ✅ Outbound calls from extensions

**Resolution Logic**:
```php
// Voice routing determines if recording should start
function shouldRecordCall(SessionUpdate $session): bool {
    // 1. Check DID (highest priority)
    if ($did = $session->didNumber) {
        $settings = RecordingSettings::forEntity($did)->first();
        if ($settings && $settings->enabled) {
            return true; // DID recording supersedes all
        }
    }
    
    // 2. Check extension
    if ($extension = $session->extension) {
        $settings = RecordingSettings::forEntity($extension)->first();
        if ($settings && $settings->enabled) {
            return true;
        }
    }
    
    // 3. Check ring group (if call routed to ring group)
    // ... similar logic
    
    return false; // No recording configured
}
```

---

### 17.4 Legal Compliance ✅

**Recording Announcement**: **NO**
- No automated "beep" tone or announcement required
- Handled by inbound IVR (customer configures their own announcement)

**Retention Policies**: **NOT NOW**
- GDPR/CCPA compliance requirements TBD
- Will be defined in future phase
- Storage lifecycle (hot/cold) serves as interim retention mechanism

**Consent Tracking**: **NOT NOW**
- Not implemented in MVP
- Future enhancement may track consent per-call

---

### 17.5 Call Scenarios ✅

**Outbound Calls**: **INCLUDED IN SCOPE**
- Outbound calls from extensions can be recorded
- Same recording settings apply (check extension config)

**Conference Recording**: **CONTINUOUS RECORDING**
- Conference rooms support recording (enabled per-room)
- Single WAV file captures all participants (mixed audio)

**Call Transfer**: **SINGLE CONTINUOUS RECORDING**
- If call is transferred (blind/attended), recording continues
- One WAV file spans entire call lifecycle (initial + transfer)
- Stream receiver tracks same `streamSid` through transfer

**Voicemail Recording**: **NOT APPLICABLE**
- OpBX does not support voicemail functionality (yet)

---

### 17.6 Cloudonix Integration Confirmations ✅

**Stream Format**: **CONFIRMED**
- Codec: `audio/x-mulaw` (μ-law, G.711)
- Sample Rate: `8000 Hz`
- Channels: `1` (mono)

**Retry Policy**: **FAIL-FAST**
- If WebSocket connection to stream receiver fails, **Cloudonix will fail the call**
- Stream receiver MUST be highly available
- Implement health checks and auto-restart (Docker/Podman)

**Stream Metadata**: **YES - INCLUDES TIMESTAMPS & SEQUENCE**
From Cloudonix documentation:
- Each `media` message includes:
  - `sequenceNumber`: Monotonically increasing per message
  - `chunk`: Per-track chunk sequence (starts at 1)
  - `timestamp`: Presentation timestamp in milliseconds from stream start
- Use `timestamp` for accurate WAV frame timing
- Use `sequenceNumber` to detect dropped packets

---

## 18. Revised Implementation Plan

Based on approved decisions, the implementation is split into **6 focused phases**:

---

### Phase 1: Database Schema & Core Models (3-4 days)

**Scope**: Foundation for multi-entity recording configuration

**Tasks**:
1. Create `call_recordings` table migration
2. Create `recording_settings` table migration (polymorphic)
3. Add `storage_tier` enum to `recordings` table
4. Create `CallRecording` model with policies
5. Create `RecordingSettings` model with polymorphic relationships
6. Write unit tests for models

**Deliverables**:
- ✅ Migrations run successfully
- ✅ Polymorphic relationships work (DID, Extension, RingGroup, AiAssistant, ConferenceRoom)
- ✅ Multi-tenant isolation via global scopes
- ✅ Policy enforcement for RBAC

**Git Commits**:
```
feat(recording): add call_recordings table with storage_tier
feat(recording): add recording_settings polymorphic table
feat(recording): add CallRecording model with organization scope
feat(recording): add RecordingSettings model with polymorphic support
test(recording): add model unit tests with multi-tenant validation
```

---

### Phase 2: Stream Receiver Service - Core (5-7 days)

**Scope**: Standalone Node.js WebSocket server

**Tasks**:
1. Initialize TypeScript project with dependencies
2. Implement WebSocket server (port 6001)
3. Implement Cloudonix protocol handler (connected/start/media/stop/dtmf)
4. Implement mulaw → PCM decoder
5. Implement WAV file writer (chunked I/O)
6. Implement session manager (track active streams)
7. Implement MinIO S3 uploader
8. Write unit tests for audio processing

**Deliverables**:
- ✅ WebSocket server accepts connections
- ✅ Audio frames decoded and written to WAV
- ✅ Metadata (timestamp, sequenceNumber) tracked
- ✅ MinIO uploads work
- ✅ Unit tests pass

**Git Commits**:
```
feat(recording): initialize stream-receiver TypeScript project
feat(recording): add WebSocket server with Cloudonix protocol
feat(recording): add mulaw to PCM audio decoder
feat(recording): add WAV file writer with chunked I/O
feat(recording): add session manager for active recordings
feat(recording): add MinIO S3 uploader service
test(recording): add audio processor unit tests
```

---

### Phase 3: Stream Receiver - Laravel Integration (4-5 days)

**Scope**: Connect stream receiver to Laravel backend

**Tasks**:
1. Create internal API endpoints (create/update/finalize recording)
2. Create `VerifyInternalApiToken` middleware
3. Implement Laravel API client in stream receiver
4. Implement Redis locks for race condition prevention
5. Add WebSocket authentication (validate org_id from JWT)
6. Docker/Podman configuration (docker-compose, Nginx proxy)
7. Write integration tests (mock WebSocket flow)

**Deliverables**:
- ✅ Stream receiver calls Laravel internal API
- ✅ JWT tokens validated (org_id verified)
- ✅ Redis locks prevent concurrent write conflicts
- ✅ Docker containers communicate correctly
- ✅ Integration tests pass

**Git Commits**:
```
feat(recording): add Laravel internal API controller
feat(recording): add VerifyInternalApiToken middleware
feat(recording): add Laravel API client in stream receiver
feat(recording): add Redis distributed locks
feat(recording): add JWT authentication for WebSocket
chore(recording): add stream-receiver to docker-compose
chore(recording): add Nginx WebSocket proxy configuration
test(recording): add stream receiver integration tests
```

---

### Phase 4: Voice Routing & CXML Integration (4-5 days)

**Scope**: Inject `<Start><Stream>` into call flow

**Tasks**:
1. Create `RecordingService` with resolution logic (DID > Extension > RingGroup...)
2. Update `VoiceRoutingService` to check recording settings
3. Create CXML builder for `<Start><Stream>` directive
4. Generate JWT tokens for WebSocket authentication
5. Handle recording for outbound calls
6. Add recording status to `SessionUpdate` model
7. Write end-to-end tests (with Cloudonix mock)

**Deliverables**:
- ✅ CXML includes `<Start><Stream>` when recording enabled
- ✅ Priority resolution works (DID supersedes extension)
- ✅ JWT tokens include org_id and call metadata
- ✅ Outbound calls recorded correctly
- ✅ End-to-end tests pass

**Git Commits**:
```
feat(recording): add RecordingService with priority resolution
feat(recording): inject Stream directive in voice routing CXML
feat(recording): add CXML builder for Stream directive
feat(recording): generate JWT tokens for WebSocket auth
feat(recording): support outbound call recording
feat(recording): add recording status to SessionUpdate
test(recording): add voice routing integration tests
```

---

### Phase 5: Frontend UI & Playback (5-6 days)

**Scope**: User-facing recording management

**Tasks**:
1. Add recording toggle to DID form
2. Add recording toggle to Extension form
3. Add recording toggle to RingGroup form
4. Add recording toggle to AiAssistant form
5. Add recording toggle to ConferenceRoom form
6. Create `CallRecordingsPage` (list, filter, search)
7. Create `AudioPlayer` component with waveform
8. Update `CallLogs` page to show recording links
9. Add real-time recording indicator (Live Calls)
10. Write frontend component tests

**Deliverables**:
- ✅ Recording can be enabled/disabled per entity
- ✅ Call logs show playback button
- ✅ Audio playback works (pre-signed URLs)
- ✅ Real-time recording status visible
- ✅ Frontend tests pass

**Git Commits**:
```
feat(recording): add recording toggle to DID form
feat(recording): add recording toggle to Extension form
feat(recording): add recording toggle to RingGroup form
feat(recording): add recording toggle to AiAssistant form
feat(recording): add recording toggle to ConferenceRoom form
feat(recording): create CallRecordingsPage with filters
feat(recording): add AudioPlayer component with waveform
feat(recording): integrate recordings into CallLogs page
feat(recording): add real-time recording indicators
test(recording): add frontend component tests
```

---

### Phase 6: Post-Processing & Storage Lifecycle (4-5 days)

**Scope**: MP3 transcoding and hot/cold storage tiering

**Tasks**:
1. Create `TranscodeRecordingToMp3` queue job (uses FFmpeg)
2. Add FFmpeg to Docker image
3. Create `ArchiveOldRecordingsCommand` (moves to cold storage)
4. Update playback URLs to handle hot/cold tiers
5. Add `storage_tier` column to `recordings` table
6. Create `StorageDriverInterface` abstraction
7. Write tests for transcoding and archival

**Deliverables**:
- ✅ WAV files auto-transcoded to MP3 after upload
- ✅ Recordings > 6 months moved to cold storage
- ✅ Playback adapts to storage tier
- ✅ Storage driver abstraction ready for future backends
- ✅ All tests pass

**Git Commits**:
```
feat(recording): add TranscodeRecordingToMp3 queue job
chore(recording): add FFmpeg to Docker image
feat(recording): add ArchiveOldRecordingsCommand for cold storage
feat(recording): update playback URLs for tiered storage
feat(recording): add StorageDriverInterface abstraction
test(recording): add transcoding and archival tests
docs(recording): add deployment and operations guide
```

---

## 19. Updated Timeline

| Phase | Duration | Dependencies |
|-------|----------|--------------|
| Phase 1: Database | 3-4 days | None |
| Phase 2: Stream Core | 5-7 days | Phase 1 |
| Phase 3: Integration | 4-5 days | Phase 2 |
| Phase 4: Voice Routing | 4-5 days | Phase 3 |
| Phase 5: Frontend | 5-6 days | Phase 4 |
| Phase 6: Post-Processing | 4-5 days | Phase 5 |
| **Total** | **25-32 days** | **(~5-6 weeks)** |

---

## 20. Success Criteria

**Phase 1**: ✅ Migrations run, models work, policies enforce RBAC  
**Phase 2**: ✅ Audio streams captured and written to WAV  
**Phase 3**: ✅ Laravel and stream receiver communicate  
**Phase 4**: ✅ Calls automatically recorded based on settings  
**Phase 5**: ✅ Users can enable/disable recording and play back files  
**Phase 6**: ✅ MP3 transcoding works, old recordings archived  

**Production Ready**: All 6 phases complete, tested, and documented.

---

## 21. Appendix

### 21.1 Cloudonix CXML Example

**Reference**: https://developers.cloudonix.com/Documentation/voiceApplication/Verb/connect/stream

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
  <Connect>
    <!-- Stream directive for recording (MUST come before Dial) -->
    <Stream url="wss://opbx.example.com/stream/eyJhbGc..." track="both" />
    
    <!-- Dial directive for connecting the call -->
    <Dial timeout="30">sip:1001@sip.cloudonix.io</Dial>
  </Connect>
</Response>
```

**Key Parameters**:
- `url`: WebSocket URL (must use WSS in production)
- `track`: `inbound`, `outbound`, or `both` (we use `both`)

### 21.2 Storage Path Examples

```
MinIO Bucket: recordings/

Organization 42, Call on 2026-02-10:
  recordings/42/2026/02/call-abc123.wav

Organization 57, Call on 2026-03-15:
  recordings/57/2026/03/call-xyz789.wav

Benefits:
- Organization isolation at path level
- Year/month partitioning for lifecycle policies
- Easy to calculate storage per org
- Easy to bulk delete old recordings
```

### 21.3 Performance Estimates

**Storage Size Estimation**:
- 1 minute of WAV (8kHz, 16-bit mono): ~960 KB
- 10 minute call: ~9.6 MB
- 100 calls/day, 10 min avg: ~960 MB/day
- 30 days: ~28 GB/month per 100 daily calls

**Concurrent Stream Capacity**:
- Single stream receiver instance: 100 concurrent streams
- Stream receiver memory: ~2 GB (20 MB per stream)
- Horizontal scaling: Add more stream receiver containers

**MinIO Performance**:
- Write throughput: 1 GB/s (sufficient for 100+ streams)
- Read throughput: 1 GB/s (sufficient for playback)
- IOPS: > 10,000 (more than enough)

---

## End of Document

**Next Steps**:
1. Review this workplan with the team
2. Review approved decisions (Section 17)
3. Begin Phase 1 implementation (Database Schema & Core Models)
4. Schedule daily standups during implementation
5. Create GitHub issues/tickets for each task

**Document Status**: ✅ Ready for Implementation

