package com.cloudonix.opbx.amd;

import com.cloudonix.opbx.amd.worker.AmdWorkerVerticle;
import io.vertx.core.Vertx;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class Main {
    private static final Logger logger = LoggerFactory.getLogger(Main.class);

    public static void main(String[] args) {
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
