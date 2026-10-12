package com.cloudonix.opbx.acd;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

import org.slf4j.MDC;

import com.cloudonix.opbx.acd.model.AgentInfo;
import com.cloudonix.opbx.acd.model.AgentState;
import com.cloudonix.opbx.acd.store.QueueStore;
import com.cloudonix.opbx.acd.store.QueueStore.AgentTransition;
import com.cloudonix.opbx.acd.store.QueueStore.TransitionOutcome;

import io.vertx.core.Future;

/**
 * Core queue logic: FIFO ordering, overflow, agent selection, agent states.
 *
 * Redis key layout:
 *   acd:queue:{org}:{queue}              list   callIds (FIFO)
 *   acd:call:{org}:{queue}:{callId}      hash   queuedAt (epoch ms)
 *   acd:agent:{org}:{queue}:{userId}     hash   state, wrapUpUntil, talkSecondsTotal, callsHandledTotal
 *   acd:rr:{org}:{queue}                 string round-robin cursor (userId offered last)
 *   acd:done:{org}:{queue}:{callId}      string dequeued-call tombstone (10 min): a late poll must not re-enqueue it;
 *                                               'a:{queuedAt}' after 'answered' (requeue restores that place), '1' = gone
 *   acd:evt:{org}:{eventId}              string seen lifecycle event (dedupes Laravel replays)
 *
 * Agent hash transitions are conditional and atomic (store Lua): an event never
 * recreates a deleted (logged-out) hash or overrides a pause.
 *
 * ponytail: single verticle, lazy wrap-up expiry (checked on poll/live) — a
 * dedicated expiry timer can be added if agents sit in wrap-up with no polls.
 */
public class QueueEngine {

    private static final org.slf4j.Logger log = org.slf4j.LoggerFactory.getLogger(QueueEngine.class);
    /** Longer than Laravel's SendAcdEventJob retry window (5 tries, ~6.5 min of backoff). */
    static final long EVENT_DEDUPE_TTL_SECONDS = 86_400;
    static final long DONE_TTL_SECONDS = 600;
    private final QueueStore store;
    private final Time time;

    public QueueEngine(QueueStore store, Time time) {
        this.store = store;
        this.time = time;
    }

    static String queueKey(String org, String queue) {
        return "acd:queue:" + org + ":" + queue;
    }

    static String callKey(String org, String queue, String callId) {
        return "acd:call:" + org + ":" + queue + ":" + callId;
    }

    static String agentKey(String org, String queue, String userId) {
        return "acd:agent:" + org + ":" + queue + ":" + userId;
    }

    static String rrKey(String org, String queue) {
        return "acd:rr:" + org + ":" + queue;
    }

    static String doneKey(String org, String queue, String callId) {
        return "acd:done:" + org + ":" + queue + ":" + callId;
    }

    static String eventKey(String org, String eventId) {
        return "acd:evt:" + org + ":" + eventId;
    }

    /**
     * Enqueue a call. Idempotent: an already-queued call keeps its original position.
     */
    public Future<Integer> enqueue(String org, String queue, String callId) {
        return enqueue(org, queue, callId, null);
    }

    /** @param doneKey when set, a call dequeued within the tombstone TTL is not re-added (position 0). */
    private Future<Integer> enqueue(String org, String queue, String callId, String doneKey) {
        return store.enqueueIfAbsent(queueKey(org, queue), callKey(org, queue, callId), doneKey, callId,
                time.nowMillis())
                .map(result -> {
                    if (result.added()) {
                        logEvent(org, queue, callId, "call enqueued", Map.of("position", result.position()));
                    }
                    return result.position();
                });
    }

