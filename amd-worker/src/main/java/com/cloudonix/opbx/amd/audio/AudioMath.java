package com.cloudonix.opbx.amd.audio;

/**
 * Shared audio math utilities used across the AMD worker.
 */
public final class AudioMath {
    private AudioMath() {
        // Utility class — prevent instantiation
    }

    /**
     * Computes the Root Mean Square (RMS) of the given samples.
     *
     * @param samples audio samples in range [-1.0, 1.0]
     * @return RMS value, or 0.0 for empty input
     */
    public static double rms(double[] samples) {
        if (samples == null || samples.length == 0) {
            return 0.0;
        }
        double sum = 0.0;
        for (double v : samples) {
            sum += v * v;
        }
        return Math.sqrt(sum / samples.length);
    }

    /**
     * Computes average energy (mean squared amplitude) of the given samples.
     *
     * @param samples audio samples in range [-1.0, 1.0]
     * @return average energy, or 0.0 for empty input
     */
    public static double energy(double[] samples) {
        if (samples == null || samples.length == 0) {
            return 0.0;
        }
        double sum = 0.0;
        for (double v : samples) {
            sum += v * v;
        }
        return sum / samples.length;
    }
}
