package com.cloudonix.opbx.amd.stream;

import com.cloudonix.opbx.amd.Config;
import com.cloudonix.opbx.amd.metrics.MetricsService;
import com.fasterxml.jackson.databind.ObjectMapper;
import io.vertx.core.Context;
import io.vertx.core.Future;
import io.vertx.core.Vertx;
import io.vertx.core.VerticleBase;
import io.vertx.core.http.HttpServerRequest;
import io.vertx.core.http.WebSocket;
import io.vertx.core.json.JsonObject;
import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import java.util.List;
import java.util.concurrent.BlockingQueue;
import java.util.concurrent.CompletableFuture;
import java.util.concurrent.LinkedBlockingQueue;
import java.util.concurrent.TimeUnit;

import static org.junit.jupiter.api.Assertions.*;

/**
 * Drives StreamHandler over a real WebSocket against a fake Laravel that holds the
 * session-check response until the test releases it.
 */
class StreamHandlerAuthTest {
    private static final String TOKEN = "tok-abcdefghijklmnop";

    private Vertx vertx;
    private int laravelPort;
    /** Session-check requests waiting for the test to answer, with the context to answer on. */
    private final BlockingQueue<Pending> sessionChecks = new LinkedBlockingQueue<>();
    private final BlockingQueue<JsonObject> actionCallbacks = new LinkedBlockingQueue<>();

    private record Pending(HttpServerRequest req, JsonObject body, String auth, Context ctx) {
        void respond(int status, String json) {
            ctx.runOnContext(v -> req.response().setStatusCode(status)
                .putHeader("Content-Type", "application/json").end(json));
        }
    }

    @BeforeEach
    void setUp() throws Exception {
        vertx = Vertx.vertx();
        laravelPort = await(vertx.createHttpServer().requestHandler(req -> {
            Context ctx = Vertx.currentContext();
            req.body().onSuccess(buf -> {
                JsonObject body = buf.toJsonObject();
                if (req.path().equals("/api/voice/amd-session")) {
                    sessionChecks.add(new Pending(req, body, req.getHeader("Authorization"), ctx));
                } else {
                    actionCallbacks.add(body);
                    req.response().end("{}");
                }
            });
        }).listen(0)).actualPort();
    }

    @AfterEach
    void tearDown() throws Exception {
        await(vertx.close());
    }

    @Test
    void sessionCheckUrlIsDerivedFromActionUrl() {
        assertEquals("http://nginx/api/voice/amd-session",
            Config.deriveSessionCheckUrl("http://nginx/api/voice/amd-action", null));
        assertEquals("https://x/check",
            Config.deriveSessionCheckUrl("http://nginx/api/voice/amd-action", "https://x/check"));
        assertEquals("http://nginx/api/voice/amd-session",
            Config.deriveSessionCheckUrl("http://nginx/api/voice/amd-action", " "));
        assertEquals("", Config.deriveSessionCheckUrl("http://nginx/api/voice/other", null));
    }

    @Test
    void resultIsHeldUntilVerifiedThenSent() throws Exception {
        WebSocket ws = connect(startAmd(200));
        CompletableFuture<Short> closed = closeCode(ws);
        await(ws.writeTextMessage(start(8000)));

        Pending check = sessionChecks.poll(5, TimeUnit.SECONDS);
        assertNotNull(check);
        assertEquals(TOKEN, check.body().getString("session"));
        assertEquals("Bearer test-token", check.auth());

        // 200 ms detection timeout produced an UNKNOWN result and closed the stream.
        assertEquals((short) 1000, closed.get(5, TimeUnit.SECONDS));
        assertNull(actionCallbacks.poll(300, TimeUnit.MILLISECONDS), "callback sent before verification");

        check.respond(200, "{\"active\":true}");
        JsonObject action = actionCallbacks.poll(5, TimeUnit.SECONDS);
        assertNotNull(action, "held callback not sent after verification");
        assertEquals("unknown", action.getString("result"));
        assertEquals(TOKEN, action.getString("session"));
    }