    /**
     * Poll for a decision for the given call.
     *
     * @param roster available agents per Laravel's membership data (extension numbers included)
     * @param strategy ring_all | round_robin | least_talk_time | fewest_calls
     * @param maxWaitSeconds overflow threshold from queue config
     * @return action: wait (with position) | dial (with agents) | overflow
     */
    public Future<Map<String, Object>> poll(String org, String queue, String callId, List<AgentInfo> roster,
            String strategy, long maxWaitSeconds) {
        String qKey = queueKey(org, queue);
        return store.lpos(qKey, callId).compose(index -> {
            if (index == null) {
                // Unknown call: worker likely restarted, so re-enqueue for recovery. A call
                // dequeued moments ago (a hold-loop poll racing answered/abandoned) is not
                // re-added: its tombstone wins and the poll just waits.
                return enqueue(org, queue, callId, doneKey(org, queue, callId))
                        .compose(position -> waitResult(Math.max(position, 1)));
            }

            int position = index.intValue() + 1;
            if (position > 1) {
                return waitResult(position);
            }

            // This call is at the head of the queue.
            return store.hgetall(callKey(org, queue, callId)).compose(call -> {
                if (call.isEmpty()) {
                    // Dequeued between our LPOS and this read: not queued, and not an overflow.
                    return waitResult(1);
                }
                long queuedAt = Long.parseLong(call.get("queuedAt"));
                if (time.nowMillis() - queuedAt > maxWaitSeconds * 1000) {
                    return dequeue(org, queue, callId).map(v -> {
                        logEvent(org, queue, callId, "call overflowed max wait", Map.of());
                        return Map.<String, Object>of("action", "overflow");
                    });
                }

                return resolveAvailableAgents(org, queue, roster).compose(available -> {
                    if (available.isEmpty()) {
                        return waitResult(1);
                    }
                    return selectAgents(org, queue, roster, available, strategy).map(selected -> {
                        logEvent(org, queue, callId, "offering to agents", Map.of(
                                "strategy", strategy,
                                "agents", selected.stream().map(AgentInfo::extensionNumber).toList().toString()));
                        return Map.<String, Object>of(
                                "action", "dial",
                                "agents", selected.stream().map(a -> Map.of(
                                        "userId", a.userId(),
                                        "extensionNumber", a.extensionNumber())).toList());
                    });
                });
            });
        });
    }

    /**
     * Handle a lifecycle event for a queued call.
     *
     * @param type        answered | ended | abandoned | overflow | dial_failed | requeue
     * @param agentUserId agent involved (answered/ended/dial_failed), may be null
     * @param talkSeconds talk duration for "ended" (0 for dial_failed), may be null
     * @param wrapUpSeconds post-call wrap-up duration from queue config (0 = none), may be null
     * @param eventId     stable id of this event across Laravel replays, may be null (no dedupe)
     */
    public Future<Void> event(String org, String queue, String callId, String type, // logged below
            String agentUserId, Long talkSeconds, Long wrapUpSeconds, String eventId) {
        String evtKey = eventId != null ? eventKey(org, eventId) : null;
        return switch (type) {
            case "answered" -> {
                Future<Void> markBusy = agentUserId != null
                        // A late 'answered' for a call already ended/abandoned (tombstoned) must not
                        // mark the agent BUSY with no 'ended' left to release them. An agent who paused
                        // (WRAP_UP) while ringing stays paused: no new offers; 'ended' still counts the call.
                        ? transition(org, queue, callId, type, new AgentTransition(agentKey(org, queue, agentUserId),
                                Set.of(AgentState.AVAILABLE.name()), Set.of(), null,
                                Map.of("state", AgentState.BUSY.name()), 0, 0, evtKey, EVENT_DEDUPE_TTL_SECONDS,
                                doneKey(org, queue, callId)))
                        : Future.succeededFuture();
                yield markBusy.compose(v -> store.dequeue(queueKey(org, queue), callKey(org, queue, callId),
                        doneKey(org, queue, callId), callId, DONE_TTL_SECONDS, time.nowMillis()));
            }
            case "ended" -> {
                Future<Void> agentUpdate = agentUserId != null
                        ? onCallEnded(org, queue, callId, agentUserId, talkSeconds == null ? 0 : talkSeconds,
                                wrapUpSeconds == null ? 0 : wrapUpSeconds, evtKey)
                        : Future.succeededFuture();
                yield agentUpdate.compose(v -> dequeue(org, queue, callId));
            }
            case "dial_failed" -> {
                // Release an agent a spurious bridge marked BUSY; no counters. A logged-out
                // (missing hash) or paused (WRAP_UP) agent stays as they are.
                Future<Void> agentUpdate = agentUserId != null
                        ? transition(org, queue, callId, type, new AgentTransition(agentKey(org, queue, agentUserId),
                                Set.of(AgentState.BUSY.name()), Set.of(), null,
                                Map.of("state", AgentState.AVAILABLE.name(), "wrapUpUntil", "0"), 0, 0,
                                evtKey, EVENT_DEDUPE_TTL_SECONDS, null))
                        : Future.succeededFuture();
                // Call stays queued (not dequeued) so the next poll can re-offer it.
                yield agentUpdate;
            }
            case "requeue" -> {
                // The winning agent never connected after 'answered' dequeued the call: release the
                // agent (as dial_failed) and put the call back at its ORIGINAL place (queuedAt kept in
                // the tombstone), clearing the tombstone so the next winner can be marked BUSY.
                Future<Void> agentUpdate = agentUserId != null
                        ? transition(org, queue, callId, type, new AgentTransition(agentKey(org, queue, agentUserId),
                                Set.of(AgentState.BUSY.name()), Set.of(), null,
                                Map.of("state", AgentState.AVAILABLE.name(), "wrapUpUntil", "0"), 0, 0,
                                evtKey, EVENT_DEDUPE_TTL_SECONDS, null))
                        : Future.succeededFuture();
                // Own dedupe key: a retry after a partial failure still requeues though the agent part ran.
                yield agentUpdate.compose(v -> store.requeue(queueKey(org, queue), callKey(org, queue, callId),
                        callKey(org, queue, ""), doneKey(org, queue, callId), callId,
                        evtKey != null ? evtKey + ":requeue" : null, EVENT_DEDUPE_TTL_SECONDS, time.nowMillis()))
                        .map(position -> {
                            logEvent(org, queue, callId, position > 0 ? "call requeued at its original place"
                                    : position == 0 ? "requeue ignored: call already gone" : "requeue replay ignored",
                                    Map.of("position", position));
                            return null;
                        });
            }
            case "abandoned", "overflow" -> dequeue(org, queue, callId);
            default -> Future.failedFuture("Unknown event type: " + type);
        };
    }

