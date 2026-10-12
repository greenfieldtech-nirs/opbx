package com.cloudonix.opbx.acd;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.util.List;
import java.util.Map;

import org.junit.jupiter.api.BeforeEach;
import org.junit.jupiter.api.Test;

import com.cloudonix.opbx.acd.model.AgentInfo;
import com.cloudonix.opbx.acd.model.AgentState;
import com.cloudonix.opbx.acd.store.InMemoryQueueStore;

import io.vertx.core.Future;
import io.vertx.core.Promise;

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

        // Overflowed call is dequeued; an in-flight poll waits and does not re-enqueue a ghost.
        Map<String, Object> rePoll = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("wait", rePoll.get("action"));
        assertEquals(List.of(), store.lrangeAll(QueueEngine.queueKey("org1", "q1")).result());
    }

    @Test
    void pollAfterAnsweredWaitsWithoutReenqueue() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.event("org1", "q1", "call-1", "answered", null, null, null, null).result();

        Map<String, Object> result = poll("q1", "call-1", List.of(), "ring_all", 300);

        assertEquals("wait", result.get("action"));
        assertEquals(List.of(), store.lrangeAll(QueueEngine.queueKey("org1", "q1")).result());
    }

    @Test
    void headCallWithMissingHashWaitsInsteadOfOverflowing() {
        engine.enqueue("org1", "q1", "call-1").result();
        store.del(QueueEngine.callKey("org1", "q1", "call-1")).result(); // dequeued mid-poll
        fakeTime.advance(301_000);

        assertEquals("wait", poll("q1", "call-1", List.of(), "ring_all", 300).get("action"));
    }

    @Test
    void lateAnsweredForAnEndedCallDoesNotMarkTheAgentBusy() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, null, null, null).result();
        engine.event("org1", "q1", "call-1", "abandoned", null, null, null, "x1").result();

        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "a1").result();

        assertEquals("AVAILABLE", agentHash("u1").get("state"));
    }

    @Test
    void roundRobinPassesABusyAgentsTurnOnWithoutSkippingAnyone() {
        for (String id : List.of("u1", "u2", "u3")) {
            engine.setAgentState("org1", "q1", id, AgentState.AVAILABLE, null, null, null).result();
        }
        List<AgentInfo> roster = List.of(agent("u1"), agent("u2"), agent("u3"));
        engine.enqueue("org1", "q1", "call-1").result();

        assertEquals("u1", firstAgentUserId(poll("q1", "call-1", roster, "round_robin", 300)));
        // u1 goes busy on another call: u2 is next (an index into the shrunken list would pick u3).
        engine.setAgentState("org1", "q1", "u1", AgentState.BUSY, null, null, null).result();
        assertEquals("u2", firstAgentUserId(poll("q1", "call-1", roster, "round_robin", 300)));
        assertEquals("u3", firstAgentUserId(poll("q1", "call-1", roster, "round_robin", 300)));
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, null, null, null).result();
        assertEquals("u1", firstAgentUserId(poll("q1", "call-1", roster, "round_robin", 300)));
    }

    @Test
    void roundRobinWithOneBusyAgentOfThreeStaysEven() {
        for (String id : List.of("u1", "u2", "u3")) {
            engine.setAgentState("org1", "q1", id, AgentState.AVAILABLE, null, null, null).result();
        }
        engine.setAgentState("org1", "q1", "u2", AgentState.BUSY, null, null, null).result();
        List<AgentInfo> roster = List.of(agent("u1"), agent("u2"), agent("u3"));
        engine.enqueue("org1", "q1", "call-1").result();

        Map<String, Integer> counts = new java.util.HashMap<>();
        for (int i = 0; i < 6; i++) {
            counts.merge(firstAgentUserId(poll("q1", "call-1", roster, "round_robin", 300)), 1, Integer::sum);
        }
        assertEquals(Map.of("u1", 3, "u3", 3), counts);
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

        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, null).result();

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

        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, null).result();
        engine.event("org1", "q1", "call-1", "ended", "u1", 42L, 15L, null).result();

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

        // A spurious bridge marked the agent BUSY, then the dial failed. Call stays queued.
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, null).result();
        engine.enqueue("org1", "q1", "call-1").result();
        engine.event("org1", "q1", "call-1", "dial_failed", "u1", null, null, null).result();

        Map<String, Object> reOffer = poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        assertEquals("dial", reOffer.get("action"));
    }

    @Test
    void requeueReleasesTheWinnerAndPutsTheCallBackWithItsTombstoneCleared() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, null, null, null).result();
        engine.setAgentState("org1", "q1", "u2", AgentState.AVAILABLE, null, null, null).result();

        // u1 won the conference claim ('answered' dequeued the call) but never connected.
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, null).result();
        assertEquals("BUSY", agentHash("u1").get("state"));

        engine.event("org1", "q1", "call-1", "requeue", "u1", null, null, null).result();

        assertEquals("AVAILABLE", agentHash("u1").get("state"));
        assertEquals("dial", poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300).get("action"));

        // The next winner must be marked BUSY: the tombstone from the first 'answered' is gone.
        engine.event("org1", "q1", "call-1", "answered", "u2", null, null, null).result();
        assertEquals("BUSY", agentHash("u2").get("state"));
    }

    private List<Map<String, Object>> waiting() {
        @SuppressWarnings("unchecked")
        List<Map<String, Object>> waiting = (List<Map<String, Object>>) engine.live("org1", "q1", List.of()).result()
                .get("waiting");
        return waiting;
    }

    @Test
    void requeueRestoresTheOriginalPlaceAndWaitTime() {
        engine.enqueue("org1", "q1", "call-1").result();
        fakeTime.advance(10_000);
        engine.enqueue("org1", "q1", "call-2").result();
        fakeTime.advance(10_000);
        engine.enqueue("org1", "q1", "call-3").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, null, null, null).result();

        // call-2 (middle) is answered, then the winner drops before the caller joined.
        engine.event("org1", "q1", "call-2", "answered", "u1", null, null, "e1").result();
        fakeTime.advance(30_000);
        engine.event("org1", "q1", "call-2", "requeue", "u1", null, null, "e2").result();

        List<Map<String, Object>> waiting = waiting();
        assertEquals(List.of("call-1", "call-2", "call-3"), waiting.stream().map(w -> w.get("callId")).toList());
        assertEquals(40L, waiting.get(1).get("waitedSeconds"), "wait time counts from the original enqueue");

        // The head call too: it goes back to the head, not the tail.
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "e3").result();
        engine.event("org1", "q1", "call-1", "requeue", "u1", null, null, "e4").result();
        assertEquals(List.of("call-1", "call-2", "call-3"), waiting().stream().map(w -> w.get("callId")).toList());
    }

    @Test
    void replayedRequeueIsIgnoredEvenAfterTheCallWasAnsweredAgain() {
        engine.enqueue("org1", "q1", "call-1").result();
        fakeTime.advance(1_000);
        engine.enqueue("org1", "q1", "call-2").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, null, null, null).result();
        engine.setAgentState("org1", "q1", "u2", AgentState.AVAILABLE, null, null, null).result();

        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "e1").result();
        engine.event("org1", "q1", "call-1", "requeue", "u1", null, null, "e2").result();
        engine.event("org1", "q1", "call-1", "requeue", "u1", null, null, "e2").result();
        assertEquals(List.of("call-1", "call-2"), waiting().stream().map(w -> w.get("callId")).toList());

        engine.event("org1", "q1", "call-1", "answered", "u2", null, null, "e3").result();
        engine.event("org1", "q1", "call-1", "requeue", "u1", null, null, "e2").result();
        assertEquals(List.of("call-2"), waiting().stream().map(w -> w.get("callId")).toList());
        assertEquals("BUSY", agentHash("u2").get("state"));
    }

    @Test
    void replayedAnsweredAfterARequeueDoesNotDequeueTheCallAgain() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, null, null, null).result();

        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "e1").result();
        engine.event("org1", "q1", "call-1", "requeue", "u1", null, null, "e2").result();
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "e1").result(); // Laravel replay

        assertEquals(List.of("call-1"), waiting().stream().map(w -> w.get("callId")).toList());
        assertEquals("AVAILABLE", agentHash("u1").get("state"));
        assertEquals("dial", poll("q1", "call-1", List.of(agent("u1")), "ring_all", 300).get("action"));
    }

    @Test
    void requeueAfterTheCallEndedDoesNotResurrectIt() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, null).result();
        engine.event("org1", "q1", "call-1", "abandoned", null, null, null, null).result();

        engine.event("org1", "q1", "call-1", "requeue", "u1", null, null, null).result();

        assertTrue(waiting().isEmpty());
        // Tombstone kept: a late poll does not re-enqueue the ghost either.
        assertEquals("wait", poll("q1", "call-1", List.of(), "ring_all", 300).get("action"));
        assertTrue(waiting().isEmpty());
    }

    @Test
    void endedAfterLogoutDoesNotLogTheAgentBackIn() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, 0L, 0L, null).result();
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "e1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.LOGGED_OUT, null, null, null).result();

        engine.event("org1", "q1", "call-1", "ended", "u1", 42L, 0L, "e2").result();

        assertTrue(store.hgetall(QueueEngine.agentKey("org1", "q1", "u1")).result().isEmpty());
        engine.enqueue("org1", "q1", "call-2").result();
        assertEquals("wait", poll("q1", "call-2", List.of(agent("u1")), "ring_all", 300).get("action"));
    }

    @Test
    void dialFailedKeepsAPausedAgentPaused() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, null, null, null).result();
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, null).result();
        engine.setAgentState("org1", "q1", "u1", AgentState.WRAP_UP, null, null, null).result(); // sticky pause

        engine.event("org1", "q1", "call-1", "dial_failed", "u1", null, null, null).result();

        assertEquals("WRAP_UP", agentHash("u1").get("state"));
    }

    @Test
    void agentPausedWhileRingingStaysPausedButTheCallCounts() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, 100L, 2L, null).result();
        engine.setAgentState("org1", "q1", "u1", AgentState.WRAP_UP, null, null, null).result(); // pause while ringing

        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "a1").result();
        assertEquals("WRAP_UP", agentHash("u1").get("state"));
        engine.event("org1", "q1", "call-1", "ended", "u1", 30L, 20L, "e1").result();

        Map<String, String> hash = agentHash("u1");
        assertEquals("WRAP_UP", hash.get("state"));
        assertEquals("0", hash.get("wrapUpUntil")); // still the sticky pause, not a post-call wrap-up
        assertEquals("130", hash.get("talkSecondsTotal"));
        assertEquals("3", hash.get("callsHandledTotal"));
        engine.enqueue("org1", "q1", "call-2").result();
        assertEquals("wait", poll("q1", "call-2", List.of(agent("u1")), "ring_all", 300).get("action"));
    }

    @Test
    void replayedEndedForAPausedAgentCountsOnce() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, 100L, 2L, null).result();
        engine.setAgentState("org1", "q1", "u1", AgentState.WRAP_UP, null, null, null).result();
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "a1").result();
        engine.event("org1", "q1", "call-1", "ended", "u1", 30L, 0L, "e1").result();

        engine.event("org1", "q1", "call-1", "ended", "u1", 30L, 0L, "e1").result();

        Map<String, String> hash = agentHash("u1");
        assertEquals("WRAP_UP", hash.get("state"));
        assertEquals("130", hash.get("talkSecondsTotal"));
        assertEquals("3", hash.get("callsHandledTotal"));
    }

    @Test
    void dialFailedForALoggedOutAgentDoesNotRecreateTheHash() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.event("org1", "q1", "call-1", "dial_failed", "u1", null, null, null).result();

        assertTrue(agentHash("u1").isEmpty());
    }

    @Test
    void replayedEndedEventCountsOnce() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, 100L, 2L, null).result();
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "a1").result();
        engine.event("org1", "q1", "call-1", "ended", "u1", 30L, 0L, "e1").result();
        // The agent takes the next call, then Laravel replays the first 'ended'.
        engine.enqueue("org1", "q1", "call-2").result();
        engine.event("org1", "q1", "call-2", "answered", "u1", null, null, "a2").result();

        engine.event("org1", "q1", "call-1", "ended", "u1", 30L, 0L, "e1").result();

        Map<String, String> hash = agentHash("u1");
        assertEquals("BUSY", hash.get("state"));
        assertEquals("130", hash.get("talkSecondsTotal"));
        assertEquals("3", hash.get("callsHandledTotal"));
    }

    @Test
    void replayedAnsweredEventDoesNotMarkTheAgentBusyAgain() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.setAgentState("org1", "q1", "u1", AgentState.AVAILABLE, null, null, null).result();
        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "a1").result();
        engine.event("org1", "q1", "call-1", "ended", "u1", 5L, 0L, "e1").result();

        engine.event("org1", "q1", "call-1", "answered", "u1", null, null, "a1").result();

        assertEquals("AVAILABLE", agentHash("u1").get("state"));
    }

    @Test
    void wrapUpExpiryDoesNotRecreateAnAgentLoggedOutMidRead() {
        GatedStore gated = new GatedStore();
        QueueEngine gatedEngine = new QueueEngine(gated, fakeTime);
        gatedEngine.enqueue("org1", "q1", "call-1").result();
        gatedEngine.setAgentState("org1", "q1", "u1", AgentState.WRAP_UP, null, null, 10L).result();
        fakeTime.advance(11_000);

        // The poll reads the expired WRAP_UP hash; the read is held while the agent logs out.
        gated.holdNextRead(QueueEngine.agentKey("org1", "q1", "u1"));
        var pending = gatedEngine.poll("org1", "q1", "call-1", List.of(agent("u1")), "ring_all", 300);
        gatedEngine.setAgentState("org1", "q1", "u1", AgentState.LOGGED_OUT, null, null, null).result();
        gated.release();

        assertEquals("wait", pending.result().get("action"));
        assertTrue(gated.hgetall(QueueEngine.agentKey("org1", "q1", "u1")).result().isEmpty());
    }

    @Test
    void abandonedEventDequeuesCall() {
        engine.enqueue("org1", "q1", "call-1").result();
        engine.enqueue("org1", "q1", "call-2").result();

        engine.event("org1", "q1", "call-1", "abandoned", null, null, null, null).result();

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

    private Map<String, String> agentHash(String userId) {
        return store.hgetall(QueueEngine.agentKey("org1", "q1", userId)).result();
    }

    /** Store whose next read of one key is answered (with the value at read time) only on release(). */
    static class GatedStore extends InMemoryQueueStore {
        private String heldKey;
        private Promise<Map<String, String>> held;
        private Map<String, String> snapshot;

        void holdNextRead(String key) {
            heldKey = key;
        }

        void release() {
            held.complete(snapshot);
        }

        @Override
        public Future<Map<String, String>> hgetall(String key) {
            if (key.equals(heldKey)) {
                heldKey = null;
                snapshot = super.hgetall(key).result();
                held = Promise.promise();
                return held.future();
            }
            return super.hgetall(key);
        }
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
