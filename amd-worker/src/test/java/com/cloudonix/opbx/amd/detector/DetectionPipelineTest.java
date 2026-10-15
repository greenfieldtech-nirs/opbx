package com.cloudonix.opbx.amd.detector;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.util.List;
import java.util.concurrent.CountDownLatch;
import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicBoolean;
import java.util.concurrent.atomic.AtomicInteger;

import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;

import io.vertx.core.Vertx;

class DetectionPipelineTest {
    private final Vertx vertx = Vertx.vertx();

    @AfterEach
    void tearDown() throws Exception {
        vertx.close().toCompletionStage().toCompletableFuture().get(5, TimeUnit.SECONDS);
    }

    @Test
    void aLateInferenceAfterTheTimeoutSendsNoSecondResult() throws Exception {
        CountDownLatch inferenceRunning = new CountDownLatch(1);
        CountDownLatch release = new CountDownLatch(1);
        BeepMlDetector slowModel = new BeepMlDetector(null) {
            @Override
            public DetectionResult processAsync(AudioSegment segment) throws Exception {
                inferenceRunning.countDown();
                release.await(5, TimeUnit.SECONDS);
                return new DetectionResult("beep_ml", ResultType.VOICEMAIL, 0.95, "late", 0);
            }
        };
        AtomicBoolean resolved = new AtomicBoolean(false);
        AtomicInteger results = new AtomicInteger();
        DetectionPipeline pipeline = new DetectionPipeline(30_000, List.of("beep_ml"), slowModel, 2000, resolved,
            r -> results.incrementAndGet());

        var done = pipeline.processSegment(vertx, new Detector.AudioSegment(new double[16000], 16000, 1000, 3000));
        assertTrue(inferenceRunning.await(5, TimeUnit.SECONDS));
        assertTrue(resolved.compareAndSet(false, true), "the stream timeout claims the result first");
        release.countDown();

        done.toCompletionStage().toCompletableFuture().get(5, TimeUnit.SECONDS);
        assertEquals(0, results.get());
    }

    @Test
    void inferencesOfDifferentStreamsRunInParallel() throws Exception {
        CountDownLatch bothRunning = new CountDownLatch(2);
        BeepMlDetector model = new BeepMlDetector(null) {
            @Override
            public DetectionResult processAsync(AudioSegment segment) throws Exception {
                bothRunning.countDown();
                // Ordered executeBlocking would run the second only after this one returns.
                return bothRunning.await(5, TimeUnit.SECONDS) ? null : new DetectionResult("beep_ml",
                    ResultType.VOICEMAIL, 0.95, "serialized", 0);
            }
        };
        AtomicInteger results = new AtomicInteger();
        var context = vertx.getOrCreateContext();
        var first = new java.util.concurrent.CompletableFuture<Void>();
        var second = new java.util.concurrent.CompletableFuture<Void>();
        context.runOnContext(v -> {
            Detector.AudioSegment seg = new Detector.AudioSegment(new double[16000], 16000, 1000, 3000);
            new DetectionPipeline(30_000, List.of("beep_ml"), model, 2000, new AtomicBoolean(), r -> results.incrementAndGet())
                .processSegment(vertx, seg).onComplete(ar -> first.complete(null));
            new DetectionPipeline(30_000, List.of("beep_ml"), model, 2000, new AtomicBoolean(), r -> results.incrementAndGet())
                .processSegment(vertx, seg).onComplete(ar -> second.complete(null));
        });

        first.get(10, TimeUnit.SECONDS);
        second.get(10, TimeUnit.SECONDS);
        assertEquals(0, results.get());
    }
}
