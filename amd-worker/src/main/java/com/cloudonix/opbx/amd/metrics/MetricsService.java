package com.cloudonix.opbx.amd.metrics;

import java.util.concurrent.atomic.AtomicInteger;
import java.util.concurrent.atomic.AtomicLong;

public class MetricsService {
    private final AtomicInteger activeStreams = new AtomicInteger(0);
    private volatile int maxStreams = 0;
    private final AtomicLong totalDetections = new AtomicLong(0);
    private final AtomicLong voicemailDetections = new AtomicLong(0);
    private final AtomicLong humanDetections = new AtomicLong(0);
    private final AtomicLong unknownDetections = new AtomicLong(0);
    private final AtomicLong totalDetectionTimeMs = new AtomicLong(0);
    private volatile boolean modelLoaded = false;
    private final long startTime = System.currentTimeMillis();
    private final AtomicLong errorsTotal = new AtomicLong(0);

    public void setMaxStreams(int max) {
        this.maxStreams = max;
    }

    public void setModelLoaded(boolean loaded) {
        this.modelLoaded = loaded;
    }

    public void incrementActiveStreams() {
        activeStreams.incrementAndGet();
    }

    public void decrementActiveStreams() {
        activeStreams.decrementAndGet();
    }

    public void incrementErrors() {
        errorsTotal.incrementAndGet();
    }

    public void recordDetection(String result, long detectionTimeMs) {
        totalDetections.incrementAndGet();
        totalDetectionTimeMs.addAndGet(detectionTimeMs);
        switch (result) {
            case "voicemail" -> voicemailDetections.incrementAndGet();
            case "human" -> humanDetections.incrementAndGet();
            default -> unknownDetections.incrementAndGet();
        }
    }

    public MetricsSnapshot getMetrics() {
        long total = totalDetections.get();
        long avg = total > 0 ? totalDetectionTimeMs.get() / total : 0;
        return new MetricsSnapshot(
            activeStreams.get(),
            maxStreams,
            total,
            voicemailDetections.get(),
            humanDetections.get(),
            unknownDetections.get(),
            avg,
            modelLoaded,
            (System.currentTimeMillis() - startTime) / 1000,
            errorsTotal.get()
        );
    }

    public record MetricsSnapshot(
        int activeStreams,
        int maxStreams,
        long totalDetections,
        long voicemailDetections,
        long humanDetections,
        long unknownDetections,
        long avgDetectionTimeMs,
        boolean modelLoaded,
        long uptimeSeconds,
        long errorsTotal
    ) {}
}
