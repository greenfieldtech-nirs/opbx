package com.cloudonix.opbx.amd;

import com.cloudonix.opbx.amd.worker.AmdWorkerVerticle;
import io.vertx.core.Vertx;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class Main {

    public static void main(String[] args) {
        // Configure slf4j-simple log level from env var BEFORE creating any logger.
        // slf4j-simple reads org.slf4j.simpleLogger.defaultLogLevel system property.
        String logLevel = System.getenv("AMD_LOG_LEVEL");
        if (logLevel != null && !logLevel.isEmpty()) {
            System.setProperty("org.slf4j.simpleLogger.defaultLogLevel", logLevel);
        }

        Logger logger = LoggerFactory.getLogger(Main.class);
        logger.info("AMD Worker starting version=1.0.0");
        Config config = new Config();
        Vertx vertx = Vertx.vertx();
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
