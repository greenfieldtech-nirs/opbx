# Voicemail Detection (Stream AMD) - Implementation Workplan

## Document Information

| Field | Value |
|-------|-------|
| Feature | Stream-Based Voicemail Detection |
| Specification | [SPECIFICATION.md](./SPECIFICATION.md) |
| Created | 2026-04-14 |
| Estimated Phases | 5 |

---

## Phase 1: AMD Worker Foundation (Node.js)

**Goal**: Standalone Node.js/TypeScript service that accepts WebSocket connections, parses the Cloudonix stream protocol, and decodes audio.

### Tasks

- [ ] **1.1** Scaffold Node.js/TypeScript project (`amd-worker/`)
  - `npm init`, TypeScript configuration (`tsconfig.json`), ESLint setup
  - Directory structure per spec Section 4.2
  - `package.json` with scripts: `build`, `start`, `dev`, `test`, `lint`
  - Environment configuration loader (`src/config.ts`)
  - Key dependencies: `ws`, `onnxruntime-node`, `piscina`, `fft-js`

- [ ] **1.2** Implement Cloudonix stream protocol parser (`src/stream/protocol.ts`)
  - Parse JSON text frames: `connected`, `start`, `media`, `dtmf`, `stop`
  - TypeScript interfaces for each message type
  - Extract `callSid`, `session`, `streamSid`, `mediaFormat`, `customParameters`
  - Jest/Vitest unit tests with sample JSON payloads from Cloudonix documentation

- [ ] **1.3** Implement WebSocket server (`src/stream/handler.ts`)
  - Accept WebSocket upgrades on `/ws/detect` using the `ws` library
  - Per-connection session management (`src/stream/session.ts`)
  - Enforce `AMD_MAX_CONCURRENT_STREAMS` limit (reject with 503)
  - Graceful shutdown (drain active connections)
  - Connection lifecycle logging with `call_sid` correlation

- [ ] **1.4** Implement audio decoding pipeline (`src/audio/`)
  - `decoder.ts`: µ-law to 16-bit linear PCM conversion (lookup table, ~20 lines)
  - `resampler.ts`: 8kHz to 16kHz upsampling (linear interpolation)
  - `buffer.ts`: Frame buffering for continuous audio segments
  - Base64 decoding of `media.payload`
  - Unit tests: decode known µ-law samples, verify PCM output

- [ ] **1.5** Implement Voice Activity Detection (`src/audio/vad.ts`)
  - Energy-based VAD (pure TypeScript, no native dependencies)
  - Configurable parameters: silence threshold, min clip length, max clip length
  - Segment audio into speech/silence regions
  - Output: speech segments as `AudioSegment` objects
  - Unit tests with synthesized speech/silence audio

- [ ] **1.6** Implement health/metrics HTTP server (`src/metrics/`)
  - `GET /health` endpoint per spec Section 4.9
  - Track: active streams, total detections, detection breakdown, avg time, uptime
  - Simple in-memory counters (single-threaded event loop makes atomicity straightforward)

- [ ] **1.7** Dockerfile and docker-compose integration
  - Multi-stage Dockerfile per spec Section 8.3
  - Add `amd-worker` service to `docker-compose.yml` per spec Section 8.2
  - No external port exposure — all traffic proxied through nginx
  - Add `AMD_WORKER_API_TOKEN` and `AMD_MAX_CONCURRENT_STREAMS` env vars to `.env.example`
  - Verify container builds and starts with health check passing

- [ ] **1.8** Nginx reverse proxy configuration
  - Add `/ws/amd/` location block to `docker/nginx/conf.d/default.conf`
  - WebSocket upgrade headers (`Upgrade`, `Connection`)
  - Proxy to `amd-worker:8082` with `/ws/amd/` prefix stripped (proxy_pass `http://amd-worker:8082/ws/`)
  - 120s timeouts (matching max AMD detection timeout)
  - Place before catch-all `location /` block
  - Verify: WebSocket upgrade through nginx reaches AMD worker
  - Verify: existing routes (`/api/`, `/app/`, `/`) unaffected

### Deliverables
- AMD worker accepts WebSocket connections (via nginx proxy) and logs protocol messages
- Audio decoding pipeline produces PCM audio from Cloudonix stream
- Health endpoint responds
- Docker container builds and runs behind nginx
- Nginx correctly proxies WebSocket connections to AMD worker

