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
    private final Map<String, StreamSession> activeStreams = new ConcurrentHashMap<>();
    private final Map<ServerWebSocket, String> wsToStreamSid = new ConcurrentHashMap<>();

    public StreamHandler(Vertx vertx, int maxConcurrentStreams, int defaultTimeoutMs,
                         List<String> detectors, BeepMlDetector beepMlDetector,
                         MetricsService metrics, ObjectMapper mapper) {
        this.vertx = vertx;
        this.maxConcurrentStreams = maxConcurrentStreams;
        this.defaultTimeoutMs = defaultTimeoutMs;
        this.detectors = detectors;
        this.beepMlDetector = beepMlDetector;
        this.metrics = metrics;
        this.mapper = mapper;
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
                case "connected" -> logger.info("Stream connected protocol={} version={}", msg.protocol, msg.version);
                case "start" -> handleStart(ws, msg);
                case "media" -> handleMedia(ws, msg);
                case "stop" -> handleStop(ws, msg);
                case "dtmf" -> { /* ignored */ }
                default -> logger.warn("Unknown event: {}", msg.event);
            }
        });
    }

    private void handleStart(ServerWebSocket ws, StreamMessage msg) {
        String streamSid = msg.streamSid;
        String callSid = msg.start.callSid;

        StreamSession session = new StreamSession(
            vertx, callSid, streamSid, defaultTimeoutMs, detectors, beepMlDetector
        );

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

        logger.info("Stream started call_sid={} stream_sid={} timeout_ms={} detectors={}",
            callSid, streamSid, defaultTimeoutMs, detectors);
    }

    private void handleMedia(ServerWebSocket ws, StreamMessage msg) {
        StreamSession session = activeStreams.get(msg.streamSid);
        if (session == null || session.resolved.get()) {
            return;
        }

        byte[] payload = AudioDecoder.decodeBase64(msg.media.payload);
        short[] pcm16 = AudioDecoder.decodeMulawToPcm16(payload);
        double[] float32_8k = AudioDecoder.pcm16ToDouble(pcm16);
        double[] float32_16k = AudioResampler.resampleLinear(float32_8k, 8000, 16000);

        double chunkDurationMs = (float32_16k.length / 16000.0) * 1000.0;
        double streamElapsedMs = System.currentTimeMillis() - session.startTimeMs;
        double chunkStartMs = Math.max(0, streamElapsedMs - chunkDurationMs);

        List<EnergyVad.VadSegment> segments = session.vad.process(float32_16k, chunkStartMs);
        processVadSegments(session, segments);
    }

    private void processVadSegments(StreamSession session, List<EnergyVad.VadSegment> segments) {
        for (EnergyVad.VadSegment segment : segments) {
            if (session.resolved.get()) {
                break;
            }
            double durationMs = segment.endMs() - segment.startMs();
            logger.info("VAD segment call_sid={} stream_sid={} duration_ms={}", session.callSid, session.streamSid, durationMs);
            Detector.AudioSegment audioSeg = new Detector.AudioSegment(
                segment.pcmData(), 16000, durationMs, segment.startMs()
            );
            session.pipeline.processSegment(vertx, audioSeg).onComplete(ar -> {
                if (ar.succeeded() && session.pipeline.isResolved()) {
                    logger.info("Pipeline resolved for call_sid={} stream_sid={}", session.callSid, session.streamSid);
                }
            });
        }
    }

    private void handleStop(ServerWebSocket ws, StreamMessage msg) {
        logger.info("Stream stopped by Cloudonix call_sid={} stream_sid={}",
            msg.stop.callSid, msg.streamSid);
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

    private void handleTimeout(ServerWebSocket ws, StreamSession session) {
        if (session.resolved.get()) {
            return;
        }
        session.resolved.set(true);
        logger.info("WebSocket closed - no voicemail detected call_sid={} stream_sid={} elapsed_ms={}",
            session.callSid, session.streamSid, System.currentTimeMillis() - session.startTimeMs);
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
            logger.info("Voicemail detected call_sid={} stream_sid={} detector={} confidence={} reason=\"{}\" detection_time_ms={}",
                session.callSid, session.streamSid, result.detector, result.confidence, result.reason, elapsedMs);
        } else if (result.result == ResultType.HUMAN) {
            logger.info("Human detected call_sid={} stream_sid={} detector={} confidence={} reason=\"{}\" detection_time_ms={}",
                session.callSid, session.streamSid, result.detector, result.confidence, result.reason, elapsedMs);
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
}
