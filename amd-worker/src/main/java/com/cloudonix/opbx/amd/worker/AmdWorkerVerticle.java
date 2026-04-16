package com.cloudonix.opbx.amd.worker;

import com.cloudonix.opbx.amd.Config;
import com.cloudonix.opbx.amd.detector.BeepMlDetector;
import com.cloudonix.opbx.amd.metrics.MetricsService;
import com.cloudonix.opbx.amd.model.OnnxModel;
import com.cloudonix.opbx.amd.stream.StreamHandler;
import com.fasterxml.jackson.databind.ObjectMapper;
import io.vertx.core.AbstractVerticle;
import io.vertx.core.Future;
import io.vertx.core.Promise;
import io.vertx.core.http.HttpServer;
import io.vertx.core.http.HttpServerOptions;
import io.vertx.core.json.JsonObject;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class AmdWorkerVerticle extends AbstractVerticle {
    private static final Logger logger = LoggerFactory.getLogger(AmdWorkerVerticle.class);

    private final Config config;
    private final ObjectMapper mapper = new ObjectMapper();
    private final MetricsService metrics = new MetricsService();
    private HttpServer wsServer;
    private HttpServer httpServer;
    private OnnxModel onnxModel;
    private StreamHandler streamHandler;

    public AmdWorkerVerticle(Config config) {
        this.config = config;
    }

    @Override
    public void start(Promise<Void> startPromise) {
        metrics.setMaxStreams(config.maxConcurrentStreams);

        onnxModel = new OnnxModel(config.modelPath);
        BeepMlDetector beepMlDetector = null;
        try {
            onnxModel.load();
            metrics.setModelLoaded(true);
            beepMlDetector = new BeepMlDetector(onnxModel);
            logger.info("ONNX model loaded path={}", config.modelPath);
        } catch (Exception e) {
            metrics.setModelLoaded(false);
            logger.error("Failed to load ONNX model, ML detector disabled path={} error={}", config.modelPath, e.getMessage());
        }

        streamHandler = new StreamHandler(
            vertx,
            config.maxConcurrentStreams,
            config.defaultTimeoutSeconds * 1000,
            config.detectors,
            beepMlDetector,
            metrics,
            mapper
        );

        wsServer = vertx.createHttpServer(new HttpServerOptions().setPort(config.websocketPort));
        wsServer.webSocketHandler(streamHandler::handleConnection);

        httpServer = vertx.createHttpServer(new HttpServerOptions().setPort(config.httpPort));
        httpServer.requestHandler(req -> {
            if (req.method().name().equals("GET") && "/health".equals(req.path())) {
                MetricsService.MetricsSnapshot m = metrics.getMetrics();
                JsonObject json = new JsonObject()
                    .put("status", "healthy")
                    .put("model_loaded", m.modelLoaded())
                    .put("active_streams", m.activeStreams())
                    .put("max_streams", m.maxStreams())
                    .put("total_detections", m.totalDetections())
                    .put("detection_breakdown", new JsonObject()
                        .put("voicemail", m.voicemailDetections())
                        .put("human", m.humanDetections())
                        .put("unknown", m.unknownDetections()))
                    .put("avg_detection_time_ms", m.avgDetectionTimeMs())
                    .put("uptime_seconds", m.uptimeSeconds());
                req.response().putHeader("Content-Type", "application/json").end(json.encode());
            } else {
                req.response().setStatusCode(404).end("Not Found");
            }
        });

        Future<HttpServer> wsFuture = wsServer.listen().mapEmpty();
        Future<HttpServer> httpFuture = httpServer.listen().mapEmpty();

        Future.all(wsFuture, httpFuture).onComplete(ar -> {
            if (ar.succeeded()) {
                logger.info("AMD Worker started websocket_port={} http_port={} max_streams={} timeout_seconds={} detectors={}",
                    config.websocketPort, config.httpPort, config.maxConcurrentStreams,
                    config.defaultTimeoutSeconds, config.detectors);
                startPromise.complete();
            } else {
                startPromise.fail(ar.cause());
            }
        });
    }

    @Override
    public void stop(Promise<Void> stopPromise) {
        Future<? > wsClose = wsServer != null ? wsServer.close() : Future.succeededFuture();
        Future<? > httpClose = httpServer != null ? httpServer.close() : Future.succeededFuture();
        Future.all(wsClose, httpClose).onComplete(ar -> {
            if (onnxModel != null) {
                onnxModel.close();
            }
            stopPromise.complete();
        });
    }
}
