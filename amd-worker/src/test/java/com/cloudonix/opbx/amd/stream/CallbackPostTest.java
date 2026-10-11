package com.cloudonix.opbx.amd.stream;

import static org.junit.jupiter.api.Assertions.assertEquals;

import java.util.concurrent.TimeUnit;
import java.util.concurrent.atomic.AtomicInteger;

import org.junit.jupiter.api.AfterEach;
import org.junit.jupiter.api.Test;

import io.vertx.core.Future;
import io.vertx.core.Vertx;
import io.vertx.core.http.HttpClient;
import io.vertx.core.http.HttpClientOptions;
import io.vertx.core.http.HttpServer;
import io.vertx.core.http.HttpServerOptions;
import io.vertx.core.net.SelfSignedCertificate;

@SuppressWarnings("removal") // SelfSignedCertificate: test-only TLS server
class CallbackPostTest {
    private final Vertx vertx = Vertx.vertx();
    private final AtomicInteger hits = new AtomicInteger();
    /** Held in a field: Vert.x 5 closes clients that become unreachable. */
    private HttpClient client;

    @AfterEach
    void tearDown() throws Exception {
        await(vertx.close());
    }

    @Test
    void postsOverHttp() throws Exception {
        HttpServer server = await(server(new HttpServerOptions(), 200));
        String url = "http://localhost:" + server.actualPort() + "/api/voice/amd-action";

        client = vertx.createHttpClient();
        assertEquals(200, await(StreamHandler.postCallback(vertx, client, url, "t", "{}")));
    }

    @Test
    void postsOverHttpsWhenTheUrlSaysSo() throws Exception {
        SelfSignedCertificate cert = SelfSignedCertificate.create();
        HttpServer server = await(server(new HttpServerOptions().setSsl(true).setKeyCertOptions(cert.keyCertOptions()), 200));
        String url = "https://localhost:" + server.actualPort() + "/api/voice/amd-action";

        // Test-only trust of the self-signed cert; SSL itself must come from the https scheme.
        client = vertx.createHttpClient(new HttpClientOptions().setTrustOptions(cert.trustOptions()));
        assertEquals(200, await(StreamHandler.postCallback(vertx, client, url, "t", "{}")));
    }

    @Test
    void retriesOnceOn5xx() throws Exception {
        HttpServer server = await(server(new HttpServerOptions(), 503));
        String url = "http://localhost:" + server.actualPort() + "/";

        client = vertx.createHttpClient();
        assertEquals(503, await(StreamHandler.postCallback(vertx, client, url, "t", "{}")));
        assertEquals(2, hits.get());
    }

    private Future<HttpServer> server(HttpServerOptions options, int status) {
        return vertx.createHttpServer(options).requestHandler(req -> {
            hits.incrementAndGet();
            req.body().onSuccess(b -> req.response().setStatusCode(status).end());
        }).listen(0);
    }

    private static <T> T await(Future<T> f) throws Exception {
        return f.toCompletionStage().toCompletableFuture().get(10, TimeUnit.SECONDS);
    }
}