    /**
     * Set an agent's presence state.
     *
     * @param talkSecondsTotal  all-time talk seconds (MySQL seed at login), or null to keep
     * @param callsHandledTotal all-time handled calls (MySQL seed at login), or null to keep
     * @param wrapUpSeconds     for WRAP_UP: auto-expire after this many seconds; null/omitted = sticky
     */
    public Future<Void> setAgentState(String org, String queue, String userId, AgentState state,
            Long talkSecondsTotal, Long callsHandledTotal, Long wrapUpSeconds) {
        String key = agentKey(org, queue, userId);

        if (state == AgentState.LOGGED_OUT) {
            return store.del(key);
        }

        Map<String, String> fields = new HashMap<>();
        fields.put("state", state.name());

        if (talkSecondsTotal != null) {
            fields.put("talkSecondsTotal", String.valueOf(talkSecondsTotal));
        }
        if (callsHandledTotal != null) {
            fields.put("callsHandledTotal", String.valueOf(callsHandledTotal));
        }

        if (state == AgentState.WRAP_UP) {
            long until = wrapUpSeconds != null && wrapUpSeconds > 0
                    ? time.nowMillis() + wrapUpSeconds * 1000
                    : 0; // 0 = sticky (manual wrap-up until toggled)
            fields.put("wrapUpUntil", String.valueOf(until));
        } else {
            fields.put("wrapUpUntil", "0");
        }

        return store.hset(key, fields);
    }

