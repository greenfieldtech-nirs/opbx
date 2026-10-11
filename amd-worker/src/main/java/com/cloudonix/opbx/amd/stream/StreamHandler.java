package com.cloudonix.opbx.amd.stream;

import com.cloudonix.opbx.amd.audio.AudioDecoder;
import com.cloudonix.opbx.amd.audio.AudioResampler;
import com.cloudonix.opbx.amd.audio.EnergyVad;
import com.cloudonix.opbx.amd.detector.*;
import com.cloudonix.opbx.amd.metrics.MetricsService;
import com.fasterxml.jackson.databind.ObjectMapper;
import io.vertx.core.Future;
import io.vertx.core.Vertx;
import io.vertx.core.http.HttpClient;
import io.vertx.core.http.HttpClientOptions;
import io.vertx.core.http.HttpMethod;
import io.vertx.core.http.PoolOptions;
import io.vertx.core.http.RequestOptions;
import io.vertx.core.http.ServerWebSocket;
import io.vertx.core.json.JsonObject;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.TimeUnit;

public class StreamHandler {
    private static final Logger logger = LoggerFactory.getLogger(StreamHandler.class);
    static final long START_TIMEOUT_MS = 5000;
    private static final long SESSION_CHECK_TIMEOUT_MS = 2000;

    private final Vertx vertx;
    private final int maxConcurrentStreams;
    private final int defaultTimeoutMs;
    private final List<String> detectors;
    private final BeepMlDetector beepMlDetector;
    private final MetricsService metrics;
    private final ObjectMapper mapper;
    private final boolean dumpAudio;
    private final String dumpAudioPath;
    private final String apiToken;
    private final String actionCallbackUrl;
    private final String sessionCheckUrl;
    private final HttpClient httpClient;
    private final Map<String, StreamSession> activeStreams = new ConcurrentHashMap<>();
    private final Map<ServerWebSocket, String> wsToStreamSid = new ConcurrentHashMap<>();
    private final int minBeepStartMs;
    private final ToneEnergyDetector toneEnergyDetector;

    public StreamHandler(Vertx vertx, int maxConcurrentStreams, int defaultTimeoutMs,
                         List<String> detectors, BeepMlDetector beepMlDetector,
                         MetricsService metrics, ObjectMapper mapper,
                         boolean dumpAudio, String dumpAudioPath, String apiToken,
                         String actionCallbackUrl, String sessionCheckUrl, int minBeepStartMs) {
        this.vertx = vertx;
        this.minBeepStartMs = minBeepStartMs;
        this.toneEnergyDetector = new ToneEnergyDetector(minBeepStartMs);
        this.maxConcurrentStreams = maxConcurrentStreams;
        this.defaultTimeoutMs = defaultTimeoutMs;
        this.detectors = detectors;
        this.beepMlDetector = beepMlDetector;
        this.metrics = metrics;
        this.mapper = mapper;
        this.dumpAudio = dumpAudio;
        this.dumpAudioPath = dumpAudioPath;
        this.apiToken = apiToken;
        this.actionCallbackUrl = actionCallbackUrl;
        this.sessionCheckUrl = sessionCheckUrl;
        // One shared client for session checks and action callbacks (was one leaked client per callback).
        this.httpClient = vertx.createHttpClient(new HttpClientOptions(), new PoolOptions().setHttp1MaxSize(20));
    }