    @Test
    void heldResultIsDroppedOn404() throws Exception {
        WebSocket ws = connect(startAmd(200));
        CompletableFuture<Short> closed = closeCode(ws);
        await(ws.writeTextMessage(start(8000)));

        Pending check = sessionChecks.poll(5, TimeUnit.SECONDS);
        assertNotNull(check);
        assertEquals((short) 1000, closed.get(5, TimeUnit.SECONDS));

        check.respond(404, "{}");
        assertNull(actionCallbacks.poll(1, TimeUnit.SECONDS), "callback sent for unknown session");
    }

    @Test
    void unknownSessionIsClosedWith1008() throws Exception {
        WebSocket ws = connect(startAmd(30_000));
        CompletableFuture<Short> closed = closeCode(ws);
        await(ws.writeTextMessage(start(8000)));

        sessionChecks.poll(5, TimeUnit.SECONDS).respond(404, "{}");
        assertEquals((short) 1008, closed.get(5, TimeUnit.SECONDS));
        assertNull(actionCallbacks.poll(300, TimeUnit.MILLISECONDS));
    }

    @Test
    void unsupportedSampleRateIsClosedWith1003() throws Exception {
        WebSocket ws = connect(startAmd(30_000));
        CompletableFuture<Short> closed = closeCode(ws);
        await(ws.writeTextMessage(start(1)));

        assertEquals((short) 1003, closed.get(5, TimeUnit.SECONDS));
        assertNull(sessionChecks.poll(300, TimeUnit.MILLISECONDS), "rejected stream must not be checked");
    }

    @Test
    void connectionWithoutStartIsClosed() throws Exception {
        WebSocket ws = connect(startAmd(30_000));
        CompletableFuture<Short> closed = closeCode(ws);

        assertEquals((short) 1008, closed.get(StreamHandler.START_TIMEOUT_MS + 3000, TimeUnit.MILLISECONDS));
    }

    /** Deploys a StreamHandler on its own verticle context, as AmdWorkerVerticle does; returns the WS port. */
    private int startAmd(int detectionTimeoutMs) throws Exception {
        String base = "http://localhost:" + laravelPort + "/api/voice/amd-action";
        int[] port = new int[1];
        await(vertx.deployVerticle(new VerticleBase() {
            @Override
            public Future<?> start() {
                StreamHandler handler = new StreamHandler(vertx, 10, detectionTimeoutMs, List.of("tone_energy"),
                    null, new MetricsService(), new ObjectMapper(), false, "/tmp", "test-token",
                    base, Config.deriveSessionCheckUrl(base, null));
                return vertx.createHttpServer().webSocketHandler(handler::handleConnection).listen(0)
                    .onSuccess(s -> port[0] = s.actualPort());
            }
        }));
        return port[0];
    }

    private WebSocket connect(int port) throws Exception {
        return await(vertx.createWebSocketClient().connect(port, "localhost", "/ws/detect"));
    }

    private static CompletableFuture<Short> closeCode(WebSocket ws) {
        CompletableFuture<Short> f = new CompletableFuture<>();
        ws.closeHandler(v -> f.complete(ws.closeStatusCode()));
        return f;
    }

    private static String start(int sampleRate) {
        return new JsonObject()
            .put("event", "start").put("sequenceNumber", "1").put("streamSid", "MZ1")
            .put("start", new JsonObject()
                .put("streamSid", "MZ1").put("session", TOKEN).put("callSid", "CA1")
                .put("mediaFormat", new JsonObject()
                    .put("encoding", "audio/x-mulaw").put("sampleRate", sampleRate).put("channels", 1)))
            .encode();
    }

    private static <T> T await(Future<T> f) throws Exception {
        return f.toCompletionStage().toCompletableFuture().get(5, TimeUnit.SECONDS);
    }
}