    /**
     * Live snapshot with an explicit agent roster (Laravel provides membership).
     */
    public Future<Map<String, Object>> live(String org, String queue, List<AgentInfo> roster) {
        long now = time.nowMillis();
        return store.lrangeAll(queueKey(org, queue))
                .compose(calls -> {
                    List<Future<Map<String, Object>>> waitingFutures = new ArrayList<>();
                    for (int i = 0; i < calls.size(); i++) {
                        String callId = calls.get(i);
                        int position = i + 1;
                        waitingFutures.add(store.hgetall(callKey(org, queue, callId)).map(call -> {
                            long queuedAt = Long.parseLong(call.getOrDefault("queuedAt", "0"));
                            return Map.<String, Object>of(
                                    "callId", callId,
                                    "position", position,
                                    "waitedSeconds", Math.max(0, (now - queuedAt) / 1000));
                        }));
                    }

                    List<Future<Map<String, Object>>> agentFutures = new ArrayList<>();
                    for (AgentInfo agent : roster) {
                        // Expire wrap-up lazily on read too: otherwise an agent
                        // with no calls polling stays WRAP_UP forever in live views.
                        agentFutures.add(resolveStateWithExpiry(org, queue, agent.userId(), now)
                                .map(resolved -> {
                                    boolean expired = resolved.state() == AgentState.AVAILABLE;
                                    long wrapUpUntil = Long.parseLong(resolved.fields().getOrDefault("wrapUpUntil", "0"));
                                    Long wrapUpRemaining = !expired && wrapUpUntil > 0
                                            ? Math.max(0, (wrapUpUntil - now) / 1000)
                                            : null;
                                    return Map.<String, Object>of(
                                            "userId", agent.userId(),
                                            "extensionNumber", agent.extensionNumber(),
                                            "state", resolved.state().name(),
                                            "wrapUpRemainingSeconds", wrapUpRemaining == null ? "" : String.valueOf(wrapUpRemaining));
                                }));
                    }

                    return Future.all(waitingFutures).compose(waitingComposite -> Future.all(agentFutures)
                            .map(agentComposite -> Map.<String, Object>of(
                                    "waiting", waitingComposite.<Map<String, Object>>list(),
                                    "agents", agentComposite.<Map<String, Object>>list())));
                });
    }

    /**
     * BUSY: counters, then wrap-up/AVAILABLE. Paused (WRAP_UP, e.g. paused while ringing or mid-call):
     * counters only, the pause stays. Logged out (no hash): nothing.
     */
    private Future<Void> onCallEnded(String org, String queue, String callId, String userId, long talkSeconds,
            long wrapUpSeconds, String evtKey) {
        Map<String, String> updates = wrapUpSeconds > 0
                ? Map.of("state", AgentState.WRAP_UP.name(),
                        "wrapUpUntil", String.valueOf(time.nowMillis() + wrapUpSeconds * 1000))
                : Map.of("state", AgentState.AVAILABLE.name(), "wrapUpUntil", "0");
        return transition(org, queue, callId, "ended", new AgentTransition(agentKey(org, queue, userId),
                Set.of(AgentState.BUSY.name()), Set.of(AgentState.WRAP_UP.name()), null, updates, talkSeconds, 1,
                evtKey, EVENT_DEDUPE_TTL_SECONDS,
                null));
    }

    private Future<Void> transition(String org, String queue, String callId, String type, AgentTransition t) {
        return store.transitionAgent(t).map(outcome -> {
            if (outcome != TransitionOutcome.APPLIED) {
                logEvent(org, queue, callId, "agent transition not applied", Map.of(
                        "event", type, "outcome", outcome.name(), "agent_key", t.agentKey()));
            }
            return null;
        });
    }

    /**
     * Read an agent's state, lazily flipping WRAP_UP to AVAILABLE once the
     * wrap-up deadline passed (sticky wrap-up has no deadline and never expires).
     * Shared by poll (selection) and live (status display).
     */
    private Future<AgentStateWithFields> resolveStateWithExpiry(String org, String queue, String userId, long now) {
        String key = agentKey(org, queue, userId);
        return store.hgetall(key).compose(fields -> {
            AgentState state = AgentState.fromString(fields.get("state"));

            if (state == AgentState.WRAP_UP) {
                String until = fields.getOrDefault("wrapUpUntil", "0");
                long wrapUpUntil = Long.parseLong(until);
                if (wrapUpUntil > 0 && now >= wrapUpUntil) {
                    // Flip only the wrap-up we read: a logout (DEL) or a new pause in between wins.
                    return store.transitionAgent(new AgentTransition(key, Set.of(AgentState.WRAP_UP.name()), Set.of(), until,
                            Map.of("state", AgentState.AVAILABLE.name(), "wrapUpUntil", "0"), 0, 0, null, 0, null))
                            .compose(outcome -> outcome == TransitionOutcome.APPLIED
                                    ? Future.succeededFuture(new AgentStateWithFields(AgentState.AVAILABLE, fields))
                                    : store.hgetall(key).map(fresh -> new AgentStateWithFields(
                                            AgentState.fromString(fresh.get("state")), fresh)));
                }
            }

            return Future.succeededFuture(new AgentStateWithFields(state, fields));
        });
    }