    public void handleConnection(ServerWebSocket ws) {
        if (!"/ws/detect".equals(ws.path())) {
            ws.close((short) 1000, "Not Found");
            return;
        }
        if (activeStreams.size() >= maxConcurrentStreams) {
            ws.close((short) 1013, "Max concurrent streams reached");
            metrics.incrementErrors();
            return;
        }

        ws.exceptionHandler(err -> {
            String streamSid = wsToStreamSid.get(ws);
            logger.error("WebSocket error stream_sid={} error={}", streamSid, err.getMessage(), err);
            metrics.incrementErrors();
        });

        // Cloudonix sends start right after connecting; idle sockets would hold stream slots.
        long startTimer = vertx.setTimer(START_TIMEOUT_MS, id -> {
            if (!wsToStreamSid.containsKey(ws) && !ws.isClosed()) {
                logger.warn("No start event within {} ms, closing remote={}", START_TIMEOUT_MS, ws.remoteAddress());
                metrics.incrementErrors();
                ws.close((short) 1008, "No start event");
            }
        });

        ws.closeHandler(v -> {
            vertx.cancelTimer(startTimer);
            String streamSid = wsToStreamSid.remove(ws);
            if (streamSid != null) {
                cleanupSession(streamSid);
            }
        });

        ws.textMessageHandler(text -> {
            // Log raw JSON for debugging, but skip media events (arrive every 20ms and are huge).
            if (!text.contains("\"event\":\"media\"")) {
                logger.info("RAW: {}", text);
            }
            StreamMessage msg = StreamMessage.parse(text, mapper);
            if (msg == null || msg.event == null) {
                logger.error("Failed to parse message: {}", text.substring(0, Math.min(text.length(), 200)));
                return;
            }
            switch (msg.event) {
                case "connected" -> handleConnected(msg);
                case "start" -> {
                    vertx.cancelTimer(startTimer);
                    handleStart(ws, msg);
                }
                case "media" -> handleMedia(ws, msg);
                case "stop" -> handleStop(ws, msg);
                case "dtmf" -> handleDtmf(msg);
                default -> logger.warn("Unknown event type={} seq={}", msg.event, msg.sequenceNumber);
            }
        });
    }

    private void handleConnected(StreamMessage msg) {
        logger.info("EVENT: connected protocol={} version={}", msg.protocol, msg.version);
    }

    private void handleStart(ServerWebSocket ws, StreamMessage msg) {
        if (msg.start == null || wsToStreamSid.containsKey(ws)) {
            logger.warn("Invalid or repeated start event stream_sid={} remote={}", msg.streamSid, ws.remoteAddress());
            metrics.incrementErrors();
            ws.close((short) 1008, "Invalid start");
            return;
        }
        String streamSid = msg.streamSid;
        String callSid = msg.start.callSid;
        org.slf4j.MDC.put("session_token", msg.start.session);
        org.slf4j.MDC.put("call_id", callSid);
        try {
            logger.info("EVENT: start seq={} stream_sid={} call_sid={} session={} tracks={} custom_params={}",
                msg.sequenceNumber, streamSid, callSid, msg.start.session,
                msg.start.tracks, msg.start.customParameters);
        } finally {
            org.slf4j.MDC.clear();
        }

        // Only 8 kHz is supported; other rates make resampleLinear allocate huge arrays.
        if (msg.start.mediaFormat != null && msg.start.mediaFormat.sampleRate != 8000) {
            logger.warn("Unsupported sample rate, closing call_sid={} stream_sid={} sample_rate={}",
                callSid, streamSid, msg.start.mediaFormat.sampleRate);
            metrics.incrementErrors();
            ws.close((short) 1003, "Unsupported sample rate");
            return;
        }

        // Extract action options from customParameters
        String sessionToken = msg.start.session;
        String actionHuman = null;
        String actionVoicemail = null;
        String actionUnknown = null;
        if (msg.start.customParameters != null) {
            actionHuman = (String) msg.start.customParameters.get("action_human");
            actionVoicemail = (String) msg.start.customParameters.get("action_voicemail");
            actionUnknown = (String) msg.start.customParameters.get("action_unknown");
        }

        StreamSession session = new StreamSession(
            vertx, callSid, streamSid, defaultTimeoutMs, detectors, beepMlDetector,
            dumpAudio, dumpAudioPath, minBeepStartMs, sessionToken, actionHuman, actionVoicemail, actionUnknown
        );

        // Atomically register this stream. If another connection already registered
        // the same streamSid, reject this new connection to prevent duplicate streams
        // from corrupting VAD state and producing duplicate log output.
        StreamSession existing = activeStreams.putIfAbsent(streamSid, session);
        if (existing != null) {
            logger.warn("Duplicate stream rejected call_sid={} stream_sid={} — already has active session",
                callSid, streamSid);
            ws.close((short) 1008, "Duplicate stream");
            return;
        }
        wsToStreamSid.put(ws, streamSid);

        if (msg.start.mediaFormat != null) {
            session.sampleRate = msg.start.mediaFormat.sampleRate;
            logger.info("Stream media format call_sid={} stream_sid={} encoding={} sample_rate={} channels={}",
                callSid, streamSid,
                msg.start.mediaFormat.encoding,
                msg.start.mediaFormat.sampleRate,
                msg.start.mediaFormat.channels);
        }

        session.onResultLogged = result -> {
            logResultAndClose(ws, session, result);
        };

        session.onTimeout = v -> {
            handleTimeout(ws, session);
        };

        session.startTimeoutTimer(vertx);
        metrics.incrementActiveStreams();
        verifySession(ws, session);

        logger.info("Stream started call_sid={} stream_sid={} timeout_ms={} detectors={} dump_audio={}",
            callSid, streamSid, defaultTimeoutMs, detectors, dumpAudio);
    }

