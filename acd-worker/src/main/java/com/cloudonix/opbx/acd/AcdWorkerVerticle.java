package com.cloudonix.opbx.acd;

import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.List;
import java.util.Map;

import com.cloudonix.opbx.acd.model.AgentInfo;
import com.cloudonix.opbx.acd.model.AgentState;
import com.cloudonix.opbx.acd.store.QueueStore;
import com.cloudonix.opbx.acd.store.RedisQueueStore;
import com.fasterxml.jackson.databind.JsonNode;
import com.fasterxml.jackson.databind.ObjectMapper;

import io.vertx.core.AbstractVerticle;
import io.vertx.core.Future;
import io.vertx.core.Promise;
import io.vertx.core.http.HttpServer;
import io.vertx.ext.web.Router;
import io.vertx.ext.web.RoutingContext;
import io.vertx.ext.web.handler.BodyHandler;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

/**
 * ACD worker verticle: REST API for queue state + health endpoint.
 *
 * All /queue/*, /events, /agents routes require Bearer token auth.
 */
public class AcdWorkerVerticle extends AbstractVerticle {
    private static final Logger logger = LoggerFactory.getLogger(AcdWorkerVerticle.class);
    private static final ObjectMapper mapper = new ObjectMapper();

    private final Config config;
    private QueueEngine engine;

    public AcdWorkerVerticle(Config config) {
        this.config = config;
    }

    @Override
    public void start(Promise<Void> startPromise) {
        RedisQueueStore.create(vertx, config.redisUri())
                .onSuccess(store -> {
                    this.engine = new QueueEngine(store, Time.system());
                    startServers().onComplete(startPromise);
                })
                .onFailure(cause -> {
                    logger.error("Failed to connect to Redis at {}: {}", config.redisUri(), cause.getMessage(), cause);
                    startPromise.fail(cause);
                });
    }

    private Future<Void> startServers() {
        Router router = Router.router(vertx);
        router.route().handler(BodyHandler.create());

        // Authenticated API routes
        router.route("/queue/*").handler(this::requireAuth);
        router.route("/events").handler(this::requireAuth);
        router.route("/agents/*").handler(this::requireAuth);

        router.post("/queue/enqueue").handler(this::handleEnqueue);
        router.post("/queue/poll").handler(this::handlePoll);
        router.post("/queue/live").handler(this::handleLive);
        router.post("/events").handler(this::handleEvent);
        router.post("/agents/state").handler(this::handleAgentState);

        HttpServer apiServer = vertx.createHttpServer().requestHandler(router);
        Future<HttpServer> apiFuture = apiServer.listen(config.httpPort)
                .onSuccess(server -> logger.info("ACD API listening on port {}", config.httpPort));

        HttpServer healthServer = vertx.createHttpServer()
                .requestHandler(req -> req.response().setStatusCode(200).end("ok"));
        Future<HttpServer> healthFuture = healthServer.listen(config.healthPort)
                .onSuccess(server -> logger.info("ACD health listening on port {}", config.healthPort));

        return Future.all(apiFuture, healthFuture).mapEmpty();
    }

    // ==========================================================================
    // Auth
    // ==========================================================================

    private void requireAuth(RoutingContext ctx) {
        String header = ctx.request().getHeader("Authorization");
        if (config.apiToken.isEmpty() || header == null || !header.startsWith("Bearer ")) {
            unauthorized(ctx);
            return;
        }
        String token = header.substring("Bearer ".length());
        if (!MessageDigest.isEqual(token.getBytes(StandardCharsets.UTF_8),
                config.apiToken.getBytes(StandardCharsets.UTF_8))) {
            unauthorized(ctx);
            return;
        }
        ctx.next();
    }

    private void unauthorized(RoutingContext ctx) {
        ctx.response().setStatusCode(401).putHeader("Content-Type", "application/json")
                .end("{\"error\":\"unauthorized\"}");
    }

    // ==========================================================================
    // Handlers
    // ==========================================================================

    private void handleEnqueue(RoutingContext ctx) {
        JsonNode body = body(ctx);
        if (body == null || missing(body, "orgId", "queueId", "callId")) {
            badRequest(ctx);
            return;
        }

        engine.enqueue(body.get("orgId").asText(), body.get("queueId").asText(), body.get("callId").asText())
                .onSuccess(position -> json(ctx, 200, Map.of("position", position)))
                .onFailure(cause -> serverError(ctx, cause));
    }

