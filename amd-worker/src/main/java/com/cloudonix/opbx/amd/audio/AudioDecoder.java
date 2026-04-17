package com.cloudonix.opbx.amd.audio;

import java.util.Base64;

public class AudioDecoder {
    private static final short[] MULAW_TO_PCM = new short[256];
    private static final int MAX_BASE64_PAYLOAD_BYTES = 1024 * 1024; // 1 MiB

    static {
        for (int i = 0; i < 256; i++) {
            int u = ~i & 0xFF;
            int sign = (u & 0x80) >> 7;
            int exponent = (u & 0x70) >> 4;
            int mantissa = u & 0x0F;
            int value = ((mantissa << 1) + 33) << exponent;
            value -= 33;
            if (sign == 1) {
                value = -value;
            }
            MULAW_TO_PCM[i] = (short) value;
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

    public static byte[] decodeBase64(String base64) {
        if (base64 == null) {
            return new byte[0];
        }
        // Reject payloads that would decode to more than MAX_BASE64_PAYLOAD_BYTES
        int estimatedDecodedLen = (base64.length() * 3) / 4;
        if (estimatedDecodedLen > MAX_BASE64_PAYLOAD_BYTES) {
            throw new IllegalArgumentException(
                "Base64 payload too large: estimated " + estimatedDecodedLen + " bytes (max " + MAX_BASE64_PAYLOAD_BYTES + ")"
            );
        }
        return Base64.getDecoder().decode(base64);
    }
}
