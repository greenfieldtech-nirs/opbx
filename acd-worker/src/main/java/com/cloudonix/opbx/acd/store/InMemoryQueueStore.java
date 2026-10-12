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
    private final Map<String, String> strings = new HashMap<>();
    private final Set<String> flags = new HashSet<>();

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
            strings.remove(key);
            flags.remove(key);
        }
        return Future.succeededFuture();
    }

    @Override
    public Future<Void> ping() {
        return Future.succeededFuture();
    }

    @Override
    public synchronized Future<EnqueueResult> enqueueIfAbsent(String queueKey, String callKey, String doneKey,
            String callId, long queuedAt) {
        List<String> list = lists.computeIfAbsent(queueKey, k -> new ArrayList<>());
        int index = list.indexOf(callId);
        if (index >= 0) {
            return Future.succeededFuture(new EnqueueResult(index + 1, false));
        }
        if (doneKey != null && flags.contains(doneKey)) {
            return Future.succeededFuture(new EnqueueResult(0, false));
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
        boolean apply = hash != null && t.allowedFrom().contains(hash.get("state"));
        if (hash == null || (t.absentKey() != null && flags.contains(t.absentKey()))
                || !(apply || t.countOnlyFrom().contains(hash.get("state")))
                || (t.expectWrapUpUntil() != null && !t.expectWrapUpUntil().equals(hash.get("wrapUpUntil")))) {
            return Future.succeededFuture(TransitionOutcome.SKIPPED);
        }
        if (apply) {
            hash.putAll(t.fields());
        }
        hash.merge("talkSecondsTotal", String.valueOf(t.incrTalkSeconds()),
                (a, b) -> String.valueOf(Long.parseLong(a) + Long.parseLong(b)));
        hash.merge("callsHandledTotal", String.valueOf(t.incrCalls()),
                (a, b) -> String.valueOf(Long.parseLong(a) + Long.parseLong(b)));
        return Future.succeededFuture(TransitionOutcome.APPLIED);
    }

    @Override
    public synchronized Future<Void> dequeue(String queueKey, String callKey, String doneKey, String callId,
            long doneTtlSeconds, Long answeredAt, String eventKey, long eventTtlSeconds) {
        if (eventKey != null && !flags.add(eventKey)) {
            return Future.succeededFuture();
        }
        flags.add(doneKey);
        if (answeredAt == null) {
            strings.put(doneKey, "1");
        } else {
            String at = hashes.getOrDefault(callKey, Map.of()).get("queuedAt");
            if (at != null) {
                strings.put(doneKey, "a:" + at);
            } else {
                strings.putIfAbsent(doneKey, "a:" + answeredAt);
            }
        }
        List<String> list = lists.get(queueKey);
        if (list != null) {
            list.remove(callId);
        }
        hashes.remove(callKey);
        return Future.succeededFuture();
    }

    @Override
    public synchronized Future<Integer> requeue(String queueKey, String callKey, String callKeyPrefix,
            String doneKey, String callId, String eventKey, long eventTtlSeconds, long now) {
        if (eventKey != null && !flags.add(eventKey)) {
            return Future.succeededFuture(-1);
        }
        List<String> list = lists.computeIfAbsent(queueKey, k -> new ArrayList<>());
        if (list.contains(callId)) {
            return Future.succeededFuture(list.indexOf(callId) + 1);
        }
        String done = strings.get(doneKey);
        long at = now;
        if (flags.contains(doneKey)) {
            if (done == null || !done.startsWith("a:")) {
                return Future.succeededFuture(0);
            }
            at = Long.parseLong(done.substring(2));
            del(doneKey);
        }
        hashes.computeIfAbsent(callKey, k -> new HashMap<>()).put("queuedAt", String.valueOf(at));
        for (int i = 0; i < list.size(); i++) {
            String other = hashes.getOrDefault(callKeyPrefix + list.get(i), Map.of()).get("queuedAt");
            if (other != null && Long.parseLong(other) > at) {
                list.add(i, callId);
                return Future.succeededFuture(i + 1);
            }
        }
        list.add(callId);
        return Future.succeededFuture(list.size());
    }

    @Override
    public synchronized Future<String> nextRoundRobin(String cursorKey, List<String> rosterIds,
            Set<String> availableIds) {
        int start = rosterIds.indexOf(strings.get(cursorKey)) + 1; // 0 when no or unknown cursor
        for (int k = 0; k < rosterIds.size(); k++) {
            String id = rosterIds.get((start + k) % rosterIds.size());
            if (availableIds.contains(id)) {
                strings.put(cursorKey, id);
                return Future.succeededFuture(id);
            }
        }
        return Future.succeededFuture(null);
    }
}
