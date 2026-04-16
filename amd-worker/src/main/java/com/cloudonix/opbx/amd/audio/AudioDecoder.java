package com.cloudonix.opbx.amd.audio;

import java.util.Base64;

public class AudioDecoder {
    private static final short[] MULAW_TO_PCM = new short[256];
    private static final byte[] PCM_TO_MULAW = new byte[65536];

    static {
        final int BIAS = 33;
        for (int i = 0; i < 256; i++) {
            int sign = (i & 0x80) != 0 ? -1 : 1;
            int exponent = (i & 0x70) >> 4;
            int mantissa = i & 0x0f;
            int value = mantissa << (exponent + 3);
            value += BIAS << exponent;
            if (sign < 0) {
                value = BIAS - value;
            } else {
                value = value - BIAS;
            }
            MULAW_TO_PCM[i] = (short) value;
        }

        for (int pcm = -32768; pcm <= 32767; pcm++) {
            int idx = pcm + 32768;
            int bestU = 0;
            int bestDist = Integer.MAX_VALUE;
            for (int u = 0; u < 256; u++) {
                int dist = Math.abs(pcm - MULAW_TO_PCM[u]);
                if (dist < bestDist) {
                    bestDist = dist;
                    bestU = u;
                }
            }
            PCM_TO_MULAW[idx] = (byte) bestU;
        }
    }

    public static short[] decodeMulawToPcm16(byte[] mulawData) {
        short[] pcm = new short[mulawData.length];
        for (int i = 0; i < mulawData.length; i++) {
            pcm[i] = MULAW_TO_PCM[mulawData[i] & 0xFF];
        }
        return pcm;
    }

    public static double[] pcm16ToDouble(short[] pcm16) {
        double[] out = new double[pcm16.length];
        for (int i = 0; i < pcm16.length; i++) {
            out[i] = pcm16[i] / 32768.0;
        }
        return out;
    }

    public static byte[] pcm16ToMulaw(short[] pcm16) {
        byte[] ulaw = new byte[pcm16.length];
        for (int i = 0; i < pcm16.length; i++) {
            ulaw[i] = PCM_TO_MULAW[pcm16[i] + 32768];
        }
        return ulaw;
    }

    public static byte[] decodeBase64(String base64) {
        return Base64.getDecoder().decode(base64);
    }
}
