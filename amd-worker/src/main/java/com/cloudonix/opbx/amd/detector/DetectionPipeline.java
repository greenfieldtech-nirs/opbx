package com.cloudonix.opbx.amd.detector;

import io.vertx.core.Future;
import io.vertx.core.Promise;
import io.vertx.core.Vertx;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.ArrayList;
import java.util.List;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.function.Consumer;

public class DetectionPipeline {
    private static final Logger logger = LoggerFactory.getLogger(DetectionPipeline.class);

    private final List<Detector> detectors = new ArrayList<>();
    /** Shared with the stream's timeout and stop: whoever CASes it first owns the single callback. */
    private final AtomicBoolean resolved;
    private final int timeoutMs;
    private final Consumer<DetectionResult> onResult;

    public DetectionPipeline(int timeoutMs, List<String> detectorNames, BeepMlDetector beepMl, int minBeepStartMs,
                             AtomicBoolean resolved, Consumer<DetectionResult> onResult) {
        this.timeoutMs = timeoutMs;
        this.resolved = resolved;
        this.onResult = onResult;
        if (beepMl != null && detectorNames.contains("beep_ml")) {
            detectors.add(beepMl);
        }
        if (detectorNames.contains("tone_energy")) {
            detectors.add(new ToneEnergyDetector(minBeepStartMs));
        }
    }

    public int getTimeoutMs() {
        return timeoutMs;
    }

    public Future<Void> processSegment(Vertx vertx, Detector.AudioSegment segment) {
        if (resolved.get()) {
            return Future.succeededFuture();
        }
        Promise<Void> promise = Promise.promise();
        processNextDetector(vertx, segment, 0, promise);
        return promise.future();
    }

    private void processNextDetector(Vertx vertx, Detector.AudioSegment segment, int index, Promise<Void> promise) {
        if (resolved.get() || index >= detectors.size()) {
            promise.complete();
            return;
        }
        Detector detector = detectors.get(index);
        logger.debug("Running detector detector={} segment_duration_ms={} start_ms={}",
            detector.name(), (int) segment.durationMs, (int) segment.startTimestampMs);
        if (detector instanceof BeepMlDetector beepMl) {
            // Unordered: streams' inferences are independent and must not queue behind each other.
            vertx.executeBlocking(() -> {
                try {
                    return beepMl.processAsync(segment);
                } catch (Exception e) {
                    logger.warn("Beep ML detector failed: {}", e.getMessage(), e);
                    return null;
                }
            }, false).onComplete(ar -> {
                if (ar.succeeded() && ar.result() != null) {
                    logger.info("Detector positive detector={} result={} reason=\"{}\"",
                        detector.name(), ar.result().result, ar.result().reason);
                    handleResult(ar.result(), promise);
                    return;
                } else if (ar.succeeded()) {
                    logger.debug("Detector negative detector={}", detector.name());
                }
                processNextDetector(vertx, segment, index + 1, promise);
            });
        } else {
            DetectionResult result = detector.process(segment);
            if (result != null && result.result != ResultType.UNKNOWN) {
                logger.info("Detector positive detector={} result={} reason=\"{}\"",
                    detector.name(), result.result, result.reason);
                handleResult(result, promise);
            } else {
                logger.debug("Detector negative detector={}", detector.name());
                processNextDetector(vertx, segment, index + 1, promise);
            }
        }
    }

    private void handleResult(DetectionResult result, Promise<Void> promise) {
        if (resolved.compareAndSet(false, true)) {
            onResult.accept(result);
        }
        promise.complete();
    }

    public boolean isResolved() {
        return resolved.get();
    }

    public boolean resolveWithResult(DetectionResult result) {
        if (resolved.compareAndSet(false, true)) {
            onResult.accept(result);
            return true;
        }
        return false;
    }
}
