package com.cloudonix.opbx.acd.store;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.HashSet;
import java.util.List;
import java.util.Map;
import java.util.Set;

import io.vertx.core.Future;

/**
 * In-memory {@link QueueStore} for unit tests. Every operation is synchronized on the
 * store, mirroring the atomicity of the Redis Lua scripts. Event-key TTLs are ignored.
 */
public class InMemoryQueueStore implements QueueStore {
    private final Map<String, List<String>> lists = new HashMap<>();
    private final Map<String, Map<String, String>> hashes = new HashMap<>();
    private final Map<String, Long> counters = new HashMap<>();
    private final Set<String> flags = new HashSet<>();

    @Override
    public synchronized Future<Long> lrem(String key, String value) {
        List<String> list = lists.get(key);
        return Future.succeededFuture(list != null && list.remove(value) ? 1L : 0L);
    }

    @Override
    public synchronized Future<List<String>> lrangeAll(String key) {
        return Future.succeededFuture(List.copyOf(lists.getOrDefault(key, List.of())));
    }

    @Override
    public synchronized Future<Long> lpos(String key, String value) {
        int index = lists.getOrDefault(key, List.of()).indexOf(value);
        return Future.succeededFuture(index < 0 ? null : (long) index);
    }

    @Override
    public synchronized Future<Void> hset(String key, Map<String, String> values) {
        hashes.computeIfAbsent(key, k -> new HashMap<>()).putAll(values);
        return Future.succeededFuture();
    }

    @Override
    public synchronized Future<Map<String, String>> hgetall(String key) {
        return Future.succeededFuture(Map.copyOf(hashes.getOrDefault(key, Map.of())));
    }

    @Override
    public synchronized Future<Void> del(String... keys) {
        for (String key : keys) {
            lists.remove(key);
            hashes.remove(key);
            counters.remove(key);
            flags.remove(key);
        }
        return Future.succeededFuture();
    }

    @Override
    public synchronized Future<Long> incr(String key) {
        return Future.succeededFuture(counters.merge(key, 1L, Long::sum));
    }

    @Override
    public Future<Void> ping() {
        return Future.succeededFuture();
    }

    @Override
    public synchronized Future<EnqueueResult> enqueueIfAbsent(String queueKey, String callKey, String callId,
            long queuedAt) {
        List<String> list = lists.computeIfAbsent(queueKey, k -> new ArrayList<>());
        int index = list.indexOf(callId);
        if (index >= 0) {
            return Future.succeededFuture(new EnqueueResult(index + 1, false));
        }
        hashes.computeIfAbsent(callKey, k -> new HashMap<>()).put("queuedAt", String.valueOf(queuedAt));
        list.add(callId);
        return Future.succeededFuture(new EnqueueResult(list.size(), true));
    }

    @Override
    public synchronized Future<TransitionOutcome> transitionAgent(AgentTransition t) {
        if (t.eventKey() != null && !flags.add(t.eventKey())) {
            return Future.succeededFuture(TransitionOutcome.DUPLICATE);
        }
        Map<String, String> hash = hashes.get(t.agentKey());
        if (hash == null || !t.allowedFrom().contains(hash.get("state"))
                || (t.expectWrapUpUntil() != null && !t.expectWrapUpUntil().equals(hash.get("wrapUpUntil")))) {
            return Future.succeededFuture(TransitionOutcome.SKIPPED);
        }
        hash.putAll(t.fields());
        hash.merge("talkSecondsTotal", String.valueOf(t.incrTalkSeconds()),
                (a, b) -> String.valueOf(Long.parseLong(a) + Long.parseLong(b)));
        hash.merge("callsHandledTotal", String.valueOf(t.incrCalls()),
                (a, b) -> String.valueOf(Long.parseLong(a) + Long.parseLong(b)));
        return Future.succeededFuture(TransitionOutcome.APPLIED);
    }
}
