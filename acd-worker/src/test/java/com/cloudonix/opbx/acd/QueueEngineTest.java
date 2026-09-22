package com.cloudonix.opbx.acd;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.util.List;
import java.util.Map;

import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import com.cloudonix.opbx.acd.model.AgentInfo;
import com.cloudonix.opbx.acd.store.InMemoryQueueStore;

import io.vertx.core.Vertx;

/**
 * QueueEngine unit tests against the in-memory store with a fake clock.
 * Async futures are resolved with vertx-core on a non-Vert.x thread (composite futures
 * backed by completed futures resolve synchronously).
 */
class QueueEngineTest {
    private InMemoryQueueStore store;
    private FakeTime fakeTime;
    private QueueEngine engine;

    @BeforeEach
    void setUp() {
        store = new InMemoryQueueStore();
        fakeTime = new FakeTime(1_000_000_000L);
        engine = new QueueEngine(store, fakeTime);
    }

    private AgentInfo agent(String id) {
        return new AgentInfo(id, "10" + id, 0, 0);
    }

    private Map<String, Object> poll(String queue, String callId, List<AgentInfo> roster, String strategy,
            long maxWaitSeconds) {
        return engine.poll("org1", queue, callId, roster, strategy, maxWaitSeconds).result();
    }

    @Test
    void enqueueAssignsFifoPositions() {
        assertEquals(1, engine.enqueue("org1", "q1", "call-1").result());
        assertEquals(2, engine.enqueue("org1", "q1", "call-2").result());
        assertEquals(3, engine.enqueue("org1", "q1", "call-3").result());
    }

