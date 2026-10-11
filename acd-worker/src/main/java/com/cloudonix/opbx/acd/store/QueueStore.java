package com.cloudonix.opbx.acd.store;

import java.util.List;
import java.util.Map;
import java.util.Set;

import io.vertx.core.Future;

/**
 * Minimal async key-value/list store abstraction.
 * Implemented by Redis in production and an in-memory map in unit tests.
 *
 * Read-modify-write sequences live in single atomic operations here (Lua in Redis):
 * an await between a read and a write lets a logout or pause slip in between.
 */
public interface QueueStore {
    Future<Long> lrem(String key, String value);

    Future<List<String>> lrangeAll(String key);

    /** 0-based index of the first occurrence, or null when absent. */
    Future<Long> lpos(String key, String value);

    Future<Void> hset(String key, Map<String, String> values);

    Future<Map<String, String>> hgetall(String key);

    Future<Void> del(String... keys);

    Future<Long> incr(String key);

    /** Succeeds only when the backing store answers. */
    Future<Void> ping();

    /** Atomically append callId unless already queued; writes the call hash only when added. */
    Future<EnqueueResult> enqueueIfAbsent(String queueKey, String callKey, String callId, long queuedAt);

    /** Atomically apply an agent hash transition guarded by the current state. */
    Future<TransitionOutcome> transitionAgent(AgentTransition transition);

    /** 1-based queue position; added=false when the call was already queued. */
    record EnqueueResult(int position, boolean added) {
    }

    /**
     * Conditional agent update: applies only when the hash exists, its state is in
     * {@code allowedFrom}, and (if set) its wrapUpUntil equals {@code expectWrapUpUntil}.
     * A non-null {@code eventKey} is claimed in the same atomic step (SET NX EX), so a
     * replayed event is reported as DUPLICATE and changes nothing.
     */
    record AgentTransition(String agentKey, Set<String> allowedFrom, String expectWrapUpUntil,
            Map<String, String> fields, long incrTalkSeconds, long incrCalls,
            String eventKey, long eventTtlSeconds) {
    }

    enum TransitionOutcome {
        APPLIED,
        /** Hash missing (logged out) or state not allowed. */
        SKIPPED,
        DUPLICATE
    }
}
