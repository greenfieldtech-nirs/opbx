package com.cloudonix.opbx.amd;

import com.cloudonix.opbx.amd.worker.AmdWorkerVerticle;
import io.vertx.core.Vertx;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class Main {

    public static void main(String[] args) {
        // Log level: AMD_LOG_LEVEL, read by logback.xml.
        Logger logger = LoggerFactory.getLogger(Main.class);
        logger.info("AMD Worker starting version=1.0.0");
        Config config = new Config();
        Vertx vertx = Vertx.vertx();
        // SIGTERM (docker stop): undeploy so stop() closes servers and clients; bounded under Docker's 10 s grace.
        Runtime.getRuntime().addShutdownHook(new Thread(() -> {
            try {
                vertx.close().toCompletionStage().toCompletableFuture().get(8, java.util.concurrent.TimeUnit.SECONDS);
            } catch (Exception e) {
                logger.warn("Shutdown did not complete cleanly error={}", e.toString());
            }
        }));
        AmdWorkerVerticle verticle = new AmdWorkerVerticle(config);
        vertx.deployVerticle(verticle).onComplete(ar -> {
            if (ar.succeeded()) {
                logger.info("AMD Worker deployed deployment_id={}", ar.result());
            } else {
                logger.error("AMD Worker failed to start error={}", ar.cause().getMessage(), ar.cause());
                vertx.close();
                System.exit(1);
            }
        });
    }
}