    /** Agent state plus the stored hash fields at read time. */
    private record AgentStateWithFields(AgentState state, Map<String, String> fields) {
    }

    /**
     * Intersect the Laravel roster with agents whose stored state is AVAILABLE,
     * lazily expiring wrap-ups, and attach stored counters.
     */
    private Future<List<AgentInfo>> resolveAvailableAgents(String org, String queue, List<AgentInfo> roster) {
        List<Future<AgentWithState>> futures = new ArrayList<>();
        long now = time.nowMillis();

        for (AgentInfo agent : roster) {
            futures.add(resolveStateWithExpiry(org, queue, agent.userId(), now)
                    .map(resolved -> new AgentWithState(agent, resolved.state(), resolved.fields())));
        }

        return Future.all(futures).map(composite -> composite.<AgentWithState>list().stream()
                .filter(a -> a.state() == AgentState.AVAILABLE)
                .map(a -> new AgentInfo(a.agent().userId(), a.agent().extensionNumber(),
                        Long.parseLong(a.fields().getOrDefault("talkSecondsTotal", "0")),
                        Long.parseLong(a.fields().getOrDefault("callsHandledTotal", "0"))))
                .toList());
    }

    /** Internal pair used while resolving roster availability. */
    private record AgentWithState(AgentInfo agent, AgentState state, Map<String, String> fields) {
    }

    private Future<List<AgentInfo>> selectAgents(String org, String queue, List<AgentInfo> roster,
            List<AgentInfo> available, String strategy) {
        if ("ring_all".equals(strategy)) {
            return Future.succeededFuture(available);
        }

        if ("round_robin".equals(strategy)) {
            // Rotate over the stable roster, not the shifting available list, so a busy agent's
            // turn passes to the next one without skipping anyone.
            Map<String, AgentInfo> byId = new HashMap<>();
            available.forEach(a -> byId.put(a.userId(), a));
            return store.nextRoundRobin(rrKey(org, queue), roster.stream().map(AgentInfo::userId).toList(),
                    byId.keySet()).map(id -> List.of(byId.get(id)));
        }

        if ("least_talk_time".equals(strategy)) {
            return Future.succeededFuture(List.of(available.stream()
                    .min((a, b) -> Long.compare(a.talkSecondsTotal(), b.talkSecondsTotal()))
                    .orElseThrow()));
        }

        if ("fewest_calls".equals(strategy)) {
            return Future.succeededFuture(List.of(available.stream()
                    .min((a, b) -> Long.compare(a.callsHandledTotal(), b.callsHandledTotal()))
                    .orElseThrow()));
        }

        return Future.failedFuture("Unknown strategy: " + strategy);
    }

    private Future<Map<String, Object>> waitResult(int position) {
        return Future.succeededFuture(Map.<String, Object>of("action", "wait", "position", position));
    }

    private Future<Void> dequeue(String org, String queue, String callId) {
        return store.dequeue(queueKey(org, queue), callKey(org, queue, callId), doneKey(org, queue, callId), callId,
                DONE_TTL_SECONDS, null);
    }

    private void logEvent(String org, String queue, String callId, String msg, Map<String, Object> extra) {
        MDC.put("org_id", org);
        MDC.put("queue_id", queue);
        MDC.put("call_id", callId);
        // For queue calls the callId IS the Cloudonix session token; expose
        // it under the audit-trail key too.
        MDC.put("session_token", callId);
        try {
            if (extra.isEmpty()) {
                log.info(msg);
            } else {
                log.info(msg + " {}", extra);
            }
        } finally {
            MDC.clear();
        }
    }
}
