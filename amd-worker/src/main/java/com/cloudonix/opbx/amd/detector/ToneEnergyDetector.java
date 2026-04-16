package com.cloudonix.opbx.amd.detector;

import com.cloudonix.opbx.amd.feature.EnergyAnalyzer;

public class ToneEnergyDetector implements Detector {
    private static final int MIN_TONE_MS = 200;
    private static final int WINDOW_MS = 50;
    private static final int SAMPLE_RATE = 16000;
    private static final double TONE_RATIO_THRESHOLD = 2.0;

    private Double toneStartMs = null;
    private boolean inTone = false;

    @Override
    public String name() {
        return "tone_energy";
    }

    @Override
    public DetectionResult process(AudioSegment segment) {
        int windowSamples = (int) Math.floor((WINDOW_MS / 1000.0) * SAMPLE_RATE);
        int numWindows = segment.pcmData.length / windowSamples;

        for (int i = 0; i < numWindows; i++) {
            int startSample = i * windowSamples;
            double[] window = new double[windowSamples];
            System.arraycopy(segment.pcmData, startSample, window, 0, windowSamples);
            double windowStartMs = segment.startTimestampMs + (i * WINDOW_MS);
            EnergyAnalyzer.EnergyBands bands = EnergyAnalyzer.computeEnergyBands(window, SAMPLE_RATE, 800, 2500);

            boolean isTone = bands.targetBandEnergy() > 0.0001 && bands.ratio() > TONE_RATIO_THRESHOLD;

            if (isTone) {
                if (!inTone) {
                    inTone = true;
                    toneStartMs = windowStartMs;
                }
            } else {
                if (inTone && toneStartMs != null) {
                    double toneDurationMs = windowStartMs - toneStartMs;
                    if (toneDurationMs >= MIN_TONE_MS) {
                        return new DetectionResult(
                            name(),
                            ResultType.VOICEMAIL,
                            0.85,
                            String.format("Pure tone detected in 800-2500Hz band for %.0fms", toneDurationMs),
                            (long) windowStartMs
                        );
                    }
                }
                inTone = false;
                toneStartMs = null;
            }
        }

        if (inTone && toneStartMs != null) {
            double toneDurationMs = segment.startTimestampMs + segment.durationMs - toneStartMs;
            if (toneDurationMs >= MIN_TONE_MS) {
                return new DetectionResult(
                    name(),
                    ResultType.VOICEMAIL,
                    0.85,
                    String.format("Pure tone detected in 800-2500Hz band for %.0fms", toneDurationMs),
                    (long) (segment.startTimestampMs + segment.durationMs)
                );
            }
        }

        return null;
    }

    @Override
    public void reset() {
        toneStartMs = null;
        inTone = false;
    }
}
