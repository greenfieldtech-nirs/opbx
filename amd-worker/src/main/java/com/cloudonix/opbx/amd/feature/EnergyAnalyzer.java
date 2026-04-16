package com.cloudonix.opbx.amd.feature;

import org.jtransforms.fft.DoubleFFT_1D;

public class EnergyAnalyzer {
    public record EnergyBands(double totalEnergy, double targetBandEnergy, double otherEnergy, double ratio) {}

    public static EnergyBands computeEnergyBands(double[] pcmData, int sampleRate, int lowFreq, int highFreq) {
        int n = (int) Math.pow(2, Math.ceil(Math.log(pcmData.length) / Math.log(2)));
        double[] padded = new double[n];
        System.arraycopy(pcmData, 0, padded, 0, pcmData.length);

        DoubleFFT_1D fft = new DoubleFFT_1D(n);
        fft.realForward(padded);

        double binSize = (double) sampleRate / n;
        double totalEnergy = 0;
        double targetBandEnergy = 0;

        int numBins = n / 2 + 1;
        for (int i = 0; i < numBins; i++) {
            double real = (i == 0 || i == n / 2) ? padded[i] : padded[2 * i];
            double imag = (i == 0 || i == n / 2) ? 0 : padded[2 * i + 1];
            double magnitude = Math.sqrt(real * real + imag * imag);
            double energy = magnitude * magnitude;
            totalEnergy += energy;
            double freq = i * binSize;
            if (freq >= lowFreq && freq <= highFreq) {
                targetBandEnergy += energy;
            }
        }

        double otherEnergy = totalEnergy - targetBandEnergy;
        double ratio = otherEnergy > 0 ? targetBandEnergy / otherEnergy : targetBandEnergy;
        return new EnergyBands(totalEnergy, targetBandEnergy, otherEnergy, ratio);
    }
}
