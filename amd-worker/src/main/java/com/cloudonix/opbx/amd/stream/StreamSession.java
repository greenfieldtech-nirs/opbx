package com.cloudonix.opbx.amd.stream;

import com.cloudonix.opbx.amd.audio.AudioDumper;
import com.cloudonix.opbx.amd.audio.EnergyVad;
import com.cloudonix.opbx.amd.detector.DetectionResult;
import com.cloudonix.opbx.amd.detector.DetectionPipeline;
import io.vertx.core.Vertx;

import java.util.concurrent.atomic.AtomicBoolean;
import java.util.function.Consumer;

/**
 * Per-stream session state. Created when a Cloudonix stream starts and
 * destroyed when the stream ends or a detection decision is reached.
 *
 * Holds:
 * - VAD instance for speech segmentation
 * - Detection pipeline (ML + energy-based detectors)
 * - Timeout timer for the overall detection window
 * - Rolling audio buffer for tone detection fallback
 * - Audio dumper for optional debugging
 *
 * Memory safety: the rolling audio buffer is capped at 5 seconds
 * (~80 KB at 16 kHz) to prevent unbounded growth.
 */
public class StreamSession {
    public final String callSid;
    public final String streamSid;
    public final long startTimeMs;
    public final DetectionPipeline pipeline;
    public final EnergyVad vad;
    public final AudioDumper dumper;
    public final AtomicBoolean resolved = new AtomicBoolean(false);
    public volatile long timeoutTimerId = -1;
    public Consumer<Void> onTimeout;
    public Consumer<DetectionResult> onResultLogged;
    public volatile int mediaChunkCounter = 0;
    public volatile double totalAudioMs = 0;
    public volatile long lastMediaLogMs = 0;
    public volatile int sampleRate = 8000;
    public volatile double lastToneCheckMs = 0;

    private final java.util.List<double[]> rawAudioChunks = new java.util.ArrayList<>();
    private static final int MAX_RETAINED_AUDIO_MS = 5000; // Keep last 5 seconds for rolling buffer checks
    private static final int MAX_RETAINED_SAMPLES = (int) ((MAX_RETAINED_AUDIO_MS / 1000.0) * 16000);
    private int totalRetainedSamples = 0;

    /**
     * Appends an audio chunk to the rolling buffer.
     * Oldest chunks are evicted if the total retained audio exceeds 5 seconds.
     */
    public void appendRawAudio(double[] chunk) {
        rawAudioChunks.add(chunk.clone());
        totalRetainedSamples += chunk.length;
        // Evict oldest chunks to stay within memory bounds
        while (totalRetainedSamples > MAX_RETAINED_SAMPLES && !rawAudioChunks.isEmpty()) {
            double[] removed = rawAudioChunks.remove(0);
            totalRetainedSamples -= removed.length;
        }
    }

    /**
     * Returns the most recent audio up to the requested duration.
     * Chunks are concatenated in reverse order (newest first).
     */
    public double[] getRecentAudio(double durationMs) {
        int samplesNeeded = (int) Math.ceil((durationMs / 1000.0) * 16000);
        double[] result = new double[samplesNeeded];
        int pos = samplesNeeded;
        for (int i = rawAudioChunks.size() - 1; i >= 0 && pos > 0; i--) {
            double[] chunk = rawAudioChunks.get(i);
            int toCopy = Math.min(chunk.length, pos);
            System.arraycopy(chunk, chunk.length - toCopy, result, pos - toCopy, toCopy);
            pos -= toCopy;
        }
        if (pos > 0) {
            double[] trimmed = new double[samplesNeeded - pos];
            System.arraycopy(result, pos, trimmed, 0, trimmed.length);
            return trimmed;
        }
        return result;
    }

    public StreamSession(Vertx vertx, String callSid, String streamSid, int timeoutMs,
                           java.util.List<String> detectors, com.cloudonix.opbx.amd.detector.BeepMlDetector beepMl,
                           boolean dumpAudio, String dumpAudioPath) {
        this.callSid = callSid;
        this.streamSid = streamSid;
        this.startTimeMs = System.currentTimeMillis();
        this.vad = new EnergyVad(16000, 10, 200, 3000, 3, 20);
        this.pipeline = new DetectionPipeline(timeoutMs, detectors, beepMl, result -> {
            if (onResultLogged != null) {
                onResultLogged.accept(result);
            }
        });
        this.dumper = new AudioDumper(dumpAudio, dumpAudioPath, callSid, streamSid);
    }

    public void startTimeoutTimer(Vertx vertx) {
        clearTimeout(vertx);
        this.timeoutTimerId = vertx.setTimer(pipeline.getTimeoutMs(), id -> {
            if (!resolved.get() && onTimeout != null) {
                onTimeout.accept(null);
            }
        });
    }

    public void clearTimeout(Vertx vertx) {
        if (timeoutTimerId != -1) {
            vertx.cancelTimer(timeoutTimerId);
            timeoutTimerId = -1;
        }
    }

    public void dispose(Vertx vertx) {
        clearTimeout(vertx);
        resolved.set(true);
        if (dumper != null && dumper.isEnabled()) {
            dumper.finalizeDump();
        }
    }
}