    /**
     * Ask Laravel whether start.session is an active OPBX call session. Audio keeps flowing while
     * the check is in flight; sendActionCallback holds any result until it completes.
     */
    private void verifySession(ServerWebSocket ws, StreamSession session) {
        checkSessionActive(session.sessionToken).onComplete(ar -> {
            boolean active = ar.succeeded() && ar.result();
            session.sessionVerified = active;
            Runnable held = session.heldCallback;
            session.heldCallback = null;
            if (active) {
                logger.info("Session verified call_sid={} stream_sid={}", session.callSid, session.streamSid);
                if (held != null) {
                    held.run();
                }
                return;
            }
            logger.warn("Rejecting stream: session not verified call_sid={} stream_sid={} session_prefix={} reason={} held_result_dropped={}",
                session.callSid, session.streamSid, tokenPrefix(session.sessionToken),
                ar.failed() ? ar.cause().getMessage() : "not an active session", held != null);
            metrics.incrementErrors();
            if (activeStreams.get(session.streamSid) == session) {
                cleanupSession(session.streamSid);
            }
            wsToStreamSid.remove(ws);
            if (!ws.isClosed()) {
                ws.close((short) 1008, "Unknown session");
            }
        });
    }

    /** true = 200 {"active":true}, false = 404 or blank token; any other outcome fails. */
    private Future<Boolean> checkSessionActive(String sessionToken) {
        if (sessionToken == null || sessionToken.isBlank()) {
            return Future.succeededFuture(false);
        }
        if (sessionCheckUrl == null || sessionCheckUrl.isEmpty()) {
            return Future.failedFuture("session check URL not configured");
        }
        RequestOptions options = new RequestOptions()
            .setMethod(HttpMethod.POST)
            .setAbsoluteURI(sessionCheckUrl)
            .setConnectTimeout(SESSION_CHECK_TIMEOUT_MS)
            .setIdleTimeout(SESSION_CHECK_TIMEOUT_MS)
            .putHeader("Content-Type", "application/json")
            .putHeader("Accept", "application/json")
            .putHeader("Authorization", "Bearer " + apiToken);
        String body = new JsonObject().put("session", sessionToken).encode();
        return httpClient.request(options)
            .compose(req -> req.send(body))
            .compose(resp -> resp.body().map(buf -> switch (resp.statusCode()) {
                case 200 -> buf.toJsonObject().getBoolean("active", false);
                case 404 -> false;
                default -> throw new IllegalStateException("session check status " + resp.statusCode());
            }))
            .timeout(SESSION_CHECK_TIMEOUT_MS, TimeUnit.MILLISECONDS);
    }

    private static String tokenPrefix(String token) {
        if (token == null) return "";
        return token.substring(0, Math.min(8, token.length()));
    }

