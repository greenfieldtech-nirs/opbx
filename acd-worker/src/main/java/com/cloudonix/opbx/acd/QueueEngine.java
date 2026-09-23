package com.cloudonix.opbx.acd;

import java.util.ArrayList;
import java.util.HashMap;
import java.util.List;
import java.util.Map;

import com.cloudonix.opbx.acd.model.AgentInfo;
import com.cloudonix.opbx.acd.model.AgentState;
import com.cloudonix.opbx.acd.store.QueueStore;

import io.vertx.core.Future;

/**
 * Core queue logic: FIFO ordering, overflow, agent selection, agent states.
 *
 * Redis key layout:
 *   acd:queue:{org}:{queue}              list   callIds (FIFO)
 *   acd:call:{org}:{queue}:{callId}      hash   queuedAt (epoch ms)
 *   acd:agent:{org}:{queue}:{userId}     hash   state, wrapUpUntil, talkSecondsTotal, callsHandledTotal
 *   acd:rr:{org}:{queue}                 string round-robin cursor
 *
 * ponytail: single verticle, lazy wrap-up expiry (checked on poll/live) — a
 * dedicated expiry timer can be added if agents sit in wrap-up with no polls.
 */
public class QueueEngine {
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

    /**
     * Enqueue a call. Idempotent: an already-queued call keeps its original position.
     */
    public Future<Integer> enqueue(String org, String queue, String callId) {
        String qKey = queueKey(org, queue);
        return store.lrangeAll(qKey).compose(existing -> {
            int position = existing.indexOf(callId) + 1;
            if (position > 0) {
                return Future.succeededFuture(position);
            }
            Map<String, String> call = Map.of("queuedAt", String.valueOf(time.nowMillis()));
            return store.hset(callKey(org, queue, callId), call)
                    .compose(v -> store.rpush(qKey, callId))
                    .map(size -> size.intValue());
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
        return store.lrangeAll(qKey).compose(calls -> {
            if (!calls.contains(callId)) {
                // Unknown call: worker likely restarted. Re-enqueue for recovery
                // (Laravel stops polling once a call is answered/abandoned/overflowed,
                // so a poll for an unknown call implies the lost state was a queued call).
                return enqueue(org, queue, callId)
                        .compose(position -> waitResult(position));
            }

            int position = calls.indexOf(callId) + 1;
            if (position > 1) {
                return waitResult(position);
            }

            // This call is at the head of the queue.
            return store.hgetall(callKey(org, queue, callId)).compose(call -> {
                long queuedAt = Long.parseLong(call.getOrDefault("queuedAt", "0"));
                if (time.nowMillis() - queuedAt > maxWaitSeconds * 1000) {
                    return dequeue(org, queue, callId).map(v -> Map.<String, Object>of("action", "overflow"));
                }

                return resolveAvailableAgents(org, queue, roster).compose(available -> {
                    if (available.isEmpty()) {
                        return waitResult(1);
                    }
                    return selectAgents(org, queue, available, strategy).map(selected -> Map.<String, Object>of(
                            "action", "dial",
                            "agents", selected.stream().map(a -> Map.of(
                                    "userId", a.userId(),
                                    "extensionNumber", a.extensionNumber())).toList()));
                });
            });
        });
    }

    /**
     * Handle a lifecycle event for a queued call.
     *
     * @param type        answered | ended | abandoned | overflow | dial_failed
     * @param agentUserId agent involved (answered/ended/dial_failed), may be null
     * @param talkSeconds talk duration for "ended" (0 for dial_failed), may be null
     * @param wrapUpSeconds post-call wrap-up duration from queue config (0 = none), may be null
     */
    public Future<Void> event(String org, String queue, String callId, String type,
            String agentUserId, Long talkSeconds, Long wrapUpSeconds) {
        return switch (type) {
            case "answered" -> {
                Future<Void> markBusy = agentUserId != null
                        ? updateAgentFields(org, queue, agentUserId, Map.of("state", AgentState.BUSY.name()))
                        : Future.succeededFuture();
                yield markBusy.compose(v -> dequeue(org, queue, callId));
            }
            case "ended" -> {
                Future<Void> agentUpdate = agentUserId != null
                        ? onCallEnded(org, queue, agentUserId, talkSeconds == null ? 0 : talkSeconds,
                                wrapUpSeconds == null ? 0 : wrapUpSeconds)
                        : Future.succeededFuture();
                yield agentUpdate.compose(v -> dequeue(org, queue, callId));
            }
            case "dial_failed" -> {
                // Agent did not answer: no counters, agent goes straight back to available.
                Future<Void> agentUpdate = agentUserId != null
                        ? updateAgentFields(org, queue, agentUserId, Map.of("state", AgentState.AVAILABLE.name()))
                        : Future.succeededFuture();
                // Call stays queued (not dequeued) so the next poll can re-offer it.
                yield agentUpdate;
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

    private Future<Void> onCallEnded(String org, String queue, String userId, long talkSeconds, long wrapUpSeconds) {
        return store.hgetall(agentKey(org, queue, userId)).compose(fields -> {
            long talk = Long.parseLong(fields.getOrDefault("talkSecondsTotal", "0")) + talkSeconds;
            long calls = Long.parseLong(fields.getOrDefault("callsHandledTotal", "0")) + 1;

            Map<String, String> updates = new HashMap<>();
            updates.put("talkSecondsTotal", String.valueOf(talk));
            updates.put("callsHandledTotal", String.valueOf(calls));

            if (wrapUpSeconds > 0) {
                updates.put("state", AgentState.WRAP_UP.name());
                updates.put("wrapUpUntil", String.valueOf(time.nowMillis() + wrapUpSeconds * 1000));
            } else {
                updates.put("state", AgentState.AVAILABLE.name());
                updates.put("wrapUpUntil", "0");
            }

            return store.hset(agentKey(org, queue, userId), updates);
        });
    }

    private Future<Void> updateAgentFields(String org, String queue, String userId, Map<String, String> fields) {
        return store.hset(agentKey(org, queue, userId), fields);
    }

    /**
     * Read an agent's state, lazily flipping WRAP_UP to AVAILABLE once the
     * wrap-up deadline passed (sticky wrap-up has no deadline and never expires).
     * Shared by poll (selection) and live (status display).
     */
    private Future<AgentStateWithFields> resolveStateWithExpiry(String org, String queue, String userId, long now) {
        return store.hgetall(agentKey(org, queue, userId)).compose(fields -> {
            AgentState state = AgentState.fromString(fields.get("state"));

            if (state == AgentState.WRAP_UP) {
                long wrapUpUntil = Long.parseLong(fields.getOrDefault("wrapUpUntil", "0"));
                if (wrapUpUntil > 0 && now >= wrapUpUntil) {
                    return store.hset(agentKey(org, queue, userId), Map.of(
                            "state", AgentState.AVAILABLE.name(),
                            "wrapUpUntil", "0")).map(v -> new AgentStateWithFields(AgentState.AVAILABLE, fields));
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
            futures.add(store.hgetall(agentKey(org, queue, agent.userId())).compose(fields -> {
                AgentState stored = AgentState.fromString(fields.get("state"));

                if (stored == AgentState.WRAP_UP) {
                    long wrapUpUntil = Long.parseLong(fields.getOrDefault("wrapUpUntil", "0"));
                    if (wrapUpUntil > 0 && now >= wrapUpUntil) {
                        AgentState expired = AgentState.AVAILABLE;
                        return store.hset(agentKey(org, queue, agent.userId()), Map.of(
                                "state", AgentState.AVAILABLE.name(),
                                "wrapUpUntil", "0")).map(v -> new AgentWithState(agent, expired, fields));
                    }
                }

                return Future.succeededFuture(new AgentWithState(agent, stored, fields));
            }));
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

    private Future<List<AgentInfo>> selectAgents(String org, String queue, List<AgentInfo> available, String strategy) {
        if ("ring_all".equals(strategy)) {
            return Future.succeededFuture(available);
        }

        if ("round_robin".equals(strategy)) {
            return store.incr(rrKey(org, queue)).map(n -> {
                int index = (int) ((n - 1) % available.size());
                return List.of(available.get(index));
            });
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
        return store.lrem(queueKey(org, queue), callId)
                .compose(v -> store.del(callKey(org, queue, callId)));
    }
}
