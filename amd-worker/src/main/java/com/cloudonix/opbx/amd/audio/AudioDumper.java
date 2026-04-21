package com.cloudonix.opbx.amd.audio;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.io.FileOutputStream;
import java.nio.file.Files;
import java.nio.file.Paths;
import java.util.ArrayList;
import java.util.List;

public class AudioDumper {
    private static final Logger logger = LoggerFactory.getLogger(AudioDumper.class);

    private final boolean enabled;
    private final String dumpDir;
    private final String callSid;
    private final String streamSid;
    private final List<double[]> chunks = new ArrayList<>();

    public AudioDumper(boolean enabled, String dumpDir, String callSid, String streamSid) {
        this.enabled = enabled;
        this.dumpDir = dumpDir;
        this.callSid = callSid;
        this.streamSid = streamSid;
    }

    public boolean isEnabled() {
        return enabled;
    }

    public void append(double[] float32Pcm) {
        if (!enabled) {
            return;
        }
        chunks.add(float32Pcm.clone());
    }

    public void finalizeDump() {
        if (!enabled || chunks.isEmpty()) {
            return;
        }
        try {
            int totalSamples = 0;
            for (double[] chunk : chunks) {
                totalSamples += chunk.length;
            }

            byte[] data = new byte[totalSamples * 2];
            int idx = 0;
            for (double[] chunk : chunks) {
                for (double sample : chunk) {
                    double clamped = Math.max(-1.0, Math.min(1.0, sample));
                    short s = (short) (clamped * 32767.0);
                    data[idx++] = (byte) (s & 0xFF);
                    data[idx++] = (byte) ((s >> 8) & 0xFF);
                }
            }

            String safeCallSid = sanitizeFilename(callSid);
            String safeStreamSid = sanitizeFilename(streamSid);
            String filename = dumpDir + "/" + safeCallSid + "_" + safeStreamSid + ".wav";
            java.nio.file.Path filePath = Paths.get(filename).toAbsolutePath().normalize();
            java.nio.file.Path dirPath = Paths.get(dumpDir).toAbsolutePath().normalize();
            if (!filePath.startsWith(dirPath)) {
                logger.warn("Blocked path traversal attempt call_sid={} stream_sid={}", callSid, streamSid);
                return;
            }
            Files.createDirectories(dirPath);
            try (FileOutputStream fos = new FileOutputStream(filePath.toString())) {
                writeWavHeader(fos, data.length, 16000, (short) 1, (short) 16);
                fos.write(data);
            }
            logger.info("Audio dump written to {} (samples={} duration_ms={})",
                filename, totalSamples, (totalSamples * 1000L) / 16000);
        } catch (Exception e) {
            logger.warn("Failed to write audio dump call_sid={} stream_sid={}", callSid, streamSid, e);
        }
    }

    private void writeWavHeader(FileOutputStream fos, int dataLength, int sampleRate,
                                short numChannels, short bitsPerSample) throws Exception {
        int byteRate = sampleRate * numChannels * (bitsPerSample / 8);
        short blockAlign = (short) (numChannels * (bitsPerSample / 8));
        int totalDataLen = dataLength + 36;

        fos.write(new byte[]{'R', 'I', 'F', 'F'});
        fos.write(intToBytes(totalDataLen));
        fos.write(new byte[]{'W', 'A', 'V', 'E'});
        fos.write(new byte[]{'f', 'm', 't', ' '});
        fos.write(intToBytes(16)); // Subchunk1Size
        fos.write(shortToBytes((short) 1)); // AudioFormat PCM
        fos.write(shortToBytes(numChannels));
        fos.write(intToBytes(sampleRate));
        fos.write(intToBytes(byteRate));
        fos.write(shortToBytes(blockAlign));
        fos.write(shortToBytes(bitsPerSample));
        fos.write(new byte[]{'d', 'a', 't', 'a'});
        fos.write(intToBytes(dataLength));
    }

    private byte[] intToBytes(int value) {
        return new byte[]{
            (byte) (value & 0xFF),
            (byte) ((value >> 8) & 0xFF),
            (byte) ((value >> 16) & 0xFF),
            (byte) ((value >> 24) & 0xFF)
        };
    }

    private byte[] shortToBytes(short value) {
        return new byte[]{
            (byte) (value & 0xFF),
            (byte) ((value >> 8) & 0xFF)
        };
    }

    /**
     * Sanitizes a filename by removing path traversal characters and
     * replacing filesystem-special characters with underscores.
     */
    private static String sanitizeFilename(String input) {
        if (input == null || input.isEmpty()) {
            return "unknown";
        }
        return input.replaceAll("[\\\\/:*?\"<>|]", "_");
    }
}
