# AMD Worker (Stream-Based Voicemail Detection)

## Overview
Standalone Node.js/TypeScript microservice that receives real-time audio streams from Cloudonix via `<Start><Stream>` WebSocket, analyzes audio for voicemail beep tones using ML + energy-based detectors, and logs detection results.

## Source Files

| File | Purpose |
|------|---------|
| `amd-worker/src/index.ts` | Entry point: loads ONNX model, starts WebSocket + HTTP servers |
| `amd-worker/src/config.ts` | Environment variable configuration loader |
| `amd-worker/src/stream/protocol.ts` | Cloudonix Stream WebSocket message parser/types |
| `amd-worker/src/stream/session.ts` | Per-stream session state + 45s timeout management |
| `amd-worker/src/stream/handler.ts` | WebSocket connection lifecycle, audio routing to VAD/pipeline |
| `amd-worker/src/audio/decoder.ts` | µ-law to PCM 16-bit + float32 conversion |
| `amd-worker/src/audio/resampler.ts` | 8kHz to 16kHz linear interpolation resampling |
| `amd-worker/src/audio/vad.ts` | Energy-based Voice Activity Detection |
| `amd-worker/src/audio/buffer.ts` | Simple audio frame buffer |
| `amd-worker/src/feature/mfcc.ts` | MFCC feature extraction (40 coeffs, librosa-like params) |
| `amd-worker/src/feature/mfcc.worker.ts` | Piscina worker thread entry for MFCC computation |
| `amd-worker/src/feature/energy.ts` | Spectral energy band analysis for tone detection |
| `amd-worker/src/detector/types.ts` | Detector interface + result type definitions |
| `amd-worker/src/detector/pipeline.ts` | Pluggable detector pipeline (first positive wins) |
| `amd-worker/src/detector/beep-ml.ts` | ONNX-based ML beep detector |
| `amd-worker/src/detector/tone-energy.ts` | Energy-based pure-tone detector (800-2500Hz) |
| `amd-worker/src/model/onnx.ts` | ONNX Runtime model loader + inference wrapper |
| `amd-worker/src/metrics/metrics.ts` | In-memory health/metrics tracking |
| `amd-worker/src/types/fft-js.d.ts` | Type declarations for `fft-js` |
| `amd-worker/Dockerfile` | Multi-stage Node.js 20 build |
| `amd-worker/package.json` | Dependencies: `ws`, `onnxruntime-node`, `piscina`, `fft-js` |
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
| `AMD_MAX_CONCURRENT_STREAMS` | `100` | Max simultaneous streams (configurable via `.env`) |
| `AMD_DEFAULT_TIMEOUT_SECONDS` | `45` | Hardcoded stream timeout |
| `AMD_LOG_LEVEL` | `info` | Logging level |
| `AMD_DETECTORS` | `beep_ml,tone_energy` | Enabled detectors |

## Call Flow (Simplified)
1. Cloudonix connects WebSocket to public URL `/ws/amd/detect` (via nginx)
2. `handler.ts` parses `connected` → `start` → `media` → `stop` messages
3. Audio decoded (µ-law → PCM → 16kHz resample)
4. VAD segments audio into speech/silence regions
5. Pipeline runs detectors on completed speech segments
6. **First positive result wins**: logs `Voicemail detected` or `Human detected`
7. If no result after **45 seconds**: logs `WebSocket closed - no voicemail detected` and closes connection
8. No OPBX HTTP callbacks in current implementation (planned for Phase 4)

## Dependencies
- Cloudonix `<Start><Stream>` CXML verb for stream initiation
- Nginx WebSocket proxy (`/ws/amd/`)
- `onnxruntime-node` for ML inference
- `piscina` for worker-thread MFCC extraction
