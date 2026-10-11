package com.cloudonix.opbx.acd.store;

import java.util.ArrayList;
import java.util.Arrays;
import java.util.HashMap;
import java.util.List;
import java.util.Map;
import java.util.Set;

import io.vertx.core.Future;
import io.vertx.core.Vertx;
import io.vertx.redis.client.Redis;
import io.vertx.redis.client.RedisAPI;
import io.vertx.redis.client.RedisOptions;
import io.vertx.redis.client.Response;

/** Redis-backed {@link QueueStore} using the Vert.x redis client. */
public class RedisQueueStore implements QueueStore {
    // KEYS: queue, call hash, done tombstone ('' = ignore). Returns {position, added}; {0, 0} when done.
    private static final String ENQUEUE_LUA = """
            local pos = redis.call('LPOS', KEYS[1], ARGV[1])
            if pos then return {pos + 1, 0} end
            if KEYS[3] ~= '' and redis.call('EXISTS', KEYS[3]) == 1 then return {0, 0} end
            redis.call('HSET', KEYS[2], 'queuedAt', ARGV[2])
            return {redis.call('RPUSH', KEYS[1], ARGV[1]), 1}
            """;

    // KEYS: agent hash, event key ('' = none), key that must be absent ('' = none). ARGV: allowed
    // states (csv), expected wrapUpUntil ('' = any), event ttl, incr talk, incr calls, field/value
    // pairs. Returns 1 applied, 0 skipped, -1 duplicate.
    private static final String TRANSITION_LUA = """
            if KEYS[2] ~= '' and not redis.call('SET', KEYS[2], '1', 'NX', 'EX', ARGV[3]) then return -1 end
            if KEYS[3] ~= '' and redis.call('EXISTS', KEYS[3]) == 1 then return 0 end
            local state = redis.call('HGET', KEYS[1], 'state')
            if not state or not string.find(',' .. ARGV[1] .. ',', ',' .. state .. ',', 1, true) then return 0 end
            if ARGV[2] ~= '' and redis.call('HGET', KEYS[1], 'wrapUpUntil') ~= ARGV[2] then return 0 end
            if #ARGV > 5 then redis.call('HSET', KEYS[1], unpack(ARGV, 6)) end
            if ARGV[4] ~= '0' then redis.call('HINCRBY', KEYS[1], 'talkSecondsTotal', ARGV[4]) end
            if ARGV[5] ~= '0' then redis.call('HINCRBY', KEYS[1], 'callsHandledTotal', ARGV[5]) end
            return 1
            """;

    // KEYS: queue, call hash, done tombstone. ARGV: callId, tombstone ttl.
    private static final String DEQUEUE_LUA = """
            redis.call('SET', KEYS[3], '1', 'EX', ARGV[2])
            redis.call('LREM', KEYS[1], 0, ARGV[1])
            redis.call('DEL', KEYS[2])
            return 1
            """;

    // KEYS: cursor. ARGV: roster ids in order, each prefixed '+' (available) or '-'.
    private static final String ROUND_ROBIN_LUA = """
            local last = redis.call('GET', KEYS[1])
            local n = #ARGV
            local start = 0
            for i = 1, n do
              if string.sub(ARGV[i], 2) == last then start = i break end
            end
            for k = 1, n do
              local entry = ARGV[((start + k - 1) % n) + 1]
              if string.sub(entry, 1, 1) == '+' then
                local id = string.sub(entry, 2)
                redis.call('SET', KEYS[1], id)
                return id
              end
            end
            return false
            """;

    private final Redis client;
    private final RedisAPI redis;

    /**
     * Pooled client: each command borrows a connection, so a Redis restart or
     * failover heals on the next command (a single connect()ed connection never does).
     */
    public RedisQueueStore(Vertx vertx, String redisUri, String password) {
        this.client = Redis.createClient(vertx, new RedisOptions()
                .setConnectionString(redisUri)
                .setPassword(password == null || password.isEmpty() ? null : password)
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
    public Future<List<String>> lrangeAll(String key) {
        return redis.lrange(key, "0", "-1").map(response -> response.stream().map(Response::toString).toList());
    }

    @Override
    public Future<Long> lpos(String key, String value) {
        return redis.lpos(List.of(key, value)).map(response -> response == null ? null : response.toLong());
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
    public Future<EnqueueResult> enqueueIfAbsent(String queueKey, String callKey, String doneKey, String callId,
            long queuedAt) {
        return redis.eval(List.of(ENQUEUE_LUA, "3", queueKey, callKey, doneKey == null ? "" : doneKey,
                callId, String.valueOf(queuedAt)))
                .map(r -> new EnqueueResult(r.get(0).toInteger(), r.get(1).toInteger() == 1));
    }

    @Override
    public Future<Void> dequeue(String queueKey, String callKey, String doneKey, String callId, long doneTtlSeconds) {
        return redis.eval(List.of(DEQUEUE_LUA, "3", queueKey, callKey, doneKey, callId, String.valueOf(doneTtlSeconds)))
                .mapEmpty();
    }

    @Override
    public Future<String> nextRoundRobin(String cursorKey, List<String> rosterIds, Set<String> availableIds) {
        List<String> args = new ArrayList<>(List.of(ROUND_ROBIN_LUA, "1", cursorKey));
        rosterIds.forEach(id -> args.add((availableIds.contains(id) ? "+" : "-") + id));
        return redis.eval(args).map(r -> r == null ? null : r.toString());
    }

    @Override
    public Future<TransitionOutcome> transitionAgent(AgentTransition t) {
        List<String> args = new ArrayList<>();
        args.add(TRANSITION_LUA);
        args.addAll(List.of("3", t.agentKey(), t.eventKey() == null ? "" : t.eventKey(),
                t.absentKey() == null ? "" : t.absentKey()));
        args.add(String.join(",", t.allowedFrom()));
        args.add(t.expectWrapUpUntil() == null ? "" : t.expectWrapUpUntil());
        args.add(String.valueOf(t.eventTtlSeconds()));
        args.add(String.valueOf(t.incrTalkSeconds()));
        args.add(String.valueOf(t.incrCalls()));
        t.fields().forEach((k, v) -> {
            args.add(k);
            args.add(v);
        });
        return redis.eval(args).map(r -> switch (r.toInteger()) {
            case 1 -> TransitionOutcome.APPLIED;
            case -1 -> TransitionOutcome.DUPLICATE;
            default -> TransitionOutcome.SKIPPED;
        });
    }
}
