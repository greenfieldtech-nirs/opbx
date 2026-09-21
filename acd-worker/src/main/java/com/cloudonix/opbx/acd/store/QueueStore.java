package com.cloudonix.opbx.acd.store;

import java.util.List;
import java.util.Map;

import io.vertx.core.Future;

/**
 * Minimal async key-value/list store abstraction.
 * Implemented by Redis in production and an in-memory map in unit tests.
 */
public interface QueueStore {
    Future<Long> rpush(String key, String value);

    Future<Long> lrem(String key, String value);

    Future<List<String>> lrangeAll(String key);

    Future<Void> hset(String key, Map<String, String> values);

    Future<Map<String, String>> hgetall(String key);

    Future<Void> del(String... keys);

    Future<Long> incr(String key);
}
