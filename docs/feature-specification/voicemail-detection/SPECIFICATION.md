# Stream-Based Voicemail Detection (AMD) Service

## Document Information

| Field | Value |
|-------|-------|
| Feature Name | Stream-Based Voicemail Detection |
| Status | DRAFT |
| Created | 2026-04-14 |
| Module | AMD Worker (new), Auto Dialer Campaigns (enhanced) |
| Dependencies | Cloudonix `<Start><Stream>` CXML, Auto Dialer, Node.js 20+ / TypeScript, ONNX Runtime |

---

## 1. Executive Summary

This feature adds a standalone, containerized voicemail detection (AMD) microservice that receives real-time audio streams from Cloudonix via the `<Start><Stream>` CXML verb, analyzes the audio using a pluggable detection pipeline, and reports results back to OPBX. OPBX then decides whether to disconnect the call or transfer it to a new destination.

This feature **complements** (does not replace) the existing Cloudonix built-in AMD (`machineDetection` parameter on outbound calls). Campaigns can choose either built-in AMD, stream-based AMD, or neither.

---

## 2. Architecture Overview

```
                          ┌──────────────────────┐
                          │   Cloudonix CPaaS     │
                          │   (VoIP + Streams)    │
                          └──────────┬────────────┘
                                     │
                                     │ WebSocket (<Start><Stream>)
                                     │ + HTTP (webhooks, statusCallbacks)
                                     ▼
                   ┌──────────────────────────────────────┐
                   │         ngrok (dev) / Public URL      │
                   └──────────────────┬───────────────────┘
                                     │
                                     ▼
┌─────────────────────────────────────────────────────────────────────────┐
│                        OPBX Docker Network                              │
│                                                                         │
│   ┌────────────────────────────────────────────────────────────────┐    │
│   │                     Nginx (Reverse Proxy)                      │    │
│   │                                                                │    │
│   │  /ws/amd/  ──────────> amd-worker:8082 (WebSocket upgrade)     │    │
│   │  /api/     ──────────> app:9000 (PHP-FPM / Laravel)            │    │
│   │  /app/     ──────────> soketi:6001 (Pusher WebSocket)          │    │
│   │  /         ──────────> frontend:3000 (Vite dev server)         │    │
│   └────────────────────────────────────────────────────────────────┘    │
│          │                         │                                    │
│          ▼                         ▼                                    │
│   ┌──────────────┐    ┌───────────────────┐    ┌──────────────────┐    │
│   │ AMD Worker   │    │  Laravel Backend   │    │  Go Dialer       │    │
│   │ (Node.js)    │    │  (PHP-FPM)         │    │  Worker          │    │
│   │              │    │                    │    │                  │    │
│   │ - WS server  │──> │ - AMD result API   │    │ - Call initiation│    │
│   │ - Audio pipe │    │ - CXML generation  │    │ - CAC/CPS limits │    │
│   │ - ONNX infer │<── │ - Cloudonix API    │    │                  │    │
│   │ - Metrics    │    │ - Action execution │    │                  │    │
│   └──────────────┘    └───────────────────┘    └──────────────────┘    │
│                               │                                         │
│                        ┌──────▼──────┐    ┌──────────┐                 │
│                        │   MySQL     │    │  Redis    │                 │
│                        └─────────────┘    └──────────┘                 │
└─────────────────────────────────────────────────────────────────────────┘
```

**Key networking principle**: All external traffic (from Cloudonix) enters through the **nginx reverse proxy**. The AMD Worker WebSocket is **not exposed directly** — Cloudonix connects to the public URL (via ngrok in development) which routes through nginx to the AMD worker. This is consistent with how all other OPBX services are accessed.

### Component Responsibilities

| Component | Responsibility |
|-----------|---------------|
| **Nginx** | Reverse proxy: routes `/ws/amd/` to AMD worker, `/api/` to Laravel, all other paths as existing |
| **Auto Dialer Campaign UI** | Configure stream-based AMD settings per campaign |
| **Laravel Backend** | Generate CXML with `<Start><Stream>`, receive AMD results, execute actions via Cloudonix API |
| **Go Dialer Worker** | Initiate calls (existing, unchanged) |
| **Cloudonix CPaaS** | Execute CXML, connect WebSocket stream through nginx to AMD Worker |
| **AMD Worker (Node.js)** | Receive audio streams (via nginx proxy), run detection pipeline, report results to OPBX (via nginx) |

---

## 3. Cloudonix Integration Points

All Cloudonix API and CXML references are verified against the official documentation.

### 3.1 `<Start><Stream>` CXML Verb

