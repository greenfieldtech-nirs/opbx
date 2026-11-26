package com.cloudonix.opbx.acd;

import static org.junit.jupiter.api.Assertions.assertEquals;

import java.util.concurrent.TimeUnit;

import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import com.cloudonix.opbx.acd.store.InMemoryQueueStore;
import com.cloudonix.opbx.acd.store.QueueStore;

import io.vertx.core.Future;
import io.vertx.core.Vertx;
import io.vertx.core.http.HttpClient;
import io.vertx.core.http.HttpMethod;
import io.vertx.core.http.HttpServer;

class HealthCheckTest {
    private Vertx vertx;

    @BeforeEach
    void setUp() {
        vertx = Vertx.vertx();
    }

    @AfterEach
    void tearDown() throws Exception {
        await(vertx.close());
    }

    @Test
    void healthIs200WhenRedisAnswers() throws Exception {
        assertEquals(200, statusFor(new InMemoryQueueStore()));
    }

    @Test
    void healthIs503WhenRedisPingFails() throws Exception {
        QueueStore down = new InMemoryQueueStore() {
            @Override
            public Future<Void> ping() {
                return Future.failedFuture("Connection refused");
            }
        };
        assertEquals(503, statusFor(down));
    }

    // Bind and connect to the same literal address: "localhost" can resolve to ::1 on CI runners and reach
    // another process listening on that port over IPv6 (HttpClosedException instead of our response).
    private static final String LOOPBACK = "127.0.0.1";

    private int statusFor(QueueStore store) throws Exception {
        HttpServer server = await(vertx.createHttpServer()
                .requestHandler(AcdWorkerVerticle.healthHandler(store))
                .listen(0, LOOPBACK));
        // Keep a strong reference and close it explicitly: Vert.x closes an unreferenced HttpClient when it is
        // garbage collected, which fails the in-flight request with "Pool closed" under full-suite GC pressure.
        HttpClient client = vertx.createHttpClient();
        try {
            return await(client
                    .request(HttpMethod.GET, server.actualPort(), LOOPBACK, "/")
                    .compose(req -> req.send())
                    .map(resp -> resp.statusCode()));
        } finally {
            await(client.close());
        }
    }

    private static <T> T await(Future<T> future) throws Exception {
        return future.toCompletionStage().toCompletableFuture().get(5, TimeUnit.SECONDS);
    }
}
