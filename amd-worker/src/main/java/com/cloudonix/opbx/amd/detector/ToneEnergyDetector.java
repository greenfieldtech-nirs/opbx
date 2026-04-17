package com.cloudonix.opbx.amd.detector;

import com.cloudonix.opbx.amd.feature.EnergyAnalyzer;
import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

public class ToneEnergyDetector implements Detector {
    private static final Logger logger = LoggerFactory.getLogger(ToneEnergyDetector.class);

    private static final int WINDOW_MS = 20;
    private static final int LOW_FREQ = 300;
    private static final int HIGH_FREQ = 1000;
    private static final int RUN_WINDOWS = 20; // 400ms
    private static final double SILENCE_THR = 0.0005;
    private static final double MAX_CV = 0.22;
    private static final double MIN_RATIO = 10.0;
    private static final int MIN_BEEP_START_MS = 10500;

    @Override
    public String name() {
        return "tone_energy";
    }

    @Override
    public DetectionResult process(AudioSegment segment) {
        int sampleRate = segment.sampleRate;
        int windowSamples = (int) Math.floor((WINDOW_MS / 1000.0) * sampleRate);
        int numWindows = segment.pcmData.length / windowSamples;
        if (numWindows < RUN_WINDOWS) {
            return null;
        }

        double[] targetEnergies = new double[numWindows];
        double[] rmsVals = new double[numWindows];
        double[] ratios = new double[numWindows];
        for (int i = 0; i < numWindows; i++) {
            int startSample = i * windowSamples;
            double[] window = new double[windowSamples];
            System.arraycopy(segment.pcmData, startSample, window, 0, windowSamples);
            EnergyAnalyzer.EnergyBands bands = EnergyAnalyzer.computeEnergyBands(window, sampleRate, LOW_FREQ, HIGH_FREQ);
            targetEnergies[i] = bands.targetBandEnergy();
            rmsVals[i] = computeRms(window);
            ratios[i] = bands.ratio();
        }

        for (int start = 0; start <= numWindows - RUN_WINDOWS; start++) {
            int end = start + RUN_WINDOWS;

            boolean allAboveSilence = true;
            for (int i = start; i < end; i++) {
                if (targetEnergies[i] <= SILENCE_THR) {
                    allAboveSilence = false;
                    break;
                }
            }
            if (!allAboveSilence) {
                continue;
            }

            double meanRms = 0;
            double meanSq = 0;
            double meanRatio = 0;
            for (int j = start; j < end; j++) {
                meanRms += rmsVals[j];
                meanSq += rmsVals[j] * rmsVals[j];
                meanRatio += ratios[j];
            }
            meanRms /= RUN_WINDOWS;
            meanRatio /= RUN_WINDOWS;
            double variance = (meanSq / RUN_WINDOWS) - (meanRms * meanRms);
            double cv = (meanRms > 0) ? Math.sqrt(variance) / meanRms : 999;

            double beepStartMs = segment.startTimestampMs + (start * WINDOW_MS);
            double beepEndMs = segment.startTimestampMs + (end * WINDOW_MS);

            if (cv <= MAX_CV && meanRatio >= MIN_RATIO && beepStartMs >= MIN_BEEP_START_MS) {
                logger.info("ToneEnergy detected beep start_ms={} end_ms={} cv={} ratio={} band={}-{}Hz",
                    (int) beepStartMs, (int) beepEndMs, String.format("%.3f", cv), String.format("%.1f", meanRatio), LOW_FREQ, HIGH_FREQ);
                return new DetectionResult(
                    name(),
                    ResultType.VOICEMAIL,
                    0.90,
                    String.format("Tone detected in %d-%dHz for %.0fms (cv=%.3f ratio=%.1f)",
                        LOW_FREQ, HIGH_FREQ, beepEndMs - beepStartMs, cv, meanRatio),
                    (long) beepEndMs
                );
            }
        }

        return null;
    }

    private static double computeRms(double[] samples) {
        double sum = 0;
        for (double v : samples) {
            sum += v * v;
        }
        return Math.sqrt(sum / samples.length);
    }

    @Override
    public void reset() {
        // stateless
    }
}
