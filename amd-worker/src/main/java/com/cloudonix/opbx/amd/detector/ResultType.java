package com.cloudonix.opbx.amd.detector;

public enum ResultType {
    VOICEMAIL("voicemail"),
    HUMAN("human"),
    UNKNOWN("unknown");

    public final String value;

    ResultType(String value) {
        this.value = value;
    }
}
