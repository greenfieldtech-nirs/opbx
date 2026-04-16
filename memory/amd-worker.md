# AMD Worker (Stream-Based Voicemail Detection)

## Overview
Standalone Java/Vert.x 5 microservice that receives real-time audio streams from Cloudonix via `<Start><Stream>` WebSocket, analyzes audio for voicemail beep tones using ML + energy-based detectors, and logs detection results locally. No OPBX HTTP callbacks in current implementation (planned for Phase 4).

## Source Files

| File | Purpose |
|------|---------|
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/Main.java` | Entry point: creates Vert.x instance, deploys `AmdWorkerVerticle` |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/Config.java` | Environment variable configuration loader |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/stream/StreamMessage.java` | Cloudonix Stream WebSocket message parser/types |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/stream/StreamSession.java` | Per-stream session state + 45s timeout management |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/stream/StreamHandler.java` | WebSocket connection lifecycle, audio routing to VAD/pipeline |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/audio/AudioDecoder.java` | µ-law to PCM 16-bit + float32 conversion |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/audio/AudioResampler.java` | 8kHz to 16kHz linear interpolation resampling |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/audio/EnergyVad.java` | Energy-based Voice Activity Detection |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/feature/MfccExtractor.java` | MFCC feature extraction (40 coeffs, librosa-like params) |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/feature/EnergyAnalyzer.java` | Spectral energy band analysis for tone detection |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/detector/Detector.java` | Detector interface |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/detector/DetectionResult.java` | Detection result payload |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/detector/ResultType.java` | Result type enum (`VOICEMAIL`, `HUMAN`, `UNKNOWN`) |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/detector/BeepMlDetector.java` | ONNX-based ML beep detector |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/detector/ToneEnergyDetector.java` | Energy-based pure-tone detector (800-2500Hz) |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/detector/DetectionPipeline.java` | Pluggable detector pipeline (first positive wins) |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/model/OnnxModel.java` | ONNX Runtime model loader + inference wrapper |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/metrics/MetricsService.java` | In-memory health/metrics tracking |
| `amd-worker/src/main/java/com/cloudonix/opbx/amd/worker/AmdWorkerVerticle.java` | Verticle: starts WS + HTTP servers, wires detectors |
| `amd-worker/pom.xml` | Maven build with Shade plugin for uber-JAR |
| `amd-worker/Dockerfile` | Multi-stage Maven build + `eclipse-temurin:21-jre` runtime |
| `amd-worker/scripts/generate_model.py` | Python script to generate `beep_detector.onnx` |
| `amd-worker/models/beep_detector.onnx` | Pre-trained GaussianNB ONNX model |

## Infrastructure

| Component | Config |
|-----------|--------|
| Nginx proxy | `location /ws/amd/` → `amd-worker:8082/ws/` (WebSocket upgrade, 120s timeout) |
| Docker service | `amd-worker` in `docker-compose.yml`, no external ports |
| Health endpoint | `GET :8083/health` |
| WebSocket endpoint | `ws://amd-worker:8082/ws/detect` (internal), exposed via `wss://{public}/ws/amd/detect` |

## Environment Variables

| Variable | Default | Description |
|----------|---------|-------------|
| `AMD_WEBSOCKET_PORT` | `8082` | WebSocket listener port |
| `AMD_HTTP_PORT` | `8083` | Health/metrics HTTP port |
| `AMD_MODEL_PATH` | `./models/beep_detector.onnx` | ONNX model file path |
| `AMD_MAX_CONCURRENT_STREAMS` | `100` | Max simultaneous streams |
| `AMD_LOG_LEVEL` | `info` | Logging level (slf4j-simple) |
| `AMD_DETECTORS` | `beep_ml,tone_energy` | Enabled detectors |
| `AMD_DUMP_AUDIO` | `false` | Debug: dump each stream to a WAV file |
| `AMD_DUMP_AUDIO_PATH` | `/tmp/amd-dumps` | Directory for debug WAV files |

**Note:** `AMD_DEFAULT_TIMEOUT_SECONDS` is hardcoded to `45` in `Config.java`.

## Audio Debug Dumps
When `AMD_DUMP_AUDIO=true`, every received stream is written to a 16kHz mono PCM16 WAV file at `AMD_DUMP_AUDIO_PATH`. The filename includes `callSid` and `streamSid` for easy correlation with logs. This is useful for offline analysis of detection accuracy.

## Call Flow (Simplified)
1. Cloudonix connects WebSocket to public URL `/ws/amd/detect` (via nginx)
2. `StreamHandler` parses `connected` → `start` → `media` → `stop` messages
3. Audio decoded (µ-law → PCM → 16kHz resample)
4. VAD segments audio into speech/silence regions
5. Pipeline runs detectors on completed speech segments
6. **First positive result wins**: logs `Voicemail detected` or `Human detected`
7. If no result after **45 seconds**: logs timeout and closes connection
8. No OPBX HTTP callbacks in current implementation

## Key Fixes & Safety Measures
- **`AtomicBoolean` `resolved` flag** prevents race conditions on concurrent detector results
- **`OnnxTensor` try-with-resources** ensures tensor memory is released after inference
- **`OnnxModel.close()`** properly closes ONNX session and environment on shutdown
- **`ws.closeHandler`** triggers cleanup to remove stale session mappings
- **`EnergyVad` buffer fix** tracks `currentSpeechBuffer` so `flush()` can emit trailing speech segments up to `clipMaxMs` (3000ms)

## Dependencies
- Cloudonix `<Start><Stream>` CXML verb for stream initiation
- Nginx WebSocket proxy (`/ws/amd/`)
- Vert.x 5.0.10 (WebSocket + HTTP server)
- Jackson 2.19.0 (JSON parsing)
- ONNX Runtime 1.22.0 (ML inference)
- JTransforms 3.1 (FFT for MFCC + energy analysis)
- slf4j-simple 2.0.16 (logging)

## Console Logging
The worker logs key events at `INFO` level so you can follow the decision process in real time:

| Log line | What it means |
|----------|---------------|
| `Stream started call_sid=...` | New Cloudonix stream opened |
| `Receiving audio call_sid=... chunks=N total_audio_ms=X elapsed_ms=Y` | Audio is flowing (throttled to once per ~5s) |
| `VAD speech start at_ms=...` | Energy VAD detected the start of speech |
| `VAD speech end duration_ms=...` | Energy VAD detected end of speech and emitted a segment |
| `VAD segment clipped ...` | Ongoing speech hit the 3000ms max and was split |
| `Processing VAD segment call_sid=... duration_ms=...` | A completed segment is being sent to detectors |
| `Running detector detector=... segment_duration_ms=...` | A specific detector is analyzing the segment |
| `Detector positive detector=... result=VOICEMAIL reason="..."` | A detector found a beep/tone |
| `Detector negative detector=...` | A detector found nothing in this segment |
| `DECISION: VOICEMAIL call_sid=... detector=...` | Final result — voicemail beep detected |
| `DECISION: HUMAN call_sid=... detector=...` | Final result — human speech detected |
| `DECISION: UNKNOWN (timeout) call_sid=...` | 45s elapsed with no detection |
| `Stream stopped by Cloudonix call_sid=...` | Cloudonix sent a `stop` event |

## Build & Run
```bash
cd amd-worker
mvn package -DskipTests -B          # Build shaded JAR
docker compose up -d amd-worker      # Run via Docker Compose
```

(End of file)
