package com.cloudonix.opbx.acd;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import io.vertx.core.Vertx;

public class Main {

    public static void main(String[] args) {
        // Configure slf4j-simple log level from env var BEFORE creating any logger.
        String logLevel = System.getenv("ACD_LOG_LEVEL");
        if (logLevel != null && !logLevel.isEmpty()) {
            System.setProperty("org.slf4j.simpleLogger.defaultLogLevel", logLevel);
        }

        Logger logger = LoggerFactory.getLogger(Main.class);
        logger.info("ACD Worker starting version=1.0.0");
        Config config = new Config();
        Vertx vertx = Vertx.vertx();
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