### Tests
- Protocol parser unit tests
- Audio decoder unit tests
- VAD unit tests
- WebSocket connection acceptance integration test (via nginx proxy)
- Concurrent stream limit test
- Nginx routing test: `/ws/amd/detect` reaches AMD worker, other paths unaffected

---

## Phase 2: Detection Pipeline & ML Integration

**Goal**: Implement the pluggable detector pipeline with both V1 detectors (ML beep + energy tone).

### Tasks

- [ ] **2.1** Define detector interface (`src/detector/types.ts`)
  - `Detector` TypeScript interface per spec Section 4.3
  - `DetectionResult` interface with result type, confidence, detector name, timestamp
  - `AudioSegment` interface (Float32Array PCM data, sample rate, duration, timestamps)

- [ ] **2.2** Implement detection pipeline (`src/detector/pipeline.ts`)
  - Register multiple detectors
  - Route audio segments to all enabled detectors
  - Aggregation: first positive result wins (resolved flag — single-threaded event loop handles synchronization)
  - Timeout handling: report `unknown` after configured timeout
  - Reset all detectors for each new stream

- [ ] **2.3** MFCC feature extraction (`src/feature/mfcc.ts` + `src/feature/mfcc.worker.ts`)
  - Implement MFCC computation matching librosa defaults:
    - 40 coefficients (`n_mfcc=40`)
    - FFT size 2048, hop length 512
    - Mel filterbank with default parameters
    - Mean over time axis
  - Validation: compare output against librosa for known test audio
  - Unit tests with known input/output pairs
  - MFCC computation runs in `worker_threads` pool via `piscina` to avoid blocking the event loop

- [ ] **2.4** ONNX model integration (`src/model/onnx.ts`)
  - Load `.onnx` model file at startup via `onnxruntime-node` (`InferenceSession.create()`)
  - Run inference: input = float32[1, 40] (MFCC), output = int64 (0=beep, 1=speech)
  - Handle model load failure gracefully (log error, disable ML detector)
  - Jest/Vitest tests with pre-computed MFCC vectors and expected predictions

- [ ] **2.5** Convert scikit-learn model to ONNX
  - Write Python conversion script (one-time tool, documented in README)
  - Convert `GaussianNB-20190130T1233.pkl` from reference repo to ONNX
  - Validate converted model produces same predictions as original
  - Place `beep_detector.onnx` in `amd-worker/models/`

- [ ] **2.6** Implement beep ML detector (`src/detector/beep-ml.ts`)
  - Receives speech segments from VAD
  - Extracts MFCC features
  - Runs ONNX inference
  - Returns `ResultVoicemail` if prediction == 0 (beep) with confidence
  - Unit tests with known beep and speech audio samples

- [ ] **2.7** Implement energy analysis (`src/feature/energy.ts`)
  - Compute spectral energy in frequency bands
  - Sliding window analysis on PCM audio
  - Output: energy levels per frequency band per window

- [ ] **2.8** Implement energy-based tone detector (`src/detector/tone-energy.ts`)
  - Analyze spectral energy in 800Hz-2500Hz band
  - Detect pure tones: high energy in target band, low energy elsewhere
  - Minimum duration threshold (200ms)
  - Returns `ResultVoicemail` on beep tone detection
  - Unit tests with synthesized tones at various frequencies

- [ ] **2.9** Integration: wire pipeline into stream handler
  - Stream handler feeds decoded, resampled audio into VAD
  - VAD feeds segments into pipeline
  - Pipeline reports results
  - End-to-end test: mock WebSocket → stream protocol → detection

### Deliverables
- Both detectors operational
- ONNX model loaded and inference working
- Pipeline produces detection results from audio streams

### Tests
- MFCC extraction accuracy tests
- ONNX inference tests
- Beep detector end-to-end test
- Tone detector unit tests
- Pipeline aggregation tests (first result wins, timeout)
- Concurrent pipeline isolation tests

---

## Phase 3: OPBX Laravel Backend Integration

**Goal**: New API endpoints, CXML generation, and action execution logic in Laravel.

### Tasks

- [ ] **3.1** Database migration
  - Add columns to `auto_dialer_campaigns`:
    - `stream_amd_enabled` (boolean, default false)
    - `stream_amd_timeout` (integer nullable, default 30)
    - `stream_amd_on_voicemail` (string nullable, default 'disconnect')
    - `stream_amd_transfer_destination_type` (string nullable)
    - `stream_amd_transfer_destination_id` (unsignedBigInteger nullable)
  - Migration file: `add_stream_amd_fields_to_auto_dialer_campaigns`

