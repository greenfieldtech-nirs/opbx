package com.cloudonix.opbx.acd.store;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.concurrent.ConcurrentHashMap;
import java.util.concurrent.atomic.AtomicLong;

import io.vertx.core.Future;

/** In-memory {@link QueueStore} for unit tests. */
public class InMemoryQueueStore implements QueueStore {
    private final Map<String, List<String>> lists = new ConcurrentHashMap<>();
    private final Map<String, Map<String, String>> hashes = new ConcurrentHashMap<>();
    private final Map<String, AtomicLong> strings = new ConcurrentHashMap<>();

    @Override
    public Future<Long> rpush(String key, String value) {
        List<String> list = lists.computeIfAbsent(key, k -> new ArrayList<>());
        synchronized (list) {
            list.add(value);
            return Future.succeededFuture((long) list.size());
        }
    }

    @Override
    public Future<Long> lrem(String key, String value) {
        List<String> list = lists.get(key);
        if (list == null) {
            return Future.succeededFuture(0L);
        }
        synchronized (list) {
            boolean removed = list.remove(value);
            return Future.succeededFuture(removed ? 1L : 0L);
        }
    }

    @Override
    public Future<List<String>> lrangeAll(String key) {
        List<String> list = lists.get(key);
        if (list == null) {
            return Future.succeededFuture(List.of());
        }
        synchronized (list) {
            return Future.succeededFuture(List.copyOf(list));
        }
    }

    @Override
    public Future<Void> hset(String key, Map<String, String> values) {
        hashes.computeIfAbsent(key, k -> new ConcurrentHashMap<>()).putAll(values);
        return Future.succeededFuture();
    }

    @Override
    public Future<Map<String, String>> hgetall(String key) {
        return Future.succeededFuture(Map.copyOf(hashes.getOrDefault(key, Map.of())));
    }

    @Override
    public Future<Void> del(String... keys) {
        for (String key : keys) {
            lists.remove(key);
            hashes.remove(key);
            strings.remove(key);
        }
        return Future.succeededFuture();
    }

    @Override
    public Future<Long> incr(String key) {
        return Future.succeededFuture(strings.computeIfAbsent(key, k -> new AtomicLong()).incrementAndGet());
    }
}