    private void handleMedia(ServerWebSocket ws, StreamMessage msg) {
        StreamSession session = activeStreams.get(msg.streamSid);
        if (session == null || session.resolved.get()) {
            return;
        }

        byte[] payload = AudioDecoder.decodeBase64(msg.media.payload);
        short[] pcm16 = AudioDecoder.decodeMulawToPcm16(payload);
        double[] float32_input = AudioDecoder.pcm16ToDouble(pcm16);
        double[] float32_16k = AudioResampler.resampleLinear(float32_input, session.sampleRate, 16000);

        double chunkDurationMs = (float32_16k.length / 16000.0) * 1000.0;
        double streamElapsedMs = System.currentTimeMillis() - session.startTimeMs;
        double chunkStartMs = Math.max(0, streamElapsedMs - chunkDurationMs);

        session.mediaChunkCounter++;
        session.totalAudioMs += chunkDurationMs;
        session.appendRawAudio(float32_16k);

        if (session.dumper.isEnabled()) {
            session.dumper.append(float32_16k);
        }

        // Run tone detector on rolling buffer to catch beeps that VAD may classify as silence
        // (the detector itself rejects tones starting before minBeepStartMs).
        if (!session.resolved.get()
            && session.totalAudioMs >= minBeepStartMs
            && session.totalAudioMs - session.lastToneCheckMs >= 500) {
            session.lastToneCheckMs = session.totalAudioMs;
            double[] recentAudio = session.getRecentAudio(1500);
            if (recentAudio.length >= 640) { // at least 40ms
                double checkStartMs = Math.max(0, session.totalAudioMs - 1500);
                Detector.AudioSegment toneSeg = new Detector.AudioSegment(
                    recentAudio, 16000, (recentAudio.length / 16000.0) * 1000.0, checkStartMs
                );
                DetectionResult toneResult = toneEnergyDetector.process(toneSeg);
                if (toneResult != null) {
                    logger.info("Tone detected in rolling buffer call_sid={} stream_sid={} start_ms={} reason=\"{}\"",
                        session.callSid, session.streamSid, (int) checkStartMs, toneResult.reason);
                    if (session.pipeline.resolveWithResult(toneResult)) {
                        logResultAndClose(ws, session, toneResult);
                    }
                }
            }
        }

        boolean shouldLog = session.mediaChunkCounter == 1
            || (System.currentTimeMillis() - session.lastMediaLogMs) >= 5000;
        if (shouldLog) {
            session.lastMediaLogMs = System.currentTimeMillis();
            logger.info("Receiving audio call_sid={} stream_sid={} chunks={} total_audio_ms={} elapsed_ms={}",
                session.callSid, session.streamSid, session.mediaChunkCounter, (int) session.totalAudioMs, (int) streamElapsedMs);
        }

        List<EnergyVad.VadSegment> segments = session.vad.process(float32_16k, chunkStartMs);
        if (!segments.isEmpty()) {
            logger.debug("VAD produced {} segment(s) call_sid={} stream_sid={}",
                segments.size(), session.callSid, session.streamSid);
        }
        processVadSegments(session, segments);
    }

    private void processVadSegments(StreamSession session, List<EnergyVad.VadSegment> segments) {
        for (EnergyVad.VadSegment segment : segments) {
            if (session.resolved.get()) {
                break;
            }
            double durationMs = segment.endMs() - segment.startMs();
            logger.debug("Processing VAD segment call_sid={} stream_sid={} duration_ms={} start_ms={}",
                session.callSid, session.streamSid, (int) durationMs, (int) segment.startMs());
            Detector.AudioSegment audioSeg = new Detector.AudioSegment(
                segment.pcmData(), 16000, durationMs, segment.startMs()
            );
            session.pipeline.processSegment(vertx, audioSeg).onComplete(ar -> {
                if (ar.succeeded() && session.pipeline.isResolved()) {
                    logger.info("Pipeline resolved for call_sid={} stream_sid={}", session.callSid, session.streamSid);
                } else if (ar.succeeded()) {
                    logger.debug("Pipeline completed segment with no detection call_sid={} stream_sid={}", session.callSid, session.streamSid);
                } else {
                    logger.warn("Pipeline failed for segment call_sid={} stream_sid={} error={}",
                        session.callSid, session.streamSid, ar.cause().getMessage());
                }
            });
        }
    }

