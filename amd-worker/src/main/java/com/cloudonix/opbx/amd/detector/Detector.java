package com.cloudonix.opbx.amd.detector;

public interface Detector {
    String name();
    DetectionResult process(AudioSegment segment);
    void reset();

    class AudioSegment {
        public final double[] pcmData;
        public final int sampleRate;
        public final double durationMs;
        public final double startTimestampMs;

        public AudioSegment(double[] pcmData, int sampleRate, double durationMs, double startTimestampMs) {
            this.pcmData = pcmData;
            this.sampleRate = sampleRate;
            this.durationMs = durationMs;
            this.startTimestampMs = startTimestampMs;
        }
    }
}
