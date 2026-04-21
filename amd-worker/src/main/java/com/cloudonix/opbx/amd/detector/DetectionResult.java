package com.cloudonix.opbx.amd.detector;

public class DetectionResult {
    public final String detector;
    public final ResultType result;
    public final double confidence;
    public final String reason;
    public final long timestampMs;

    public DetectionResult(String detector, ResultType result, double confidence, String reason, long timestampMs) {
        this.detector = detector;
        this.result = result;
        this.confidence = confidence;
        this.reason = reason;
        this.timestampMs = timestampMs;
    }
}
