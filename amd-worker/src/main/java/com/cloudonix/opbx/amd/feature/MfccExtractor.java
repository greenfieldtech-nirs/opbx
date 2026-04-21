package com.cloudonix.opbx.amd.feature;

import org.jtransforms.fft.DoubleFFT_1D;

import java.util.ArrayList;
import java.util.List;

public class MfccExtractor {

    public static double[] computeMfcc(double[] pcmData, int sampleRate, int numCoeffs, int fftSize, int hopLength) {
        List<double[]> frames = new ArrayList<>();
        for (int i = 0; i + fftSize <= pcmData.length; i += hopLength) {
            double[] frame = new double[fftSize];
            System.arraycopy(pcmData, i, frame, 0, fftSize);
            for (int j = 0; j < fftSize; j++) {
                frame[j] *= 0.5 * (1 - Math.cos((2 * Math.PI * j) / (fftSize - 1)));
            }
            frames.add(frame);
        }

        if (frames.isEmpty()) {
            return new double[numCoeffs];
        }

        double[][] filters = melFilterbank(numCoeffs, fftSize, sampleRate);
        List<double[]> mfccFrames = new ArrayList<>();

        for (double[] frame : frames) {
            double[] spectrum = computePowerSpectrum(frame);
            double[] melEnergies = new double[numCoeffs];
            for (int f = 0; f < numCoeffs; f++) {
                double energy = 0;
                for (int i = 0; i < spectrum.length; i++) {
                    energy += spectrum[i] * filters[f][i];
                }
                melEnergies[f] = Math.log(energy + 1e-10);
            }
            mfccFrames.add(dct(melEnergies, numCoeffs));
        }

        double[] averaged = new double[numCoeffs];
        for (int c = 0; c < numCoeffs; c++) {
            double sum = 0;
            for (double[] frame : mfccFrames) {
                sum += frame[c];
            }
            averaged[c] = sum / mfccFrames.size();
        }
        return averaged;
    }

    private static double[][] melFilterbank(int numFilters, int fftSize, int sampleRate) {
        double fMin = 0;
        double fMax = sampleRate / 2.0;
        double melMin = 2595 * Math.log10(1 + fMin / 700);
        double melMax = 2595 * Math.log10(1 + fMax / 700);

        double[] melPoints = new double[numFilters + 2];
        for (int i = 0; i < numFilters + 2; i++) {
            melPoints[i] = melMin + (i * (melMax - melMin)) / (numFilters + 1);
        }

        double[] hzPoints = new double[numFilters + 2];
        int[] binPoints = new int[numFilters + 2];
        for (int i = 0; i < numFilters + 2; i++) {
            hzPoints[i] = 700 * (Math.pow(10, melPoints[i] / 2595) - 1);
            binPoints[i] = (int) Math.floor(((fftSize + 1) * hzPoints[i]) / sampleRate);
        }

        int filterLength = fftSize / 2 + 1;
        double[][] filterbank = new double[numFilters][filterLength];
        for (int i = 1; i <= numFilters; i++) {
            for (int j = binPoints[i - 1]; j < binPoints[i]; j++) {
                if (j >= 0 && j < filterLength) {
                    filterbank[i - 1][j] = (double) (j - binPoints[i - 1]) / (binPoints[i] - binPoints[i - 1]);
                }
            }
            for (int j = binPoints[i]; j < binPoints[i + 1]; j++) {
                if (j >= 0 && j < filterLength) {
                    filterbank[i - 1][j] = (double) (binPoints[i + 1] - j) / (binPoints[i + 1] - binPoints[i]);
                }
            }
        }
        return filterbank;
    }

    private static double[] computePowerSpectrum(double[] frame) {
        int n = frame.length;
        double[] copy = new double[n];
        System.arraycopy(frame, 0, copy, 0, n);
        DoubleFFT_1D fft = new DoubleFFT_1D(n);
        fft.realForward(copy);
        int numBins = n / 2 + 1;
        double[] power = new double[numBins];
        for (int i = 0; i < numBins; i++) {
            double real = (i == 0 || i == n / 2) ? copy[i] : copy[2 * i];
            double imag = (i == 0 || i == n / 2) ? 0 : copy[2 * i + 1];
            double magnitude = Math.sqrt(real * real + imag * imag);
            power[i] = magnitude * magnitude;
        }
        return power;
    }

    private static double[] dct(double[] input, int numCoeffs) {
        double[] result = new double[numCoeffs];
        int N = input.length;
        for (int k = 0; k < numCoeffs; k++) {
            double sum = 0;
            for (int n = 0; n < N; n++) {
                sum += input[n] * Math.cos((Math.PI * k * (2 * n + 1)) / (2 * N));
            }
            result[k] = sum * Math.sqrt(2.0 / N);
        }
        return result;
    }
}