- [ ] **3.2** Update `AutoDialerCampaign` model
  - Add new fields to `$fillable`
  - Add casts: `stream_amd_enabled` → boolean, `stream_amd_on_voicemail` → string
  - Validation: if `stream_amd_on_voicemail` == 'transfer', destination fields required
  - Update factory for tests

- [ ] **3.3** Implement AMD worker auth middleware (`VerifyAmdWorkerAuth`)
  - Mirror `VerifyDialerWorkerAuth` pattern
  - Check `Authorization: Bearer` against `AMD_WORKER_API_TOKEN` env var
  - Register middleware alias `amd.worker.auth`

- [ ] **3.4** Implement AMD worker API controller (`AmdWorkerController`)
  - `GET /api/v1/amd/worker/session/{callSid}`:
    - Look up `auto_dialer_call_sessions` by `call_id` = `callSid`
    - Join to campaign, verify `stream_amd_enabled`
    - Return campaign AMD config (timeout, action, destination, detectors)
    - Return 404 if not found or stream AMD not enabled
  - `POST /api/v1/amd/worker/result`:
    - Validate payload (call_sid, result, confidence, detector)
    - Idempotency: check Redis key `amd:result:{call_sid}`, set with 5min TTL
    - Dispatch to `AmdResultHandler` service
    - Return action taken

- [ ] **3.5** Register API routes
  - Route group: `prefix('amd/worker')`, middleware `amd.worker.auth`
  - `GET /session/{callSid}` → `AmdWorkerController@getSession`
  - `POST /result` → `AmdWorkerController@reportResult`

- [ ] **3.6** Implement `AmdResultHandler` service
  - Receives detection result for a call
  - Loads campaign and call session from database
  - Executes action based on result:
    - `voicemail` + `disconnect`: Call `CloudonixClient::disconnectSession()`
    - `voicemail` + `transfer`: Generate CXML for destination, call `CloudonixClient::switchVoiceApplication()`
    - `human` / `unknown`: No Cloudonix API call
  - Updates `auto_dialer_call_sessions` record: `amd_result`, `amd_confidence`
  - Structured logging with `call_sid` correlation

- [ ] **3.7** Add `switchVoiceApplication()` to `CloudonixClient`
  - New method: `POST /calls/{domain}/sessions/{token}/application`
  - Accepts either `cxml` or `url` parameter
  - Returns session details on success
  - Handles 400 (bad request) and 409 (conflict) errors
  - Circuit breaker wrapped (existing pattern)

- [ ] **3.8** Implement `AmdCxmlGenerator` service
  - Generates `<Start><Stream>` CXML fragment
  - Stream URL derived from `WebhookUrlResolver` base URL + `/ws/amd/detect` path
    - Scheme changed to `wss://` (or `ws://` for non-TLS development)
    - Same base URL as all other Cloudonix webhooks (ngrok URL in dev, production URL in prod)
  - Sets `track="inbound_track"`
  - Sets `statusCallback` to OPBX stream status endpoint (same base URL + `/api/webhooks/amd/stream-status`)
  - Sets `name="amd-stream"`
  - No separate AMD URL configuration needed — piggybacks on existing webhook URL resolution

- [ ] **3.9** Modify `AutoDialerCxmlBuilder`
  - When `campaign.stream_amd_enabled`:
    - Prepend `<Start><Stream>` before existing routing verbs
    - Use `AmdCxmlGenerator` for the fragment
  - Existing AMD (built-in) logic unchanged

- [ ] **3.10** Implement stream status callback endpoint
  - `POST /api/webhooks/amd/stream-status`
  - Middleware: `webhook.signature`
  - Log stream lifecycle events (`stream-started`, `stream-stopped`, `stream-error`)
  - On `stream-error`: log error, optionally notify (future enhancement)

- [ ] **3.11** Generate transfer CXML from destination selector
  - When `stream_amd_on_voicemail` == 'transfer':
    - Use `stream_amd_transfer_destination_type` + `_id` to resolve destination
    - Generate CXML using existing routing infrastructure (`VoiceRoutingManager` or `CxmlBuilder`)
    - Destinations: extension, ring_group, ivr_menu, conference_room, ai_assistant, ai_load_balancer, hangup
  - This reuses the existing OPBX destination routing system