    private void handlePoll(RoutingContext ctx) {
        JsonNode body = body(ctx);
        if (body == null || missing(body, "orgId", "queueId", "callId", "strategy", "maxWaitSeconds")
                || !body.hasNonNull("agents") || !body.get("agents").isArray()) {
            badRequest(ctx);
            return;
        }

        List<AgentInfo> roster = parseRoster(body.get("agents"));
        engine.poll(
                body.get("orgId").asText(),
                body.get("queueId").asText(),
                body.get("callId").asText(),
                roster,
                body.get("strategy").asText(),
                body.get("maxWaitSeconds").asLong())
                .onSuccess(result -> json(ctx, 200, result))
                .onFailure(cause -> serverError(ctx, cause));
    }

    private void handleLive(RoutingContext ctx) {
        JsonNode body = body(ctx);
        if (body == null || missing(body, "orgId", "queueId")) {
            badRequest(ctx);
            return;
        }

        List<AgentInfo> roster = body.hasNonNull("agents") && body.get("agents").isArray()
                ? parseRoster(body.get("agents"))
                : List.of();

        engine.live(body.get("orgId").asText(), body.get("queueId").asText(), roster)
                .onSuccess(result -> json(ctx, 200, result))
                .onFailure(cause -> serverError(ctx, cause));
    }

    private void handleEvent(RoutingContext ctx) {
        JsonNode body = body(ctx);
        if (body == null || missing(body, "orgId", "queueId", "callId", "type")) {
            badRequest(ctx);
            return;
        }

        engine.event(
                body.get("orgId").asText(),
                body.get("queueId").asText(),
                body.get("callId").asText(),
                body.get("type").asText(),
                body.hasNonNull("agentUserId") ? body.get("agentUserId").asText() : null,
                body.hasNonNull("talkSeconds") ? body.get("talkSeconds").asLong() : null,
                body.hasNonNull("wrapUpSeconds") ? body.get("wrapUpSeconds").asLong() : null)
                .onSuccess(v -> ctx.response().setStatusCode(204).end())
                .onFailure(cause -> serverError(ctx, cause));
    }

    private void handleAgentState(RoutingContext ctx) {
        JsonNode body = body(ctx);
        if (body == null || missing(body, "orgId", "queueId", "userId", "state")) {
            badRequest(ctx);
            return;
        }

        AgentState state;
        try {
            // Laravel sends lowercase state names; Java enum constants are uppercase.
            state = AgentState.valueOf(body.get("state").asText().toUpperCase(java.util.Locale.ROOT));
        } catch (IllegalArgumentException e) {
            badRequest(ctx);
            return;
        }

        engine.setAgentState(
                body.get("orgId").asText(),
                body.get("queueId").asText(),
                body.get("userId").asText(),
                state,
                body.hasNonNull("talkSecondsTotal") ? body.get("talkSecondsTotal").asLong() : null,
                body.hasNonNull("callsHandledTotal") ? body.get("callsHandledTotal").asLong() : null,
                body.hasNonNull("wrapUpSeconds") ? body.get("wrapUpSeconds").asLong() : null)
                .onSuccess(v -> ctx.response().setStatusCode(204).end())
                .onFailure(cause -> serverError(ctx, cause));
    }

    // ==========================================================================
    // Helpers
    // ==========================================================================

    private JsonNode body(RoutingContext ctx) {
        try {
            if (ctx.body().isEmpty()) {
                return null;
            }
            return mapper.readTree(ctx.body().buffer().getBytes());
        } catch (Exception e) {
            return null;
        }
    }

    private boolean missing(JsonNode body, String... fields) {
        for (String field : fields) {
            if (!body.hasNonNull(field)) {
                return true;
            }
        }
        return false;
    }

    private List<AgentInfo> parseRoster(JsonNode agents) {
        List<AgentInfo> roster = new java.util.ArrayList<>();
        for (JsonNode agent : agents) {
            if (agent.hasNonNull("userId") && agent.hasNonNull("extensionNumber")) {
                roster.add(new AgentInfo(agent.get("userId").asText(), agent.get("extensionNumber").asText(), 0, 0));
            }
        }
        return roster;
    }

    private void json(RoutingContext ctx, int status, Map<String, Object> payload) {
        try {
            ctx.response().setStatusCode(status).putHeader("Content-Type", "application/json")
                    .end(mapper.writeValueAsString(payload));
        } catch (Exception e) {
            serverError(ctx, e);
        }
    }

    private void badRequest(RoutingContext ctx) {
        ctx.response().setStatusCode(400).putHeader("Content-Type", "application/json")
                .end("{\"error\":\"invalid_request\"}");
    }

    private void serverError(RoutingContext ctx, Throwable cause) {
        logger.error("Request failed: {}", cause.getMessage(), cause);
        ctx.response().setStatusCode(500).putHeader("Content-Type", "application/json")
                .end("{\"error\":\"internal_error\"}");
    }
}
