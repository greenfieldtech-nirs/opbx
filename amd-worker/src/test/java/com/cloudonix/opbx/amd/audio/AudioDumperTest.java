package com.cloudonix.opbx.amd.audio;

import static org.junit.jupiter.api.Assertions.assertEquals;

import java.nio.file.Files;
import java.nio.file.Path;
import java.util.concurrent.TimeUnit;

import org.junit.jupiter.api.Test;
import org.junit.jupiter.api.io.TempDir;

import io.vertx.core.Vertx;

class AudioDumperTest {
    @TempDir
    Path dir;

    @Test
    void dumpIsCappedAtTheDetectionWindow() throws Exception {
        Vertx vertx = Vertx.vertx();
        try {
            AudioDumper dumper = new AudioDumper(true, dir.toString(), "CA1", "MZ1", 640);
            for (int i = 0; i < 10; i++) {
                dumper.append(new double[320]); // 20 ms each; only the first 640 samples are kept
            }

            dumper.finalizeDump(vertx).toCompletionStage().toCompletableFuture().get(5, TimeUnit.SECONDS);

            assertEquals(44 + 640 * 2, Files.size(dir.resolve("CA1_MZ1.wav")));
        } finally {
            vertx.close().toCompletionStage().toCompletableFuture().get(5, TimeUnit.SECONDS);
        }
    }
}
