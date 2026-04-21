package com.cloudonix.opbx.amd.detector;

import com.cloudonix.opbx.amd.feature.EnergyAnalyzer;
import javax.sound.sampled.*;
import java.io.*;
import java.util.*;

public class OfflineToneTest {
    static final int WINDOW_MS = 20;
    static final int LOW_FREQ = 300;
    static final int HIGH_FREQ = 1000;
    static final int RUN_WINDOWS = 20; // 400ms
    static final double SILENCE_THR = 0.0005;
    static final double MAX_CV = 0.22;
    static final double MIN_RATIO = 10.0;
    static final int MIN_START_MS = 10500; // real beep is always around 12-13s

    public static void main(String[] args) throws Exception {
        File dumpDir = new File("../volumes/amd-dumps");
        File[] files = dumpDir.listFiles((dir, name) -> name.endsWith(".wav"));
        if (files == null || files.length == 0) {
            System.out.println("No WAV files found in " + dumpDir.getAbsolutePath());
            return;
        }
        Arrays.sort(files, Comparator.comparing(File::getName));

        int beepFiles = 0, totalFiles = files.length;
        for (File f : files) {
            List<BeepHit> hits = testFile(f);
            List<BeepHit> lateHits = hits.stream().filter(h -> h.startMs >= MIN_START_MS).toList();
            if (!lateHits.isEmpty()) beepFiles++;
            double durationSec = (f.length() - 44) / 2.0 / 16000.0; // approximate WAV duration
            System.out.println(f.getName() + " -> duration=" + String.format("%.1f", durationSec) +
                "s beeps=" + lateHits.size() + " first_at_ms=" + (lateHits.isEmpty() ? "none" : lateHits.get(0).startMs));
            for (BeepHit h : lateHits) {
                System.out.println("   BEEP start=" + h.startMs + "ms end=" + h.endMs + "ms cv=" + String.format("%.3f", h.cv));
            }
        }
        System.out.println("\n=== SUMMARY ===");
        System.out.println("Files with beep after " + MIN_START_MS + "ms: " + beepFiles + "/" + totalFiles);
    }

    static List<BeepHit> testFile(File file) throws Exception {
        List<BeepHit> hits = new ArrayList<>();
        AudioInputStream ais = AudioSystem.getAudioInputStream(file);
        AudioFormat format = ais.getFormat();
        byte[] raw = ais.readAllBytes();
        ais.close();

        double[] pcm = new double[raw.length / 2];
        for (int i = 0; i < pcm.length; i++) {
            short s = (short) ((raw[i * 2 + 1] << 8) | (raw[i * 2] & 0xFF));
            pcm[i] = s / 32768.0;
        }

        int sampleRate = (int) format.getSampleRate();
        int windowSamples = (int) Math.floor((WINDOW_MS / 1000.0) * sampleRate);
        int numWindows = pcm.length / windowSamples;

        double[] targetEnergies = new double[numWindows];
        double[] rmsVals = new double[numWindows];
        double[] ratios = new double[numWindows];
        for (int i = 0; i < numWindows; i++) {
            double[] window = new double[windowSamples];
            System.arraycopy(pcm, i * windowSamples, window, 0, windowSamples);
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
            if (!allAboveSilence) continue;

            double meanRms = 0, meanSq = 0, meanRatio = 0;
            for (int j = start; j < end; j++) {
                meanRms += rmsVals[j];
                meanSq += rmsVals[j] * rmsVals[j];
                meanRatio += ratios[j];
            }
            meanRms /= RUN_WINDOWS;
            meanRatio /= RUN_WINDOWS;
            double variance = (meanSq / RUN_WINDOWS) - (meanRms * meanRms);
            double cv = (meanRms > 0) ? Math.sqrt(variance) / meanRms : 999;

            if (cv <= MAX_CV && meanRatio >= MIN_RATIO) {
                int startMs = start * WINDOW_MS;
                int endMs = end * WINDOW_MS;
                // Skip overlapping hits (within 200ms)
                if (!hits.isEmpty() && startMs - hits.get(hits.size() - 1).endMs < 200) {
                    continue;
                }
                hits.add(new BeepHit(startMs, endMs, cv));
            }
        }
        return hits;
    }

    static double computeRms(double[] x) {
        double sum = 0;
        for (double v : x) sum += v * v;
        return Math.sqrt(sum / x.length);
    }

    record BeepHit(int startMs, int endMs, double cv) {}
}