- [ ] **3.12** Update campaign API validation
  - `AutoDialerCampaignRequest` (or existing form request):
    - `stream_amd_enabled`: boolean
    - `stream_amd_timeout`: integer, 3-120, nullable
    - `stream_amd_on_voicemail`: in:disconnect,transfer, required_if stream_amd_enabled
    - `stream_amd_transfer_destination_type`: required_if stream_amd_on_voicemail == transfer
    - `stream_amd_transfer_destination_id`: required_if stream_amd_on_voicemail == transfer
  - Validate destination exists and belongs to organization

### Deliverables
- AMD worker can query OPBX for campaign context
- AMD worker can report results to OPBX
- OPBX executes correct action on Cloudonix API
- CXML includes `<Start><Stream>` when stream AMD enabled

### Tests
- AMD context endpoint: valid call, unknown call, auth failure
- AMD result endpoint: disconnect action, transfer action, human result, duplicate result
- AmdResultHandler: mock Cloudonix API calls
- CXML generation: verify `<Start><Stream>` presence and attributes
- Campaign validation: stream AMD field validation rules
- switchVoiceApplication: mock Cloudonix API, error handling

---

## Phase 4: AMD Worker ↔ OPBX Integration

**Goal**: Wire the AMD worker's HTTP client to OPBX and complete the end-to-end flow.

### Tasks

- [ ] **4.1** Implement OPBX HTTP client in AMD worker (`src/api/client.ts`)
  - `getSessionContext(callSid: string): Promise<SessionContext>`
  - `reportResult(result: AmdResult): Promise<ActionResponse>`
  - Bearer token authentication from `AMD_WORKER_API_TOKEN`
  - HTTP timeout: 5 seconds
  - Retry logic for result reporting: 3 attempts, exponential backoff (1s, 2s, 4s)
  - HTTP agent with `keepAlive: false` (matches dialer worker pattern for nginx compatibility)

- [ ] **4.2** Integrate context lookup into stream handler
  - On `start` message: extract `callSid`, call `GetSessionContext()`
  - If 404: close WebSocket, log "unknown call"
  - If error: close WebSocket, log error
  - If success: configure pipeline with campaign settings (timeout, detectors)

- [ ] **4.3** Integrate result reporting into pipeline
  - When pipeline produces a result: call `ReportResult()`
  - On success: log action taken, close stream (detection complete)
  - On failure: log error, stream continues (call is unaffected)

- [ ] **4.4** Implement timeout behavior
  - Per-stream timer starts on `start` message
  - Timer duration from campaign config (`detection_timeout`)
  - On timeout: report `unknown` to OPBX, close stream
  - Timer cancelled if detection result produced before timeout

- [ ] **4.5** End-to-end integration test
  - Docker compose with AMD worker + nginx + Laravel + Redis
  - Simulate: WebSocket connection **through nginx** → Cloudonix protocol messages → AMD detection → OPBX callback (through nginx) → verify DB state
  - Use mock audio data (pre-recorded beep and speech samples in µ-law format)
  - Verify nginx WebSocket proxy handles connection lifecycle correctly

### Deliverables
- AMD worker calls OPBX for context and reports results
- Full flow from WebSocket to Cloudonix action
- Timeout handling works correctly

### Tests
- API client unit tests (mock OPBX responses)
- Context lookup integration test
- Result reporting integration test
- Timeout test
- End-to-end Docker integration test

---

## Phase 5: Frontend & Campaign UI

**Goal**: Add stream AMD configuration to the auto dialer campaign form.

### Tasks

- [ ] **5.1** Update campaign API response
  - Include new `stream_amd_*` fields in campaign resource/response
  - Update `AutoDialerCampaignResource` (or equivalent)

- [ ] **5.2** Update campaign TypeScript types
  - Add fields to campaign interface in `autoDialerCampaignsApi.ts`:
    ```typescript
    stream_amd_enabled: boolean;
    stream_amd_timeout: number | null;
    stream_amd_on_voicemail: 'disconnect' | 'transfer' | null;
    stream_amd_transfer_destination_type: string | null;
    stream_amd_transfer_destination_id: number | null;
    ```

