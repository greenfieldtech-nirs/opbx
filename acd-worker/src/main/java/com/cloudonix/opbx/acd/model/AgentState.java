package com.cloudonix.opbx.acd.model;

/** Agent presence states. An agent is offered calls only in AVAILABLE. */
public enum AgentState {
    AVAILABLE,
    /** On a queued call; returns to AVAILABLE/WRAP_UP on call end. */
    BUSY,
    /** Post-call or manual pause; auto-expires when wrapUpUntil passes (0 = sticky). */
    WRAP_UP,
    LOGGED_OUT;

    public static AgentState fromString(String value) {
        if (value == null) {
            return LOGGED_OUT;
        }
        try {
            return AgentState.valueOf(value);
        } catch (IllegalArgumentException e) {
            return LOGGED_OUT;
        }
    }
}
