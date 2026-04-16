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
    private final AtomicBoolean resolved = new AtomicBoolean(false);
    private final int timeoutMs;
    private final Consumer<DetectionResult> onResult;

    public DetectionPipeline(int timeoutMs, List<String> detectorNames, BeepMlDetector beepMl, Consumer<DetectionResult> onResult) {
        this.timeoutMs = timeoutMs;
        this.onResult = onResult;
        if (beepMl != null && detectorNames.contains("beep_ml")) {
            detectors.add(beepMl);
        }
        if (detectorNames.contains("tone_energy")) {
            detectors.add(new ToneEnergyDetector());
        }
    }

    public void reset() {
        resolved.set(false);
        for (Detector d : detectors) {
            d.reset();
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
        if (detector instanceof BeepMlDetector beepMl) {
            vertx.executeBlocking(() -> {
                try {
                    return beepMl.processAsync(segment);
                } catch (Exception e) {
                    logger.warn("Beep ML detector failed: {}", e.getMessage(), e);
                    return null;
                }
            }).onComplete(ar -> {
                if (ar.succeeded() && ar.result() != null) {
                    handleResult(ar.result(), promise);
                    return;
                }
                processNextDetector(vertx, segment, index + 1, promise);
            });
        } else {
            DetectionResult result = detector.process(segment);
            if (result != null && result.result != ResultType.UNKNOWN) {
                handleResult(result, promise);
            } else {
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
}
