package com.cloudonix.opbx.amd.detector;

import com.cloudonix.opbx.amd.feature.EnergyAnalyzer;
import javax.sound.sampled.*;
import java.io.*;

public class AnalyzeFalsePositive {
    static final int WINDOW_MS = 20;
    static final int LOW_FREQ = 300;
    static final int HIGH_FREQ = 1000;
    static final int RUN_WINDOWS = 20; // 400ms
    static final double SILENCE_THR = 0.0005;

    public static void main(String[] args) throws Exception {
        String path = "../volumes/amd-dumps/WltSqfbXULgyN_PGE-mBCw.._82d88dd5-5d8b-4500-b472-907d3f8c4987.wav";
        File file = new File(path);
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

        System.out.println("File: " + file.getName());
        System.out.println("Sample rate: " + sampleRate + " Hz");
        System.out.println("Duration: " + (numWindows * WINDOW_MS) + " ms");
        System.out.println();

        double[] targetEnergies = new double[numWindows];
        double[] rmsVals = new double[numWindows];
        for (int i = 0; i < numWindows; i++) {
            double[] window = new double[windowSamples];
            System.arraycopy(pcm, i * windowSamples, window, 0, windowSamples);
            EnergyAnalyzer.EnergyBands bands = EnergyAnalyzer.computeEnergyBands(window, sampleRate, LOW_FREQ, HIGH_FREQ);
            targetEnergies[i] = bands.targetBandEnergy();
            rmsVals[i] = computeRms(window);
        }

        // Analyze the false positive at 18700ms and compare with earlier windows
        int[] inspectStarts = {12500 / WINDOW_MS, 18700 / WINDOW_MS};
        for (int start : inspectStarts) {
            int end = start + RUN_WINDOWS;
            if (end > numWindows) continue;
            double ms = start * WINDOW_MS;
            System.out.println("=== Window at " + (int) ms + "ms ===");

            double meanRms = 0, meanSq = 0;
            for (int j = start; j < end; j++) {
                meanRms += rmsVals[j];
                meanSq += rmsVals[j] * rmsVals[j];
            }
            meanRms /= RUN_WINDOWS;
            double variance = (meanSq / RUN_WINDOWS) - (meanRms * meanRms);
            double cv = (meanRms > 0) ? Math.sqrt(variance) / meanRms : 999;

            double meanEnergy = 0;
            for (int j = start; j < end; j++) meanEnergy += targetEnergies[j];
            meanEnergy /= RUN_WINDOWS;

            System.out.println("  meanRms=" + meanRms + " cv=" + cv + " meanEnergy=" + meanEnergy);
            System.out.println("  rms values:");
            for (int j = start; j < end; j++) {
                System.out.print(" " + String.format("%.5f", rmsVals[j]));
                if ((j - start + 1) % 5 == 0) System.out.println();
            }
            System.out.println();
        }

        // List all candidate runs with cv < 0.30
        System.out.println("=== All candidate runs (energy > silence, any cv) ===");
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

            double meanRms = 0, meanSq = 0;
            for (int j = start; j < end; j++) {
                meanRms += rmsVals[j];
                meanSq += rmsVals[j] * rmsVals[j];
            }
            meanRms /= RUN_WINDOWS;
            double variance = (meanSq / RUN_WINDOWS) - (meanRms * meanRms);
            double cv = (meanRms > 0) ? Math.sqrt(variance) / meanRms : 999;
            if (cv <= 0.30) {
                System.out.println("  start=" + (start * WINDOW_MS) + "ms cv=" + String.format("%.3f", cv));
            }
        }
    }

    static double computeRms(double[] x) {
        double sum = 0;
        for (double v : x) sum += v * v;
        return Math.sqrt(sum / x.length);
    }
}