    private void handleStop(ServerWebSocket ws, StreamMessage msg) {
        logger.info("EVENT: stop seq={} stream_sid={} call_sid={} session={}",
            msg.sequenceNumber, msg.streamSid, msg.stop.callSid, msg.stop.session);
        StreamSession session = activeStreams.get(msg.streamSid);
        if (session != null && !session.resolved.get()) {
            List<EnergyVad.VadSegment> flushed = session.vad.flush();
            if (!flushed.isEmpty()) {
                logger.info("Flushed {} VAD segments on stop for call_sid={}", flushed.size(), session.callSid);
            }
            processVadSegments(session, flushed);
        }
        // Give detectors a brief moment to process flushed segments before closing
        vertx.setTimer(300, id -> {
            cleanupSession(msg.streamSid);
            wsToStreamSid.remove(ws);
            if (!ws.isClosed()) {
                ws.close((short) 1000, "Stopped");
            }
        });
    }

    private void handleDtmf(StreamMessage msg) {
        logger.info("EVENT: dtmf seq={} stream_sid={} track={} digit={}",
            msg.sequenceNumber, msg.streamSid, msg.dtmf.track, msg.dtmf.digit);
    }

    private void handleTimeout(ServerWebSocket ws, StreamSession session) {
        if (session.resolved.get()) {
            return;
        }
        session.resolved.set(true);
        long elapsedMs = System.currentTimeMillis() - session.startTimeMs;
        metrics.recordDetection("unknown", elapsedMs);
        logger.info("DECISION: UNKNOWN (timeout) call_sid={} stream_sid={} elapsed_ms={} reason=\"No voicemail detected within timeout\"",
            session.callSid, session.streamSid, elapsedMs);
        sendActionCallback(session, "unknown", "No voicemail detected within timeout", 0.0, elapsedMs);
        cleanupSession(session.streamSid);
        wsToStreamSid.remove(ws);
        if (!ws.isClosed()) {
            ws.close((short) 1000, "Timeout - no voicemail detected");
        }
    }

    private void logResultAndClose(ServerWebSocket ws, StreamSession session, DetectionResult result) {
        long elapsedMs = System.currentTimeMillis() - session.startTimeMs;
        metrics.recordDetection(result.result.value, elapsedMs);

        // MDC tags land in the JSON log contract (session_token = audit key).
        // Set/clear around the log call only: Vert.x event-loop threads are
        // shared across streams, so MDC must never linger.
        org.slf4j.MDC.put("session_token", session.sessionToken);
        org.slf4j.MDC.put("call_id", session.callSid);
        try {

        if (result.result == ResultType.VOICEMAIL) {
            logger.info("DECISION: VOICEMAIL call_sid={} stream_sid={} detector={} confidence={} reason=\"{}\" detection_time_ms={}",
                session.callSid, session.streamSid, result.detector, result.confidence, result.reason, elapsedMs);
        } else if (result.result == ResultType.HUMAN) {
            logger.info("DECISION: HUMAN call_sid={} stream_sid={} detector={} confidence={} reason=\"{}\" detection_time_ms={}",
                session.callSid, session.streamSid, result.detector, result.confidence, result.reason, elapsedMs);
        } else {
            logger.info("DECISION: {} call_sid={} stream_sid={} detector={} confidence={} reason=\"{}\" detection_time_ms={}",
                result.result, session.callSid, session.streamSid, result.detector, result.confidence, result.reason, elapsedMs);
        }

        } finally {
            org.slf4j.MDC.clear();
        }

        sendActionCallback(session, result.result.value, result.reason, result.confidence, elapsedMs);

        cleanupSession(session.streamSid);
        wsToStreamSid.remove(ws);
        if (!ws.isClosed()) {
            ws.close((short) 1000, "Detection complete");
        }
    }

