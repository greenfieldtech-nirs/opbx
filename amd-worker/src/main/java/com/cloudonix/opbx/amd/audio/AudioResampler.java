package com.cloudonix.opbx.amd.audio;

public class AudioResampler {
    public static double[] resampleLinear(double[] input, int inputRate, int outputRate) {
        if (inputRate == outputRate) {
            return input.clone();
        }
        double ratio = (double) inputRate / outputRate;
        int outputLength = (int) Math.floor(input.length / ratio);
        double[] output = new double[outputLength];

        for (int i = 0; i < outputLength; i++) {
            double pos = i * ratio;
            int index = (int) Math.floor(pos);
            double frac = pos - index;
            double s0 = input[index];
            double s1 = (index + 1 < input.length) ? input[index + 1] : s0;
            output[i] = s0 + frac * (s1 - s0);
        }
        return output;
    }
}
