package com.cloudonix.opbx.amd.detector;

import com.cloudonix.opbx.amd.feature.EnergyAnalyzer;
import javax.sound.sampled.*;
import java.io.*;

public class AnalyzeAllBeeps {
    static final int WINDOW_MS = 20;
    static final int LOW_FREQ = 300;
    static final int HIGH_FREQ = 1000;
    static final int RUN_WINDOWS = 20; // 400ms
    static final double SILENCE_THR = 0.0005;

    public static void main(String[] args) throws Exception {
        File dumpDir = new File("../volumes/amd-dumps");
        File[] files = dumpDir.listFiles((dir, name) -> name.endsWith(".wav"));
        if (files == null) return;

        System.out.println(String.format("%-60s %10s %10s %10s %10s %10s %10s %10s",
            "file", "start_ms", "cv", "meanRms", "energy", "peakConc", "ratio", "minRms/maxRms"));
        System.out.println("-".repeat(130));

        for (File file : files) {
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
            double[] peakConcs = new double[numWindows];
            double[] ratios = new double[numWindows];
            for (int i = 0; i < numWindows; i++) {
                double[] window = new double[windowSamples];
                System.arraycopy(pcm, i * windowSamples, window, 0, windowSamples);
                EnergyAnalyzer.EnergyBands bands = EnergyAnalyzer.computeEnergyBands(window, sampleRate, LOW_FREQ, HIGH_FREQ);
                targetEnergies[i] = bands.targetBandEnergy();
                rmsVals[i] = computeRms(window);
                peakConcs[i] = bands.peakConcentration();
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

                double meanRms = 0, meanSq = 0, meanEnergy = 0, meanPeakConc = 0, meanRatio = 0;
                double minRms = Double.MAX_VALUE, maxRms = 0;
                for (int j = start; j < end; j++) {
                    meanRms += rmsVals[j];
                    meanSq += rmsVals[j] * rmsVals[j];
                    meanEnergy += targetEnergies[j];
                    meanPeakConc += peakConcs[j];
                    meanRatio += ratios[j];
                    minRms = Math.min(minRms, rmsVals[j]);
                    maxRms = Math.max(maxRms, rmsVals[j]);
                }
                meanRms /= RUN_WINDOWS;
                double variance = (meanSq / RUN_WINDOWS) - (meanRms * meanRms);
                double cv = (meanRms > 0) ? Math.sqrt(variance) / meanRms : 999;
                meanEnergy /= RUN_WINDOWS;
                meanPeakConc /= RUN_WINDOWS;
                meanRatio /= RUN_WINDOWS;

                int ms = start * WINDOW_MS;
                // Only print detections after 10s OR the best few before 10s
                boolean print = (ms >= 10000 && cv <= 0.30) || cv <= 0.15;
                if (print) {
                    String basename = file.getName().length() > 60 ? file.getName().substring(0, 60) : file.getName();
                    System.out.println(String.format(
                        "%-60s %10d %10.3f %10.5f %10.2f %10.4f %10.2f %10.3f",
                        basename, ms, cv, meanRms, meanEnergy, meanPeakConc, meanRatio, minRms/maxRms));
                }
            }
        }
    }

    static double computeRms(double[] x) {
        double sum = 0;
        for (double v : x) sum += v * v;
        return Math.sqrt(sum / x.length);
    }
}
