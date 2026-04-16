package com.cloudonix.opbx.amd.stream;

import com.cloudonix.opbx.amd.audio.EnergyVad;
import com.cloudonix.opbx.amd.detector.DetectionResult;
import com.cloudonix.opbx.amd.detector.DetectionPipeline;
import io.vertx.core.Vertx;

import java.util.concurrent.atomic.AtomicBoolean;
import java.util.function.Consumer;

public class StreamSession {
    public final String callSid;
    public final String streamSid;
    public final long startTimeMs;
    public final DetectionPipeline pipeline;
    public final EnergyVad vad;
    public final AtomicBoolean resolved = new AtomicBoolean(false);
    public volatile long timeoutTimerId = -1;
    public Consumer<Void> onTimeout;
    public Consumer<DetectionResult> onResultLogged;

    public StreamSession(Vertx vertx, String callSid, String streamSid, int timeoutMs,
                         java.util.List<String> detectors, com.cloudonix.opbx.amd.detector.BeepMlDetector beepMl) {
        this.callSid = callSid;
        this.streamSid = streamSid;
        this.startTimeMs = System.currentTimeMillis();
        this.vad = new EnergyVad(16000, 10, 200, 3000, 3, 20);
        this.pipeline = new DetectionPipeline(timeoutMs, detectors, beepMl, result -> {
            if (onResultLogged != null) {
                onResultLogged.accept(result);
            }
        });
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
    }
}