**Source**: [Cloudonix `<Start><Stream>` Documentation](https://developers.cloudonix.com/Documentation/voiceApplication/Verb/start/stream)

The `<Stream>` noun inside `<Start>` creates a unidirectional audio stream over WebSocket.

#### CXML Example for AMD

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <!-- Start AMD stream BEFORE connecting to destination -->
    <!-- URL points to public OPBX URL; nginx proxies /ws/amd/ to amd-worker:8082 -->
    <Start>
        <Stream name="amd-{callSid}" url="wss://opbx.example.com/ws/amd/detect"
                track="inbound_track"
                statusCallback="https://opbx.example.com/api/webhooks/amd/stream-status"
                statusCallbackMethod="POST" />
    </Start>
    <!-- Normal call flow continues immediately (async) -->
    <Connect>
        <Stream url="wss://ai-assistant.example.com/ws" />
    </Connect>
</Response>
```

> **Note**: The `url` attribute in `<Stream>` uses the **public OPBX URL** (e.g., the ngrok tunnel URL in development). Nginx receives the WebSocket upgrade request on `/ws/amd/` and proxies it to the `amd-worker` container. Cloudonix never connects directly to the AMD worker.

#### Stream Protocol Messages (from Cloudonix)

The protocol is compatible with the Twilio Stream WebSocket protocol. Messages are JSON text frames:

| Message | Event Field | Key Data |
|---------|------------|----------|
| **Connected** | `connected` | `protocol`, `version` |
| **Start** | `start` | `streamSid`, `start.callSid`, `start.session`, `start.tracks[]`, `start.mediaFormat`, `start.customParameters` |
| **Media** | `media` | `media.track`, `media.chunk`, `media.timestamp`, `media.payload` (base64) |
| **DTMF** | `dtmf` | `dtmf.track`, `dtmf.digit` |
| **Stop** | `stop` | `stop.session`, `stop.callSid` |

#### Audio Format

| Property | Value |
|----------|-------|
| Encoding | `audio/x-mulaw` (µ-law) |
| Sample Rate | 8000 Hz |
| Channels | 1 (mono) |

> **Note**: The AMD service must internally convert from µ-law 8kHz to linear PCM 16kHz for MFCC feature extraction. This is handled transparently within the AMD worker.

#### Stream Attributes Used

| Attribute | Value | Rationale |
|-----------|-------|-----------|
| `name` | `amd-{callSid}` | Unique per call, allows `<Stop>` if needed |
| `url` | `{WEBHOOK_BASE_URL}/ws/amd/detect` | Public URL routed through nginx to AMD worker. Uses the same base URL as all other Cloudonix webhooks (resolved by `WebhookUrlResolver`). The `/ws/amd/` prefix is what nginx uses to route to the `amd-worker` upstream. |
| `track` | `inbound_track` | We analyze the callee's audio (the remote party) |
| `statusCallback` | `{WEBHOOK_BASE_URL}/api/webhooks/amd/stream-status` | Receive stream lifecycle events, same base URL |
| `statusCallbackMethod` | `POST` | Standard |

### 3.2 Session Delete (Hangup) API

**Source**: [Cloudonix Call and Session Control - Destroy Session](https://developers.cloudonix.com/Documentation/apiWorkflow/callControlAndSessionManagement#destroy-session-hangup)

Used when AMD detects voicemail and the campaign action is "disconnect".

```
DELETE /calls/{domain-name-or-id}/sessions/{token}
Authorization: Bearer {domain-api-key}
```

**Response**: HTTP 204 (No Content)

**Optional query parameter**: `?reason=timeout` (or other reason codes: `timeout`, `denied`, `busy`, `nocredit`)

### 3.3 Switch Voice Application API

**Source**: [Cloudonix Call and Session Control - Switch Voice Application](https://developers.cloudonix.com/Documentation/apiWorkflow/callControlAndSessionManagement#switch-voice-application)

Used when AMD detects voicemail and the campaign action is "transfer".

```
POST /calls/{domain-name-or-id}/sessions/{token}/application
Authorization: Bearer {domain-api-key}
Content-Type: application/json

{
    "cxml": "<?xml version=\"1.0\" encoding=\"UTF-8\"?><Response>...</Response>"
}
```

**Alternative**: Instead of inline `cxml`, can provide `url` pointing to a CXML endpoint.

**Response**: HTTP 200 with session details JSON.

**Constraints** (from docs):
- Requires domain administrator or higher authorization
- Session must be actively running a voice application
- If currently in a `<Dial>`/`<Connect>`, the outgoing call will be disconnected first
- Request must contain either `cxml` OR `url` (not both, not neither)

---

## 4. AMD Worker Service (Node.js)

### 4.1 Technology Stack

| Component | Technology | Rationale |
|-----------|-----------|-----------|
| Language | Node.js 20+ / TypeScript | Team familiarity, excellent async I/O for WebSocket handling |
| WebSocket | `ws` (npm) | De-facto Node.js WebSocket library, 78M weekly downloads, handles upgrades and backpressure natively |
| ML Inference | `onnxruntime-node` (npm) | Official ONNX Runtime for Node.js, pre-built native binaries, TypeScript types included. No CGO complexity. |
| Audio Processing | Custom TypeScript + `fft-js` | µ-law decode, resample 8kHz→16kHz, MFCC extraction using FFT |
| MFCC Extraction | `mfcc` (npm) or custom with `fft-js` + `dct` | Pure JS MFCC implementation. Alternatively, custom implementation matching librosa parameters. |
| VAD | Custom energy-based (TypeScript) | Simple energy-threshold VAD in pure TypeScript. Optional: `node-vad` for native libfvad bindings. |
| CPU-bound Work | `worker_threads` + `piscina` | Offload MFCC extraction and ONNX inference to worker thread pool to avoid blocking the event loop |
| HTTP Client | `undici` or Node.js native `fetch` | For OPBX API calls (context lookup, result reporting) |
| HTTP Server | `express` or native `http` | Health/metrics endpoints |
| Configuration | Environment variables | Consistent with dialer worker pattern |

### 4.2 Directory Structure

```
amd-worker/
├── src/
│   ├── index.ts                     # Entry point
│   ├── config.ts                    # Environment configuration
│   ├── stream/
│   │   ├── handler.ts               # WebSocket connection handler
│   │   ├── protocol.ts              # Cloudonix stream protocol parser
│   │   └── session.ts               # Per-stream session state
│   ├── audio/
│   │   ├── decoder.ts               # µ-law to PCM conversion
│   │   ├── resampler.ts             # 8kHz to 16kHz upsampling
│   │   ├── vad.ts                   # Voice Activity Detection
│   │   └── buffer.ts                # Audio frame buffering
│   ├── feature/
│   │   ├── mfcc.ts                  # MFCC feature extraction (runs in worker thread)
│   │   ├── mfcc.worker.ts           # Worker thread entry for MFCC computation
│   │   └── energy.ts                # Energy/frequency analysis
│   ├── detector/
│   │   ├── pipeline.ts              # Pluggable detector pipeline
│   │   ├── types.ts                 # Detector interface & result types
│   │   ├── beep-ml.ts               # ML-based beep detector (ONNX)
│   │   └── tone-energy.ts           # Energy-based tone detector
│   ├── model/
│   │   └── onnx.ts                  # ONNX model loading and inference
│   ├── api/
│   │   ├── client.ts                # OPBX HTTP client
│   │   └── types.ts                 # Request/response types
│   └── metrics/
│       └── metrics.ts               # Health and metrics tracking
├── models/
│   └── beep_detector.onnx           # Pre-trained ONNX model
├── tests/
│   ├── stream/
│   │   └── protocol.test.ts
│   ├── audio/
│   │   ├── decoder.test.ts
│   │   └── vad.test.ts
│   ├── feature/
│   │   └── mfcc.test.ts
│   └── detector/
│       ├── beep-ml.test.ts
│       └── tone-energy.test.ts
├── Dockerfile
├── package.json
├── tsconfig.json
├── .eslintrc.json
└── README.md
```

### 4.3 Pluggable Detector Interface

```typescript
/** Detector is the interface for all AMD detection plugins */
interface Detector {
    /** Returns the detector's identifier */
    readonly name: string;

    /**
     * Process an audio segment and return a detection result.
     * Returns null if no determination yet (needs more data).
     */
    process(segment: AudioSegment): DetectionResult | null;

    /** Reset internal state for a new stream */
    reset(): void;
}

/** Represents a detector's output */
interface DetectionResult {
    detector: string;           // Which detector produced this result
    result: ResultType;         // VOICEMAIL, HUMAN, UNKNOWN
    confidence: number;         // 0.0 - 1.0
    reason: string;             // Human-readable explanation
    timestampMs: number;        // Milliseconds from stream start
}

/** Detection result type */
enum ResultType {
    VOICEMAIL = 'voicemail',
    HUMAN = 'human',
    UNKNOWN = 'unknown',
}

/** Audio segment for analysis */
interface AudioSegment {
    pcmData: Float32Array;      // Linear PCM samples
    sampleRate: number;         // Sample rate in Hz
    durationMs: number;         // Duration in milliseconds
    startTimestampMs: number;   // Start time from stream beginning
}
```

### 4.4 V1 Detectors

#### Beep Detector (ML-based)

Based on the [Nexmo AnsweringMachineDetection](https://github.com/nexmo-community/AnsweringMachineDetection) reference implementation.

**Algorithm**:
1. Audio arrives as µ-law 8kHz base64 frames from Cloudonix stream
2. Decode µ-law to linear PCM 16-bit
3. Upsample from 8kHz to 16kHz (required for MFCC extraction consistency with training data)
4. Voice Activity Detection (VAD) segments audio into speech/silence regions
5. When a speech segment completes (silence detected after speech):
   - Extract MFCC features (40 coefficients, averaged over time)
   - Run ONNX inference (GaussianNB model)
   - Prediction `0` = beep detected, prediction `1` = speech
6. If beep detected → report `ResultVoicemail`

**Model Details** (from reference):
- Training: MFCC features (40 coefficients) extracted via librosa
- Classifier: GaussianNB (98% accuracy) or GaussianProcessClassifier
- Classes: `0` = beep, `1` = speech
- Model format: Converted from scikit-learn `.pkl` to ONNX

**VAD Parameters** (configurable):
| Parameter | Default | Description |
|-----------|---------|-------------|
| `SILENCE_FRAMES` | 10 | Consecutive silent frames to end a speech segment |
| `CLIP_MIN_MS` | 200 | Minimum audio clip length to analyze |
| `MAX_LENGTH_MS` | 3000 | Maximum audio clip length for processing |
| `VAD_SENSITIVITY` | 3 | VAD aggressiveness (1-3, 3 = most aggressive) |

#### Energy-Based Tone Detector

A lightweight, non-ML fallback detector for beep tones.

**Algorithm**:
1. Compute spectral energy in the 800Hz-2500Hz range (typical voicemail beep frequencies)
2. If energy in this band exceeds threshold AND energy outside this band is below threshold (indicating a pure tone):
   - Duration of tone > 200ms → beep detected
3. Report `ResultVoicemail`

This detector serves as:
- A fast initial check before ML inference completes
- A fallback if the ML model fails to load
- A complementary signal (both detectors can vote)

### 4.5 Detection Pipeline Flow

```
Audio Stream (µ-law 8kHz)
    │
    ▼
┌─────────────┐
│ µ-law Decode │──> PCM 16-bit 8kHz
└──────┬──────┘
       │
       ▼
┌─────────────┐
│  Resample   │──> PCM 16-bit 16kHz
└──────┬──────┘
       │
       ├──────────────────────┐
       ▼                      ▼
┌──────────────┐    ┌─────────────────┐
│  VAD + MFCC  │    │ Energy Analysis │
│  Buffer      │    │ (continuous)    │
└──────┬───────┘    └────────┬────────┘
       │                     │
       ▼                     ▼
┌──────────────┐    ┌─────────────────┐
│ Beep ML Det. │    │ Tone Energy Det.│
│ (on segment) │    │ (on window)     │
└──────┬───────┘    └────────┬────────┘
       │                     │
       └──────────┬──────────┘
                  ▼
         ┌────────────────┐
         │ Pipeline        │
         │ Aggregator      │
         │ (first positive │
         │  result wins)   │
         └────────┬───────┘
                  │
                  ▼
         ┌────────────────┐
         │ Report to OPBX │
         │ via HTTP POST  │
         └────────────────┘
```

### 4.6 Configuration (Environment Variables)

| Variable | Default | Required | Description |
|----------|---------|----------|-------------|
| `AMD_WEBSOCKET_PORT` | `8082` | No | WebSocket listener port (nginx proxies to this) |
| `AMD_HTTP_PORT` | `8083` | No | Health/metrics HTTP port (internal only) |
| `OPBX_API_URL` | `http://nginx` | Yes | OPBX URL, routed through nginx (same pattern as dialer worker) |
| `AMD_WORKER_API_TOKEN` | - | Yes | Bearer token for OPBX authentication |
| `AMD_MODEL_PATH` | `./models/beep_detector.onnx` | No | Path to ONNX model file |
| `AMD_MAX_CONCURRENT_STREAMS` | `100` | No | Maximum simultaneous streams |
| `AMD_DEFAULT_TIMEOUT_SECONDS` | `30` | No | Default detection timeout |
| `AMD_LOG_LEVEL` | `info` | No | Logging level |
| `AMD_DETECTORS` | `beep_ml,tone_energy` | No | Comma-separated list of enabled detectors |
| `REDIS_HOST` | `redis` | No | Redis host (for optional metrics/state) |
| `REDIS_PORT` | `6379` | No | Redis port |
| `REDIS_PASSWORD` | - | Yes | Redis password (shared with other services) |

### 4.7 WebSocket Endpoint

**Internal** (within Docker network, what AMD worker listens on):
```
ws://amd-worker:8082/ws/detect
```

**External** (what Cloudonix connects to, via nginx proxy):
```
wss://{public-opbx-url}/ws/amd/detect
```

Nginx strips the `/ws/amd` prefix and proxies to `amd-worker:8082/ws/detect`. The AMD worker only sees requests on `/ws/detect` — it is unaware of the nginx prefix.

The AMD worker accepts incoming WebSocket connections (proxied through nginx from Cloudonix). It speaks the Cloudonix/Twilio-compatible Stream WebSocket protocol.

#### Connection Lifecycle

1. **Cloudonix connects** → AMD receives `connected` message
2. **`start` message received** → AMD extracts `callSid` and `session` token, calls OPBX to get campaign context
3. **`media` messages flow** → AMD decodes, processes through detection pipeline
4. **Detection result** → AMD calls OPBX HTTP endpoint with result
5. **`stop` message or timeout** → AMD cleans up session, reports `unknown` if no detection
6. **WebSocket closes** → Session cleanup

### 4.8 OPBX Context Lookup

When the AMD worker receives a `start` message, it extracts the `callSid` from `start.callSid` and calls OPBX to retrieve campaign context:

```
GET /api/v1/amd/worker/session/{callSid}
Authorization: Bearer {AMD_WORKER_API_TOKEN}
```

**Response**:
```json
{
    "call_sid": "abc123",
    "session_token": "16a7294c989b11e7...",
    "campaign_id": 42,
    "organization_id": 7,
    "detection_timeout": 30,
    "on_voicemail_action": "disconnect",
    "transfer_destination": null,
    "detectors": ["beep_ml", "tone_energy"]
}
```

If the `callSid` is not found (call is not from an auto dialer campaign with stream AMD), OPBX returns HTTP 404 and the AMD worker closes the stream gracefully.

### 4.9 Health & Metrics Endpoint

```
GET http://amd-worker:{AMD_HTTP_PORT}/health
```

**Response**:
```json
{
    "status": "healthy",
    "model_loaded": true,
    "active_streams": 23,
    "max_streams": 100,
    "total_detections": 1547,
    "detection_breakdown": {
        "voicemail": 423,
        "human": 987,
        "unknown": 137
    },
    "avg_detection_time_ms": 4200,
    "uptime_seconds": 86400
}
```

---

## 5. OPBX Laravel Backend Changes

### 5.1 New Database Fields

#### `auto_dialer_campaigns` Table (Migration)

| Column | Type | Default | Description |
|--------|------|---------|-------------|
| `stream_amd_enabled` | `boolean` | `false` | Enable stream-based AMD |
| `stream_amd_timeout` | `integer` nullable | `30` | Detection timeout in seconds (3-120) |
| `stream_amd_on_voicemail` | `string` nullable | `disconnect` | Action: `disconnect` or `transfer` |
| `stream_amd_transfer_destination_type` | `string` nullable | `null` | Destination routing type (uses existing `RoutingDestinationType` enum) |
| `stream_amd_transfer_destination_id` | `unsignedBigInteger` nullable | `null` | Destination resource ID |

> **Note**: `stream_amd_enabled` is independent of the existing `amd_enabled` toggle. A campaign can have built-in AMD, stream-based AMD, both, or neither. If both are enabled, built-in AMD runs first (blocking or async), and stream-based AMD runs in parallel via the audio stream.

### 5.2 New API Endpoints

#### AMD Worker API (internal, authenticated)

**Prefix**: `/api/v1/amd/worker`
**Middleware**: `amd.worker.auth` (new, mirrors `dialer.worker.auth` pattern)

| Method | URI | Purpose |
|--------|-----|---------|
| `GET` | `/session/{callSid}` | Get campaign context for a call |
| `POST` | `/result` | Receive AMD detection result |

#### AMD Worker Session Lookup

```
GET /api/v1/amd/worker/session/{callSid}
```

**Response (200)**:
```json
{
    "call_sid": "abc123",
    "session_token": "16a7294c989b11e7...",
    "campaign_id": 42,
    "organization_id": 7,
    "domain_name": "example.cloudonix.com",
    "detection_timeout": 30,
    "on_voicemail_action": "disconnect",
    "transfer_destination": {
        "type": "ivr_menu",
        "id": 15
    },
    "detectors": ["beep_ml", "tone_energy"]
}
```

**Response (404)**: Call not found or not associated with a stream-AMD-enabled campaign.

Implementation: Looks up `auto_dialer_call_sessions` by `call_id` matching the `callSid`, joins to campaign, checks `stream_amd_enabled`.

#### AMD Detection Result

```
POST /api/v1/amd/worker/result
Authorization: Bearer {AMD_WORKER_API_TOKEN}
Content-Type: application/json

{
    "call_sid": "abc123",
    "session_token": "16a7294c989b11e7...",
    "stream_sid": "stream-xyz",
    "result": "voicemail",
    "confidence": 0.96,
    "detector": "beep_ml",
    "reason": "Beep tone detected at 4.2s",
    "detection_time_ms": 4200
}
```

**Result values**: `voicemail`, `human`, `unknown`

**Response (200)**:
```json
{
    "action_taken": "disconnect",
    "success": true
}
```

**OPBX Action Logic** (on receiving result):

```
if result == "voicemail":
    if campaign.stream_amd_on_voicemail == "disconnect":
        call Cloudonix DELETE /calls/{domain}/sessions/{token}
    elif campaign.stream_amd_on_voicemail == "transfer":
        generate CXML for transfer destination
        call Cloudonix POST /calls/{domain}/sessions/{token}/application
    update call session: amd_result = "machine", amd_confidence = confidence
elif result == "human":
    // No action needed, call continues normally
    update call session: amd_result = "human", amd_confidence = confidence
elif result == "unknown":
    // Timeout - no determination made
    update call session: amd_result = "unknown"
```

### 5.3 CXML Generation Changes

**File**: `app/Services/CxmlBuilder/AutoDialerCxmlBuilder.php`

When `stream_amd_enabled` is true on a campaign, the CXML builder must prepend `<Start><Stream>` before the normal routing verbs:

```xml
<?xml version="1.0" encoding="UTF-8"?>
<Response>
    <!-- Stream-based AMD: start audio stream to AMD worker via nginx -->
    <Start>
        <Stream name="amd-stream"
                url="wss://abc123.ngrok.io/ws/amd/detect"
                track="inbound_track"
                statusCallback="https://abc123.ngrok.io/api/webhooks/amd/stream-status"
                statusCallbackMethod="POST" />
    </Start>
    <!-- Normal call routing follows immediately -->
    <Connect>
        <Stream url="wss://ai-assistant.example.com/ws" />
    </Connect>
</Response>
```

**URL construction**: The `url` attribute is built from the same webhook base URL that all other Cloudonix webhooks use, resolved by `WebhookUrlResolver` (priority: `OPBX_APPLICATION_WEBHOOK_BASEURL` env → `CloudonixSettings::webhook_base_url` → `config('app.url')`). The path `/ws/amd/detect` is appended. The scheme is changed to `wss://` (or `ws://` for non-TLS).

This ensures the AMD stream URL automatically matches the public URL used for API webhooks — no separate AMD-specific URL configuration needed.

### 5.4 Stream Status Callback Endpoint

**Webhook**: `POST /api/webhooks/amd/stream-status`
**Middleware**: `webhook.signature` (existing Cloudonix webhook signature verification)

Receives stream lifecycle events from Cloudonix (`statusCallback` attribute):

| Parameter | Description |
|-----------|-------------|
| `CallSid` | Call ID |
| `Session` | Session token |
| `StreamSid` | Stream identifier |
| `StreamName` | Stream name (e.g., `amd-stream`) |
| `StreamEvent` | `stream-started`, `stream-stopped`, `stream-error` |
| `StreamError` | Error message (if error) |
| `Timestamp` | ISO 8601 timestamp |

This endpoint logs stream lifecycle events and handles error cases (e.g., if stream fails to connect to AMD worker).

### 5.5 New Laravel Middleware

**`VerifyAmdWorkerAuth`** (alias: `amd.worker.auth`)

Mirrors the existing `dialer.worker.auth` middleware pattern:
- Checks `Authorization: Bearer {token}` header
- Validates against `AMD_WORKER_API_TOKEN` environment variable
- Returns 401 on failure

### 5.6 New/Modified Services

| Service | Type | Purpose |
|---------|------|---------|
| `AmdResultHandler` | New | Processes AMD detection results, executes actions via Cloudonix API |
| `AmdCxmlGenerator` | New | Generates the `<Start><Stream>` CXML fragment |
| `AutoDialerCxmlBuilder` | Modified | Integrate stream AMD CXML into campaign call flow |
| `AutoDialerCloudonixService` | Modified | Include stream AMD config when building payloads |

### 5.7 Campaign Form Changes (Frontend)

New section in Auto Dialer Campaign form: **"Stream-Based AMD"**

Fields:
- **Enable Stream AMD** (`stream_amd_enabled`): Toggle switch
- **Detection Timeout** (`stream_amd_timeout`): Number input, 3-120 seconds, default 30
- **On Voicemail Detected** (`stream_amd_on_voicemail`): Select: "Disconnect Call" / "Transfer Call"
- **Transfer Destination** (`stream_amd_transfer_destination_type` + `_id`): Standard OPBX destination selector (existing component), shown only when action is "Transfer Call"

These fields are shown in a collapsible panel, independent of the existing "AMD Settings" section.

---

## 6. Call Flow

### 6.1 Complete Flow: Auto Dialer Call with Stream AMD

```
Step  Actor              Action
──────────────────────────────────────────────────────────────
 1    Go Dialer Worker   Polls OPBX for active campaigns, gets pending destinations
 2    Go Dialer Worker   POST /calls/initiate to OPBX Laravel
 3    Laravel             Builds payload with CXML that includes <Start><Stream>
 4    Laravel             POST /calls/{domain}/application to Cloudonix API
 5    Cloudonix           Initiates outbound call to destination phone number
 6    Callee              Answers the call (human or answering machine)
 7    Cloudonix           Executes CXML: <Start><Stream> → opens WebSocket to AMD Worker
 8    Cloudonix           Executes next verb: <Connect> → connects to AI/destination
                          (both happen ~simultaneously due to <Start> being async)
 9    Cloudonix           Streams audio (inbound_track) to AMD Worker via WebSocket
10    AMD Worker          Receives `connected` message
11    AMD Worker          Receives `start` message → extracts callSid
12    AMD Worker          GET /api/v1/amd/worker/session/{callSid} → gets campaign config
13    AMD Worker          Receives `media` messages → decodes µ-law → resamples → runs pipeline
14a   AMD Worker          IF beep detected (or timeout reached):
15a   AMD Worker          POST /api/v1/amd/worker/result → reports to OPBX
16a   Laravel             Processes result:
                          - If "disconnect": DELETE /calls/{domain}/sessions/{token}
                          - If "transfer": POST /calls/{domain}/sessions/{token}/application
17a   Cloudonix           Executes the action (hangup or switch application)
18a   AMD Worker          Receives `stop` message (stream closes with call)

14b   AMD Worker          IF human detected (speech patterns, no beep):
15b   AMD Worker          POST /api/v1/amd/worker/result with result="human"
16b   Laravel             Logs result, no call interruption needed
17b   AMD Worker          Continues listening until timeout or stream ends
```

### 6.2 Timing Diagram

```
Time ──────────────────────────────────────────────────────────────>

Call:     [ring]──[answer]─────────────────────[disconnect]
                    │
CXML:               ├── <Start><Stream> ──┐
                    │                      │ (async, parallel)
                    └── <Connect> ─────────┤
                                           │
Stream:                [WS connect]──[start]──[media][media]...[stop]
                                       │
AMD:                                   └── lookup context
                                           │
                                           [decode][decode]...[BEEP!]
                                                               │
                                                          POST /result
                                                               │
OPBX:                                                     DELETE session
                                                               │
Cloudonix:                                                [hangup]
```

---

## 7. Security Considerations

### 7.1 Authentication

| Communication Path | Auth Method | Details |
|-------------------|-------------|---------|
| Cloudonix → nginx → AMD Worker | None (WebSocket) | Cloudonix connects to public URL `/ws/amd/detect`. Nginx proxies WebSocket to AMD worker. AMD validates call via OPBX context lookup. |
| AMD Worker → nginx → OPBX | Bearer token | `AMD_WORKER_API_TOKEN` env var, `amd.worker.auth` middleware. AMD calls `http://nginx/api/v1/amd/worker/*`. |
| OPBX → Cloudonix API | Bearer token | Organization's `domain_api_key` (existing) |
| Cloudonix → nginx → OPBX (statusCallback) | Webhook signature | Standard `/api/` routing through nginx. Existing `webhook.signature` middleware. |

### 7.2 Stream Validation

The AMD worker validates incoming streams at multiple layers:

**Nginx layer** (first line of defense):
1. Only `/ws/amd/` path is routed to AMD worker — all other paths go to existing services
2. Nginx security headers apply (X-Frame-Options, X-Content-Type-Options, etc.)

**AMD worker layer**:
1. Accept only WebSocket upgrade requests on `/ws/detect`
2. After receiving `start` message, look up `callSid` in OPBX (via nginx → Laravel)
3. If OPBX returns 404 (unknown call), close the WebSocket immediately
4. Enforce `AMD_MAX_CONCURRENT_STREAMS` limit; reject new connections when full (HTTP 503)

### 7.3 No Credential Distribution

AMD Worker never receives Cloudonix API credentials. All Cloudonix API calls are made by OPBX Laravel, which already has per-organization credentials stored securely (encrypted in `cloudonix_settings` table).

---

## 8. Docker & Nginx Integration

### 8.1 Nginx Reverse Proxy Configuration

The AMD worker WebSocket is exposed through nginx, following the same pattern as Soketi (`/app/` → `soketi:6001`). A new `location` block routes WebSocket traffic from `/ws/amd/` to the AMD worker container.

**Addition to `docker/nginx/conf.d/default.conf`**:

```nginx
# AMD Worker WebSocket proxy
# Cloudonix <Start><Stream> connects here via the public URL
location /ws/amd/ {
    proxy_pass http://amd-worker:8082/ws/;

    # WebSocket upgrade headers (required)
    proxy_http_version 1.1;
    proxy_set_header Upgrade $http_upgrade;
    proxy_set_header Connection "upgrade";

    # Pass original headers
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;

    # WebSocket timeouts - keep connections alive for up to 2 minutes
    # (matches max AMD detection timeout of 120s)
    proxy_connect_timeout 120s;
    proxy_send_timeout 120s;
    proxy_read_timeout 120s;
}
```

**Placement**: This block must appear **before** the catch-all `location /` block in `default.conf`, similar to how `/app/` and `/api/` are placed.

**Full routing table after change**:

| Location | Target | Protocol | Notes |
|----------|--------|----------|-------|
| `/ws/amd/` | `amd-worker:8082` | **WebSocket** | AMD audio stream from Cloudonix. 120s timeout (matches max detection timeout). |
| `/app/` | `soketi:6001` | WebSocket | Soketi/Pusher (existing) |
| `/api/` | `app:9000` (PHP-FPM) | HTTP | Laravel API, including AMD result callback and stream status (existing) |
| `*.php$` | `app:9000` | FastCGI | PHP-FPM (existing) |
| `/` | `opbx_frontend:3000` | HTTP/WS | React Vite dev server (existing) |

### 8.2 New Container in `docker-compose.yml`

The AMD worker exposes **no ports externally** — all traffic arrives through nginx.

```yaml
# AMD Worker - Node.js voicemail detection service
# Receives audio streams from Cloudonix via nginx WebSocket proxy at /ws/amd/
# Reports detection results to Laravel via internal HTTP
amd-worker:
    build:
        context: ./amd-worker
        dockerfile: Dockerfile
    container_name: opbx_amd_worker
    restart: unless-stopped
    # No external ports - all traffic proxied through nginx
    environment:
        - AMD_WEBSOCKET_PORT=8082
        - AMD_HTTP_PORT=8083
        - OPBX_API_URL=http://nginx
        - AMD_WORKER_API_TOKEN=${AMD_WORKER_API_TOKEN:-dev-amd-token-change-in-production}
        - AMD_MODEL_PATH=/app/models/beep_detector.onnx
        - AMD_MAX_CONCURRENT_STREAMS=${AMD_MAX_CONCURRENT_STREAMS:-100}
        - AMD_DEFAULT_TIMEOUT_SECONDS=30
        - AMD_LOG_LEVEL=info
        - REDIS_HOST=redis
        - REDIS_PORT=6379
        - REDIS_PASSWORD=${REDIS_PASSWORD}
    volumes:
        - ./amd-worker/models:/app/models:ro
    depends_on:
        - nginx
        - redis
    healthcheck:
        test: ["CMD", "node", "-e", "fetch('http://localhost:8083/health').then(r => process.exit(r.ok ? 0 : 1)).catch(() => process.exit(1))"]
        interval: 30s
        timeout: 10s
        retries: 3
        start_period: 10s
    networks:
        - opbx
```

**Key differences from dialer-worker pattern**:
- **No exposed ports**: Unlike the dialer-worker (which exposes 8081 for CDR webhooks), the AMD worker receives all external traffic through nginx
- **`OPBX_API_URL=http://nginx`**: AMD worker calls OPBX API through nginx (same as dialer-worker's `LARAVEL_API_URL`)
- **`depends_on: nginx`**: Ensures nginx is running before AMD worker starts (since AMD→OPBX calls go through nginx)

The nginx service also needs an updated `depends_on` to be aware of the AMD worker (for upstream resolution). However, since nginx uses the Docker DNS resolver (`resolver 10.89.0.1 valid=10s;`), it will resolve `amd-worker` dynamically — no hard dependency needed. Nginx will return 502 if the AMD worker isn't ready yet, which is acceptable since Cloudonix will report a `stream-error` via the `statusCallback`.

### 8.3 Dockerfile (AMD Worker)

```dockerfile
FROM node:20-slim AS builder
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci
COPY tsconfig.json ./
COPY src/ ./src/
RUN npm run build

FROM node:20-slim
RUN apt-get update && apt-get install -y --no-install-recommends \
    ca-certificates \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --production
COPY --from=builder /build/dist ./dist
COPY models ./models
EXPOSE 8082 8083
CMD ["node", "dist/index.js"]
```

> **Note**: `onnxruntime-node` ships with pre-built native binaries that are installed automatically via `npm ci`. No manual native library installation or CGO build toolchain required.

### 8.4 Environment Variables (`.env.example`)

```bash
# AMD Worker
AMD_WORKER_API_TOKEN=          # Shared secret for AMD worker <-> OPBX auth
AMD_MAX_CONCURRENT_STREAMS=100 # Max concurrent audio streams per AMD worker container
```

> **Note**: There is no `AMD_SERVICE_WSS_URL` env var. The stream URL in CXML is derived from the same webhook base URL used for all Cloudonix webhooks (`WebhookUrlResolver`), with the path `/ws/amd/detect` appended. This ensures the stream URL automatically uses the correct public URL (ngrok in dev, production domain in prod) without separate configuration.

### 8.5 Networking Flow

```
Cloudonix Cloud
    │
    │  wss://abc123.ngrok.io/ws/amd/detect
    │
    ▼
┌─────────┐         ┌─────────┐         ┌──────────────┐
│  ngrok  │────────>│  nginx  │────────>│  amd-worker  │
│ :4040   │  :80    │  :80    │ /ws/amd/ │  :8082 (WS)  │
└─────────┘         │         │         │  :8083 (HTTP) │
                    │         │         └──────┬───────┘
                    │         │                │
                    │         │    HTTP POST /api/v1/amd/worker/result
                    │         │                │
                    │         │<───────────────┘
                    │         │
                    │         │──────> app:9000 (PHP-FPM / Laravel)
                    └─────────┘
```

**Development flow** (with ngrok):
1. ngrok tunnels all traffic to `nginx:80` (existing setup, single tunnel)
2. Cloudonix connects WebSocket to `wss://{ngrok-url}/ws/amd/detect`
3. nginx upgrades the connection and proxies to `amd-worker:8082`
4. AMD worker calls back to `http://nginx/api/v1/amd/worker/result`
5. nginx routes `/api/` to Laravel as usual

**No separate ngrok tunnel is needed** — the existing single ngrok→nginx tunnel handles both API webhooks and AMD WebSocket streams.

---

## 9. ONNX Model Preparation

### 9.1 Model Conversion

The reference repository ships scikit-learn models in Python pickle format. We need to convert to ONNX:

```python
# conversion_script.py (one-time, not shipped in production)
import pickle
import numpy as np
from skl2onnx import convert_sklearn
from skl2onnx.common.data_types import FloatTensorType

# Load the scikit-learn model
model = pickle.load(open("models/GaussianNB-20190130T1233.pkl", "rb"))

# Define input shape: 40 MFCC coefficients
initial_type = [('mfcc_features', FloatTensorType([None, 40]))]

# Convert to ONNX
onnx_model = convert_sklearn(model, initial_types=initial_type)

# Save
with open("models/beep_detector.onnx", "wb") as f:
    f.write(onnx_model.SerializeToString())
```

### 9.2 MFCC Feature Extraction in Node.js

The Node.js AMD worker must replicate the exact MFCC extraction that was used during training:

| Parameter | Value | Source |
|-----------|-------|--------|
| Number of MFCC coefficients | 40 | `n_mfcc=40` in reference |
| Aggregation | Mean over time axis | `np.mean(..., axis=0)` in reference |
| Input sample rate | 16kHz | After resampling from 8kHz |
| Window function | Hann (librosa default) | librosa default |
| FFT size | 2048 (librosa default) | librosa default |
| Hop length | 512 (librosa default) | librosa default |

Node.js libraries for MFCC:
- `mfcc` npm package (pure JS, FFT→Mel filterbank→DCT, configurable parameters)
- `fft-js` + `dct` for custom implementation matching librosa's algorithm exactly
- MFCC computation is CPU-intensive and MUST run in a `worker_threads` pool (via `piscina`) to avoid blocking the event loop during concurrent stream processing

---

## 10. Error Handling & Edge Cases

### 10.1 AMD Worker Errors

| Scenario | Behavior |
|----------|----------|
| OPBX unreachable (context lookup fails) | Close WebSocket, log error. AMD cannot determine action without context. |
| ONNX model fails to load | Start without ML detector. Energy-based tone detector still operational. Log critical error. |
| Max concurrent streams reached | Return HTTP 503 on new WebSocket upgrade. Cloudonix stream `statusCallback` will receive `stream-error`. |
| WebSocket disconnects mid-stream | Clean up session. If no result was reported yet, report `unknown` to OPBX. |
| Detection timeout reached | Report `unknown` result to OPBX. OPBX treats as "no determination" (no action taken, call continues). |
| OPBX result endpoint unreachable | Retry 3 times with exponential backoff (1s, 2s, 4s). If all fail, log error. Call continues without action. |

### 10.2 OPBX Backend Errors

| Scenario | Behavior |
|----------|----------|
| Cloudonix session delete fails (404) | Call already ended. Log and update session record. |
| Cloudonix switch application fails (400) | Session not in valid state. Log error, attempt disconnect as fallback. |
| Cloudonix switch application fails (409) | Call not connected. Log and update session record. |
| Campaign deleted while stream active | Context lookup returns 404. AMD worker closes stream. |
| Duplicate AMD results for same call | Idempotency check: only process first result per `call_sid`. Store processed flag in Redis. |

### 10.3 Race Conditions

| Race Condition | Mitigation |
|----------------|-----------|
| Call ends before AMD detects | AMD receives `stop` message, reports `unknown`. OPBX receives result for ended call, updates DB only. |
| AMD detects after call transferred by other means | Cloudonix API returns 404/409. OPBX logs and ignores. |
| Multiple detectors report simultaneously | Pipeline aggregator uses a resolved flag (atomic boolean); first positive result wins, subsequent results ignored for that stream. |
| Built-in AMD and stream AMD both enabled | Both run independently. Built-in AMD affects `AnsweredBy` field on application request. Stream AMD reports separately. OPBX should handle both results gracefully (first action wins, second is no-op). |

---

## 11. Testing Strategy

### 11.1 AMD Worker Tests (Node.js / TypeScript)

| Test Area | Tests |
|-----------|-------|
| **Protocol parsing** | Parse `connected`, `start`, `media`, `stop`, `dtmf` messages correctly |
| **Audio decoding** | µ-law to PCM conversion accuracy |
| **Resampling** | 8kHz to 16kHz resampling accuracy |
| **MFCC extraction** | Verify against known librosa output for test audio files |
| **Beep detection** | Feed known beep audio → expect `voicemail` result |
| **Speech detection** | Feed known speech audio → expect no beep detection |
| **Timeout handling** | Simulate timeout → expect `unknown` result |
| **Concurrent streams** | Run N simultaneous streams, verify isolation |
| **Max streams enforcement** | Exceed limit → verify 503 response |
| **OPBX client** | Mock OPBX endpoints, test context lookup and result reporting |
| **Error handling** | OPBX unreachable, model load failure, invalid messages |

### 11.2 Laravel Backend Tests (PHP)

| Test Area | Tests |
|-----------|-------|
| **AMD result endpoint** | Valid result → correct Cloudonix API call |
| **AMD result - disconnect** | Result=voicemail + action=disconnect → Session Delete called |
| **AMD result - transfer** | Result=voicemail + action=transfer → Switch Voice App called |
| **AMD result - human** | Result=human → no Cloudonix API call, DB updated |
| **AMD result - idempotency** | Same call_sid reported twice → second is no-op |
| **AMD context endpoint** | Valid callSid → returns campaign config |
| **AMD context - unknown call** | Unknown callSid → 404 |
| **AMD auth middleware** | Invalid/missing token → 401 |
| **CXML generation** | Campaign with stream_amd_enabled → CXML includes `<Start><Stream>` |
| **Campaign validation** | stream_amd_enabled + transfer requires destination |
| **Stream status callback** | Webhook processes stream events correctly |

### 11.3 Integration Tests

| Test | Description |
|------|-------------|
| **End-to-end mock** | Simulate full flow: stream connect → audio → detection → OPBX callback → Cloudonix API mock |
| **Docker health** | AMD worker container starts, health endpoint responds |
| **Stream protocol** | WebSocket client sends Cloudonix-format messages, AMD processes correctly |

---

## 12. Monitoring & Observability

### 12.1 Structured Logging

All AMD worker logs include:

```json
{
    "level": "info",
    "ts": "2026-04-14T12:00:00Z",
    "caller": "stream/handler.ts:45",
    "msg": "Beep detected",
    "call_sid": "abc123",
    "stream_sid": "stream-xyz",
    "campaign_id": 42,
    "detector": "beep_ml",
    "confidence": 0.96,
    "detection_time_ms": 4200
}
```

### 12.2 Key Metrics (Health Endpoint)

| Metric | Type | Description |
|--------|------|-------------|
| `active_streams` | Gauge | Current number of active WebSocket streams |
| `max_streams` | Constant | Configured maximum |
| `total_detections` | Counter | Total detection results reported |
| `detection_breakdown` | Counter map | Results by type (voicemail/human/unknown) |
| `avg_detection_time_ms` | Gauge | Moving average detection time |
| `model_loaded` | Boolean | Whether ONNX model is loaded |
| `uptime_seconds` | Counter | Service uptime |
| `errors_total` | Counter | Total errors encountered |

### 12.3 OPBX Dashboard Integration (Future)

AMD detection statistics could be added to the Auto Dialer Monitor dashboard:
- Stream AMD detections per campaign
- Voicemail detection rate
- Average detection time
- False positive tracking (requires manual review mechanism)

---

## 13. Limitations & Known Constraints

1. **Audio format**: Cloudonix streams µ-law 8kHz. The reference model was trained on 16kHz audio. Resampling may introduce minor accuracy degradation.

2. **Model accuracy**: The reference model claims 96-98% accuracy on their training dataset. Real-world accuracy may vary depending on:
   - Voicemail systems in different countries/carriers
   - Background noise
   - Non-standard beep tones

3. **Detection latency**: The ML-based beep detector requires a complete speech segment (speech followed by silence) before analysis. This means detection happens AFTER the beep, not during it. Typical latency: 3-8 seconds after call answer.

4. **No bidirectional audio**: The stream is unidirectional (`inbound_track` only). AMD cannot hear what OPBX's AI assistant is saying to the callee.

5. **WebSocket via nginx**: The AMD WebSocket is proxied through nginx, adding minimal latency. Nginx must be configured to upgrade WebSocket connections on `/ws/amd/`. In development, the existing single ngrok tunnel handles both API and WebSocket traffic — no separate tunnel needed.

6. **Event loop blocking**: MFCC feature extraction and ONNX inference are CPU-intensive. These MUST run in a `worker_threads` pool (via `piscina`) to avoid blocking the Node.js event loop. Without this, concurrent stream processing would degrade.

7. **Concurrent stream limit**: Default limit of 100 per container, configurable via `AMD_MAX_CONCURRENT_STREAMS` environment variable. For higher volume, deploy multiple AMD worker instances (Cloudonix will round-robin or use the URL provided in CXML).

8. **Node.js memory**: The Node.js runtime has a higher baseline memory footprint (~80-150MB) compared to compiled alternatives. For 100 concurrent streams with worker threads, plan for 256-512MB container memory.

---

## 14. Future Enhancements (Out of Scope for V1)

- **Custom model training**: UI to upload voicemail audio samples and retrain the model
- **Per-country models**: Different beep detection models for different regions
- **Bidirectional analysis**: Analyze both tracks for more sophisticated detection
- **Real-time dashboard**: Live AMD detection visualization in the monitor
- **Webhook-based AMD**: Alternative to streaming for simpler deployments
- **Neural network models**: Replace GaussianNB with deep learning for higher accuracy
- **Silence pattern detector**: Additional detector plugin for silence-based machine detection
- **Speech duration detector**: Additional detector plugin for greeting length analysis
