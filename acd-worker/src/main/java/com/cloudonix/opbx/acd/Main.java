package com.cloudonix.opbx.acd;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import io.vertx.core.Vertx;

public class Main {

    public static void main(String[] args) {
        // Log level: ACD_LOG_LEVEL, read by logback.xml.
        Logger logger = LoggerFactory.getLogger(Main.class);
        logger.info("ACD Worker starting version=1.0.0");
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
        AcdWorkerVerticle verticle = new AcdWorkerVerticle(config);
        vertx.deployVerticle(verticle).onComplete(ar -> {
            if (ar.succeeded()) {
                logger.info("ACD Worker deployed deployment_id={}", ar.result());
            } else {
                logger.error("ACD Worker failed to start error={}", ar.cause().getMessage(), ar.cause());
                vertx.close();
                System.exit(1);
            }
        });
    }
}
