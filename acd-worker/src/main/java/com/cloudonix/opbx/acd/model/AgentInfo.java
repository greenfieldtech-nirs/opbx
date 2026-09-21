package com.cloudonix.opbx.acd.model;

/**
 * An agent as provided by Laravel in the poll roster.
 * Counters live in the worker's agent hash; this is the offer-time view.
 */
public record AgentInfo(String userId, String extensionNumber, long talkSecondsTotal, long callsHandledTotal) {
}