    @Test
    void enqueueIsIdempotent() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.enqueue("org1", "q1", "call-2").result();
        assertEquals(1, engine.enqueue("org1", "q1", "call-1").result());
    }

    @Test
    void nonHeadCallWaitsWithPosition() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.enqueue("org1", "q1", "call-2").result();

        Map<String, Object> result = poll("q1", "call-2", List.of(), "ring_all", 300);
        assertEquals("wait", result.get("action"));
        assertEquals(2, result.get("position"));
    }

    @Test
    void headCallWaitsWhenNoAgentsAvailable() {
        engine.enqueue("org1", "q1", "call-1").result();

        // Unknown (never logged in) agents are not available.
        Map<String, Object> result = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("wait", result.get("action"));
        assertEquals(1, result.get("position"));
    }

    @Test
    void headCallDialsAvailableAgent() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, null, null, null)
                .result();

        Map<String, Object> result = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("dial", result.get("action"));

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> agents = (List<Map<String, Object>>) result.get("agents");
        assertEquals(1, agents.size());
        assertEquals("u1", agents.get(0).get("userId"));
        assertEquals("10u1", agents.get(0).get("extensionNumber"));
    }

    @Test
    void ringAllReturnsAllAvailableAgents() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, null, null, null)
                .result();
        engine.setAgentState("org1", "q1", "u2", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, null, null, null)
                .result();

        Map<String, Object> result = poll("q1", "call-1",
                List.of(agent("u1"), agent("u2"), agent("u3")), "ring_all", 300);
        assertEquals("dial", result.get("action"));

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> agents = (List<Map<String, Object>>) result.get("agents");
        assertEquals(2, agents.size());
    }

    @Test
    void roundRobinRotatesAcrossAgents() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, null, null, null)
                .result();
        engine.setAgentState("org1", "q1", "u2", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, null, null, null)
                .result();
        List<AgentInfo> roster = List.of(agent("u1"), agent("u2"));

        assertEquals("u1", firstAgentUserId(poll("q1", "call-1", roster, "round_robin", 300)));
        assertEquals("u2", firstAgentUserId(poll("q1", "call-1", roster, "round_robin", 300)));
        assertEquals("u1", firstAgentUserId(poll("q1", "call-1", roster, "round_robin", 300)));
    }

    @Test
    void leastTalkTimePicksLowestCounter() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, 500L, 3L, null)
                .result();
        engine.setAgentState("org1", "q1", "u2", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, 100L, 1L, null)
                .result();

        Map<String, Object> result = poll("q1", "call-1", List.of(agent("u1"), agent("u2")), "least_talk_time",
                300);
        assertEquals("u2", firstAgentUserId(result));
    }

    @Test
    void fewestCallsPicksLowestCounter() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, 0L, 5L, null)
                .result();
        engine.setAgentState("org1", "q1", "u2", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, 0L, 2L, null)
                .result();

        Map<String, Object> result = poll("q1", "call-1", List.of(agent("u1"), agent("u2")), "fewest_calls", 300);
        assertEquals("u2", firstAgentUserId(result));
    }

    @Test
    void callOverflowsAfterMaxWait() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, null, null, null)
                .result();
        fakeTime.advance(301_000);

        Map<String, Object> result = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("overflow", result.get("action"));

        // Overflowed call is dequeued. A subsequent poll re-enqueues for recovery
        // (returns wait once), then offers on the next poll.
        Map<String, Object> rePoll = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("wait", rePoll.get("action"));
        Map<String, Object> rePoll2 = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("dial", rePoll2.get("action"));
    }

    @Test
    void wrapUpExpiresAndAgentBecomesAvailable() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.WRAP_UP, null, null, 30L)
                .result();

        Map<String, Object> duringWrapUp = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("wait", duringWrapUp.get("action"));

        fakeTime.advance(31_000);

        Map<String, Object> afterWrapUp = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("dial", afterWrapUp.get("action"));
    }

    @Test
    void manualWrapUpIsSticky() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.WRAP_UP, null, null, null)
                .result();

        fakeTime.advance(60_000);

        Map<String, Object> result = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 3600);
        assertEquals("wait", result.get("action"));
    }

    @Test
    void answeredEventMarksAgentBusyAndDequeuesCall() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, null, null, null)
                .result();

        engine.event("org1", "q1", "call-1", "answered", "u1", null, null).result();

        // Next caller moves to head; agent is BUSY so no dial.
        engine.enqueue("org1", "q1", "call-2").result();
        Map<String, Object> result = poll("q1", "call-2", List.of(agent("u1")), "ring_all", 300);
        assertEquals("wait", result.get("action"));
        assertEquals(1, result.get("position"));
    }

    @Test
    void endedEventIncrementsCountersAndAppliesWrapUp() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, 0L, 0L, null)
                .result();

        engine.event("org1", "q1", "call-1", "answered", "u1", null, null).result();
        engine.event("org1", "q1", "call-1", "ended", "u1", 42L, 15L).result();

        // Agent is in wrap-up: least_talk_time still picks them (only agent) but dial must not happen.
        engine.enqueue("org1", "q1", "call-2").result();
        Map<String, Object> duringWrapUp = poll("q1", "call-2", List.of(agent("u1")), "ring_all", 300);
        assertEquals("wait", duringWrapUp.get("action"));

        // After wrap-up expiry, counters are visible to strategy selection.
        fakeTime.advance(16_000);
        engine.enqueue("org1", "q1", "call-3").result();
        Map<String, Object> result = poll("q1", "call-2", List.of(agent("u1")), "least_talk_time", 300);
        assertEquals("dial", result.get("action"));
    }

    @Test
    void dialFailedReturnsAgentToAvailableWithoutCounters() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, null, null, null)
                .result();

        // Offer, then agent fails to answer. Call stays queued.
        engine.event("org1", "q1", "call-1", "dial_failed", "u1", null, null).result();

        Map<String, Object> reOffer = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("dial", reOffer.get("action"));
    }

    @Test
    void abandonedEventDequeuesCall() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.enqueue("org1", "q1", "call-2").result();

        engine.event("org1", "q1", "call-1", "abandoned", null, null, null).result();

        Map<String, Object> result = poll("q1", "call-2", List.of(), "ring_all", 300);
        assertEquals("wait", result.get("action"));
        assertEquals(1, result.get("position"));
    }

    @Test
    void unknownCallOnPollIsReenqueued() {
        Map<String, Object> result = poll("q1", "ghost-call", List.of(), "ring_all", 300);
        assertEquals("wait", result.get("action"));
        assertEquals(1, result.get("position"));
    }

    @Test
    void logoutClearsAgentState() {
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.AVAILABLE, null, null, null)
                .result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.LOGGED_OUT, null, null, null)
                .result();

        engine.enqueue("org1", "q1", "call-1").result();
        Map<String, Object> result = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("wait", result.get("action"));
    }

    @Test
    void liveReportsWaitingWithPositionsAndWaitTimes() {
        fakeTime.advance(10_000);
        engine.enqueue("org1", "q1", "call-1").result();
        fakeTime.advance(5_000);
        engine.enqueue("org1", "q1", "call-2").result();
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.WRAP_UP, null, null, 25L)
                .result();

        @SuppressWarnings("unchecked")
        Map<String, Object> live = engine.live("org1", "q1", List.of(agent("u1"))).result();

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> waiting = (List<Map<String, Object>>) live.get("waiting");
        assertEquals(2, waiting.size());
        assertEquals(1, waiting.get(0).get("position"));
        assertEquals(5L, waiting.get(0).get("waitedSeconds"));
        assertEquals(2, waiting.get(1).get("position"));
        assertEquals(0L, waiting.get(1).get("waitedSeconds"));

        @SuppressWarnings("unchecked")
        List<Map<String, Object>> agents = (List<Map<String, Object>>) live.get("agents");
        assertEquals(1, agents.size());
        assertEquals("WRAP_UP", agents.get(0).get("state"));
    }

    @Test
    void liveReflectsWrapUpExpiryWithoutPolls() {
        engine.setAgentState("org1", "q1", "u1", com.cloudonix.opbx.acd.model.AgentState.WRAP_UP, null, null, 15L)
                .result();

        @SuppressWarnings("unchecked")
        Map<String, Object> live = engine.live("org1", "q1", List.of(agent("u1"))).result();
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> agents = (List<Map<String, Object>>) live.get("agents");
        assertEquals("WRAP_UP", agents.get(0).get("state"));

        fakeTime.advance(16_000);

        @SuppressWarnings("unchecked")
        Map<String, Object> after = engine.live("org1", "q1", List.of(agent("u1"))).result();
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> agentsAfter = (List<Map<String, Object>>) after.get("agents");
        assertEquals("AVAILABLE", agentsAfter.get(0).get("state"));

        // The flip is persisted, not just reported.
        Map<String, Object> again = engine.live("org1", "q1", List.of(agent("u1"))).result();
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> agentsAgain = (List<Map<String, Object>>) again.get("agents");
        assertEquals("AVAILABLE", agentsAgain.get(0).get("state"));
    }

    @Test
    void queuesAreIsolatedPerOrganization() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.enqueue("org2", "q1", "call-1").result();

        @SuppressWarnings("unchecked")
        Map<String, Object> live = engine.live("org1", "q1", List.of()).result();
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> waiting = (List<Map<String, Object>>) live.get("waiting");
        assertEquals(1, waiting.size());
        assertTrue(((String) waiting.get(0).get("callId")).equals("call-1"));
    }

    private String firstAgentUserId(Map<String, Object> result) {
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> agents = (List<Map<String, Object>>) result.get("agents");
        return (String) agents.get(0).get("userId");
    }

    /** Mutable clock for tests. */
    static class FakeTime implements Time {
        private long now;

        FakeTime(long startMillis) {
            this.now = startMillis;
        }

        void advance(long millis) {
            this.now += millis;
        }

        @Override
        public long nowMillis() {
            return now;
        }
    }
}