- [ ] **5.3** Add Stream AMD section to campaign form
  - New collapsible section: "Stream-Based Voicemail Detection"
  - Toggle: "Enable Stream AMD"
  - When enabled:
    - Number input: "Detection Timeout (seconds)" (3-120, default 30)
    - Select: "On Voicemail Detected" → "Disconnect Call" / "Transfer Call"
    - Conditional: When "Transfer Call" selected, show destination selector
  - Destination selector: reuse existing `DestinationSelector` component
  - Independent of existing "AMD Settings" section

- [ ] **5.4** Zod validation schema
  - Add stream AMD fields to campaign form Zod schema
  - Conditional validation: transfer fields required when action is 'transfer'

- [ ] **5.5** Update campaign detail/listing views
  - Show stream AMD status in campaign detail page
  - Badge or indicator showing "Stream AMD: Enabled"

- [ ] **5.6** Add AMD metrics to monitor (optional, if time permits)
  - Show AMD detection stats in campaign drill-down monitor
  - AMD detection rate, average detection time

### Deliverables
- Campaign form allows configuring stream-based AMD
- Campaign detail shows AMD configuration
- Full CRUD works with stream AMD fields

### Tests
- Form renders correctly with stream AMD section
- Validation: required fields when enabled
- API round-trip: create campaign with stream AMD → read back → verify fields
- Destination selector works for transfer destination

---

## Phase Summary

| Phase | Description | Key Outcome |
|-------|-------------|-------------|
| **Phase 1** | AMD Worker Foundation | Node.js service accepts WebSocket, decodes audio |
| **Phase 2** | Detection Pipeline & ML | Beep + tone detection working with ONNX model |
| **Phase 3** | OPBX Backend Integration | API endpoints, CXML generation, action execution |
| **Phase 4** | Worker ↔ OPBX Wiring | End-to-end flow working in Docker |
| **Phase 5** | Frontend & Campaign UI | Campaign form for stream AMD configuration |

---

## Dependencies & Prerequisites

| Dependency | Phase | Notes |
|------------|-------|-------|
| Node.js 20+ / TypeScript | Phase 1 | Runtime and type system |
| `ws` npm package | Phase 1 | WebSocket server |
| `onnxruntime-node` npm package | Phase 2 | Pre-built native binaries, no CGO |
| `piscina` npm package | Phase 2 | Worker thread pool for CPU-bound MFCC/ONNX work |
| `fft-js` npm package | Phase 2 | FFT for MFCC computation |
| Scikit-learn model conversion | Phase 2 | One-time Python script, produces `.onnx` file |
| Test audio samples | Phase 2 | Beep tones and speech samples in µ-law 8kHz format |
| `AMD_WORKER_API_TOKEN` env var | Phase 3 | Add to `.env.example` |
| Nginx config update | Phase 1 | Add `/ws/amd/` WebSocket proxy location block |
| Existing ngrok tunnel | Phase 4 | Already tunnels to nginx:80 — no separate AMD tunnel needed |

---

## Risk Register

| Risk | Impact | Likelihood | Mitigation |
|------|--------|------------|------------|
| MFCC implementation in Node.js doesn't match librosa output | High | Medium | Validate against librosa output for known audio. Use `mfcc` npm package or custom implementation with pre-computed test vectors. |
| ONNX model conversion loses accuracy | High | Low | Validate predictions match original sklearn model. Use `skl2onnx` which is well-tested. |
| µ-law → 16kHz resampling degrades detection quality | Medium | Medium | Test with real voicemail recordings. Energy-based detector provides fallback. |
| Cloudonix stream WebSocket format differs from docs | High | Low | Test against real Cloudonix stream early. Protocol is Twilio-compatible, well-documented. |
| Nginx WebSocket proxy issues | Medium | Low | Pattern already proven with Soketi (`/app/`). Use same proxy headers. Test upgrade handshake early. |
| AMD WebSocket unreachable from Cloudonix in dev | Medium | Low | Uses existing ngrok→nginx tunnel. No separate tunnel needed. Same path as API webhooks. |
| Event loop blocking from CPU-bound MFCC/ONNX | Medium | Medium | Use `piscina` worker thread pool. Validate under load: 100 concurrent streams with simultaneous detections. |
| Node.js memory under high concurrency | Medium | Low | Profile memory at max concurrent streams (`AMD_MAX_CONCURRENT_STREAMS`). Set container memory limit to 512MB. Monitor via health endpoint. |
