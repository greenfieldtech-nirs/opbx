package com.cloudonix.opbx.acd.store;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

import io.vertx.core.Future;
import io.vertx.core.Vertx;
import io.vertx.redis.client.Redis;
import io.vertx.redis.client.RedisAPI;
import io.vertx.redis.client.RedisOptions;
import io.vertx.redis.client.Response;

/** Redis-backed {@link QueueStore} using the Vert.x redis client. */
public class RedisQueueStore implements QueueStore {
    private final Redis client;
    private final RedisAPI redis;

    /**
     * Pooled client: each command borrows a connection, so a Redis restart or
     * failover heals on the next command (a single connect()ed connection never does).
     */
    public RedisQueueStore(Vertx vertx, String redisUri) {
        this.client = Redis.createClient(vertx, new RedisOptions()
                .setConnectionString(redisUri)
                .setMaxPoolSize(16)
                .setMaxPoolWaiting(1024));
        this.redis = RedisAPI.api(client);
    }

    @Override
    public Future<Void> ping() {
        return redis.ping(List.of()).mapEmpty();
    }

    public Future<Void> close() {
        return client.close();
    }

    @Override
    public Future<Long> rpush(String key, String value) {
        return redis.rpush(List.of(key, value)).map(Response::toLong);
    }

    @Override
    public Future<Long> lrem(String key, String value) {
        return redis.lrem(key, "0", value).map(Response::toLong);
    }

    @Override
    public Future<List<String>> lrangeAll(String key) {
        return redis.lrange(key, "0", "-1").map(response -> response.stream().map(Response::toString).toList());
    }

    @Override
    public Future<Void> hset(String key, Map<String, String> values) {
        List<String> args = new ArrayList<>();
        args.add(key);
        values.forEach((k, v) -> {
            args.add(k);
            args.add(v);
        });
        return redis.hset(args).mapEmpty();
    }

    @Override
    public Future<Map<String, String>> hgetall(String key) {
        return redis.hgetall(key).map(response -> {
            Map<String, String> map = new HashMap<>();
            for (String field : response.getKeys()) {
                map.put(field, response.get(field).toString());
            }
            return map;
        });
    }

    @Override
    public Future<Void> del(String... keys) {
        return redis.del(Arrays.asList(keys)).mapEmpty();
    }

    @Override
    public Future<Long> incr(String key) {
        return redis.incr(key).map(Response::toLong);
    }
}
