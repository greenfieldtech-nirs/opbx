package com.cloudonix.opbx.amd.stream;

import com.cloudonix.opbx.amd.audio.AudioDecoder;
import com.cloudonix.opbx.amd.audio.AudioResampler;
import com.cloudonix.opbx.amd.audio.EnergyVad;
import com.cloudonix.opbx.amd.detector.*;
import com.cloudonix.opbx.amd.metrics.MetricsService;
import com.fasterxml.jackson.databind.ObjectMapper;
import io.vertx.core.Future;
import io.vertx.core.Vertx;
import io.vertx.core.http.ServerWebSocket;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;

public class StreamHandler {
    private static final Logger logger = LoggerFactory.getLogger(StreamHandler.class);

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
    private final Map<String, StreamSession> activeStreams = new ConcurrentHashMap<>();
    private final Map<ServerWebSocket, String> wsToStreamSid = new ConcurrentHashMap<>();
    private final ToneEnergyDetector toneEnergyDetector = new ToneEnergyDetector();

    public StreamHandler(Vertx vertx, int maxConcurrentStreams, int defaultTimeoutMs,
                         List<String> detectors, BeepMlDetector beepMlDetector,
                         MetricsService metrics, ObjectMapper mapper,
                         boolean dumpAudio, String dumpAudioPath, String apiToken) {
        this.vertx = vertx;
        this.maxConcurrentStreams = maxConcurrentStreams;
        this.defaultTimeoutMs = defaultTimeoutMs;
        this.detectors = detectors;
        this.beepMlDetector = beepMlDetector;
        this.metrics = metrics;
        this.mapper = mapper;
        this.dumpAudio = dumpAudio;
        this.dumpAudioPath = dumpAudioPath;
        this.apiToken = apiToken;
    }

    public void handleConnection(ServerWebSocket ws) {
        if (!"/ws/detect".equals(ws.path())) {
            ws.close((short) 1000, "Not Found");
            return;
        }
        // TODO: Re-enable auth check before production deployment
        // if (!apiToken.isEmpty() && !isAuthorized(ws)) {
        //     logger.warn("Unauthorized WebSocket connection from {} path={}", ws.remoteAddress(), ws.path());
        //     ws.close((short) 1008, "Unauthorized");
        //     metrics.incrementErrors();
        //     return;
        // }
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

        ws.closeHandler(v -> {
            String streamSid = wsToStreamSid.remove(ws);
            if (streamSid != null) {
                cleanupSession(streamSid);
            }
        });

        ws.textMessageHandler(text -> {
            StreamMessage msg = StreamMessage.parse(text, mapper);
            if (msg == null || msg.event == null) {
                logger.error("Failed to parse message: {}", text.substring(0, Math.min(text.length(), 200)));
                return;
            }
            switch (msg.event) {
                case "connected" -> handleConnected(msg);
                case "start" -> handleStart(ws, msg);
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
        String streamSid = msg.streamSid;
        String callSid = msg.start.callSid;
        logger.info("EVENT: start seq={} stream_sid={} call_sid={} session={} tracks={} custom_params={}",
            msg.sequenceNumber, streamSid, callSid, msg.start.session,
            msg.start.tracks, msg.start.customParameters);

        // Close any existing WebSocket for this streamSid to prevent duplicate streams corrupting VAD state
        ServerWebSocket oldWs = null;
        for (java.util.Map.Entry<ServerWebSocket, String> entry : wsToStreamSid.entrySet()) {
            if (streamSid.equals(entry.getValue())) {
                oldWs = entry.getKey();
                break;
            }
        }
        if (oldWs != null) {
            logger.warn("Closing duplicate stream call_sid={} stream_sid={}", callSid, streamSid);
            wsToStreamSid.remove(oldWs);
            activeStreams.remove(streamSid);
            try {
                oldWs.close();
            } catch (Exception e) {
                logger.debug("Error closing old websocket: {}", e.getMessage());
            }
        }

        StreamSession session = new StreamSession(
            vertx, callSid, streamSid, defaultTimeoutMs, detectors, beepMlDetector,
            dumpAudio, dumpAudioPath
        );

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
        activeStreams.put(streamSid, session);
        wsToStreamSid.put(ws, streamSid);
        metrics.incrementActiveStreams();

        logger.info("Stream started call_sid={} stream_sid={} timeout_ms={} detectors={} dump_audio={}",
            callSid, streamSid, defaultTimeoutMs, detectors, dumpAudio);
    }

    private void handleMedia(ServerWebSocket ws, StreamMessage msg) {
        StreamSession session = activeStreams.get(msg.streamSid);
        if (session == null || session.resolved.get()) {
            return;
        }

        logger.debug("EVENT: media seq={} stream_sid={} track={} chunk={} timestamp={} payload_bytes={}",
            msg.sequenceNumber, msg.streamSid, msg.media.track, msg.media.chunk,
            msg.media.timestamp, msg.media.payload != null ? msg.media.payload.length() : 0);

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

        // Run tone detector on rolling buffer to catch beeps that VAD may classify as silence.
        // Only enable after ~11.5s since the real voicemail beep is always around 12-13s.
        if (!session.resolved.get()
            && session.totalAudioMs >= 11500
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
        cleanupSession(session.streamSid);
        wsToStreamSid.remove(ws);
        if (!ws.isClosed()) {
            ws.close((short) 1000, "Timeout - no voicemail detected");
        }
    }

    private void logResultAndClose(ServerWebSocket ws, StreamSession session, DetectionResult result) {
        long elapsedMs = System.currentTimeMillis() - session.startTimeMs;
        metrics.recordDetection(result.result.value, elapsedMs);

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

        cleanupSession(session.streamSid);
        wsToStreamSid.remove(ws);
        if (!ws.isClosed()) {
            ws.close((short) 1000, "Detection complete");
        }
    }

    private void cleanupSession(String streamSid) {
        if (streamSid == null) return;
        StreamSession session = activeStreams.remove(streamSid);
        if (session != null) {
            session.dispose(vertx);
            metrics.decrementActiveStreams();
        }
    }

    private boolean isAuthorized(ServerWebSocket ws) {
        String authHeader = ws.headers().get("Authorization");
        if (authHeader == null || authHeader.isEmpty()) {
            return false;
        }
        String expected = "Bearer " + apiToken;
        // Use constant-time comparison to prevent timing attacks
        return constantTimeEquals(authHeader, expected);
    }

    private static boolean constantTimeEquals(String a, String b) {
        if (a == null || b == null) {
            return a == b;
        }
        byte[] aBytes = a.getBytes(java.nio.charset.StandardCharsets.UTF_8);
        byte[] bBytes = b.getBytes(java.nio.charset.StandardCharsets.UTF_8);
        int diff = aBytes.length ^ bBytes.length;
        int len = Math.min(aBytes.length, bBytes.length);
        for (int i = 0; i < len; i++) {
            diff |= aBytes[i] ^ bBytes[i];
        }
        return diff == 0;
    }
}