    /**
     * Send the AMD result to the Laravel callback endpoint.
     * The callback is fire-and-forget: we close the WebSocket regardless of success.
     */
    private void sendActionCallback(StreamSession session, String result, String reason,
                                     double confidence, long detectionTimeMs) {
        if (session.sessionVerified == null) {
            logger.info("Holding AMD result until session is verified call_sid={} stream_sid={} result={}",
                session.callSid, session.streamSid, result);
            session.heldCallback = () -> sendActionCallback(session, result, reason, confidence, detectionTimeMs);
            return;
        }
        if (!session.sessionVerified) {
            logger.warn("Dropping AMD result for unverified session call_sid={} stream_sid={} result={}",
                session.callSid, session.streamSid, result);
            return;
        }
        if (actionCallbackUrl == null || actionCallbackUrl.isEmpty()) {
            logger.debug("No action callback URL configured, skipping callback");
            return;
        }

        // Resolve action based on result type and configured options
        String action = resolveAction(session, result);

        String json = buildCallbackPayload(session, result, action, reason, confidence, detectionTimeMs);

        try {
            java.net.URI uri = new java.net.URI(actionCallbackUrl);
            String host = uri.getHost();
            int port = uri.getPort() != -1 ? uri.getPort() : ("https".equals(uri.getScheme()) ? 443 : 80);
            String path = uri.getRawPath();
            if (uri.getRawQuery() != null) {
                path += "?" + uri.getRawQuery();
            }

            org.slf4j.MDC.put("session_token", session.sessionToken);
            org.slf4j.MDC.put("call_id", session.callSid);
            try {
                logger.info("Sending AMD action callback to {}:{}{} call_sid={} result={} action={}",
                    host, port, path, session.callSid, result, action);
            } finally {
                org.slf4j.MDC.clear();
            }

            httpClient
                .request(io.vertx.core.http.HttpMethod.POST, port, host, path)
                .compose(req -> {
                    req.putHeader("Content-Type", "application/json");
                    req.putHeader("Authorization", "Bearer " + apiToken);
                    return req.send(json);
                })
                .onComplete(ar -> {
                    if (ar.succeeded()) {
                        var response = ar.result();
                        logger.info("AMD action callback sent call_sid={} result={} action={} status={}",
                            session.callSid, result, action, response.statusCode());
                        response.body(); // consume body to avoid resource leak
                    } else {
                        logger.warn("AMD action callback failed call_sid={} result={} action={} error={}",
                            session.callSid, result, action, ar.cause().getMessage());
                    }
                });
        } catch (java.net.URISyntaxException e) {
            logger.error("Invalid action callback URL: {} error={}", actionCallbackUrl, e.getMessage());
        }
    }

    private String resolveAction(StreamSession session, String result) {
        String configuredAction = switch (result) {
            case "voicemail" -> session.actionVoicemail;
            case "human" -> session.actionHuman;
            case "unknown" -> session.actionUnknown;
            default -> null;
        };

        if (configuredAction != null && !configuredAction.isEmpty()) {
            return configuredAction;
        }

        // Default behavior when no options are provided
        if ("voicemail".equals(result)) {
            return "HANGUP";
        }
        return "CONTINUE";
    }

    private String buildCallbackPayload(StreamSession session, String result, String action,
                                         String reason, double confidence, long detectionTimeMs) {
        return String.format(
            "{\"callSid\":\"%s\",\"streamSid\":\"%s\",\"session\":\"%s\",\"result\":\"%s\",\"action\":\"%s\",\"confidence\":%.2f,\"detectionTimeMs\":%d,\"reason\":\"%s\"}",
            escapeJson(session.callSid),
            escapeJson(session.streamSid),
            escapeJson(session.sessionToken != null ? session.sessionToken : ""),
            escapeJson(result),
            escapeJson(action),
            confidence,
            detectionTimeMs,
            escapeJson(reason != null ? reason : "")
        );
    }

    private static String escapeJson(String input) {
        if (input == null) return "";
        return input.replace("\\", "\\\\")
            .replace("\"", "\\\"")
            .replace("\n", "\\n")
            .replace("\r", "\\r")
            .replace("\t", "\\t");
    }

    private void cleanupSession(String streamSid) {
        if (streamSid == null) return;
        StreamSession session = activeStreams.remove(streamSid);
        if (session != null) {
            session.dispose(vertx);
            metrics.decrementActiveStreams();
        }
    }
}
