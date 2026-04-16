package com.cloudonix.opbx.amd.audio;

import org.slf4j.Logger;
import org.slf4j.LoggerFactory;

import java.util.ArrayList;
import java.util.List;

public class EnergyVad {
    private static final Logger logger = LoggerFactory.getLogger(EnergyVad.class);
    public record VadSegment(double[] pcmData, double startMs, double endMs) {}

    private final int sampleRate;
    private final int silenceFrames;
    private final int clipMinMs;
    private final int clipMaxMs;
    private final int vadSensitivity;
    private final int frameMs;

    private double[] buffer = new double[0];
    private double bufferStartMs = 0;
    private int silenceCounter = 0;
    private int speechCounter = 0;
    private boolean inSpeech = false;
    private double currentStreamMs = 0;
    private final List<VadSegment> segments = new ArrayList<>();

    // Track audio for the current ongoing speech segment
    private double[] currentSpeechBuffer = new double[0];
    private double currentSpeechStartMs = 0;

    public EnergyVad(int sampleRate, int silenceFrames, int clipMinMs, int clipMaxMs, int vadSensitivity, int frameMs) {
        this.sampleRate = sampleRate;
        this.silenceFrames = silenceFrames;
        this.clipMinMs = clipMinMs;
        this.clipMaxMs = clipMaxMs;
        this.vadSensitivity = vadSensitivity;
        this.frameMs = frameMs;
    }

    public void reset() {
        buffer = new double[0];
        bufferStartMs = 0;
        silenceCounter = 0;
        speechCounter = 0;
        inSpeech = false;
        currentStreamMs = 0;
        segments.clear();
        currentSpeechBuffer = new double[0];
        currentSpeechStartMs = 0;
    }

    public List<VadSegment> process(double[] pcmData, double startMs) {
        double[] newBuffer = new double[buffer.length + pcmData.length];
        System.arraycopy(buffer, 0, newBuffer, 0, buffer.length);
        System.arraycopy(pcmData, 0, newBuffer, buffer.length, pcmData.length);
        buffer = newBuffer;
        if (buffer.length == pcmData.length) {
            bufferStartMs = startMs;
        }

        int frameSamples = (int) Math.floor((frameMs / 1000.0) * sampleRate);
        List<VadSegment> newSegments = new ArrayList<>();

        while (buffer.length >= frameSamples) {
            double[] frame = new double[frameSamples];
            System.arraycopy(buffer, 0, frame, 0, frameSamples);
            boolean isSpeech = isSpeechFrame(frame);
            currentStreamMs = bufferStartMs + (frameSamples / (double) sampleRate) * 1000.0;

            if (isSpeech) {
                speechCounter++;
                silenceCounter = 0;
                if (!inSpeech && speechCounter >= 2) {
                    inSpeech = true;
                    currentSpeechStartMs = bufferStartMs;
                    currentSpeechBuffer = new double[0];
                    logger.info("VAD speech start at_ms={}", (int) currentSpeechStartMs);
                }
                if (inSpeech) {
                    // Append frame to current speech buffer
                    double[] expanded = new double[currentSpeechBuffer.length + frameSamples];
                    System.arraycopy(currentSpeechBuffer, 0, expanded, 0, currentSpeechBuffer.length);
                    System.arraycopy(frame, 0, expanded, currentSpeechBuffer.length, frameSamples);
                    currentSpeechBuffer = expanded;

                    // Emit segment if it exceeds clipMaxMs, then start a new one
                    double segmentDurationMs = currentStreamMs - currentSpeechStartMs;
                    if (segmentDurationMs >= clipMaxMs) {
                        logger.info("VAD segment clipped at max_duration_ms={} start_ms={}", clipMaxMs, (int) currentSpeechStartMs);
                        newSegments.add(new VadSegment(currentSpeechBuffer.clone(), currentSpeechStartMs, currentStreamMs));
                        currentSpeechBuffer = new double[0];
                        currentSpeechStartMs = currentStreamMs;
                    }
                }
            } else {
                silenceCounter++;
                if (inSpeech && silenceCounter >= silenceFrames) {
                    double segmentEndMs = currentStreamMs;
                    double durationMs = segmentEndMs - currentSpeechStartMs;
                    if (durationMs >= clipMinMs && durationMs <= clipMaxMs) {
                        logger.info("VAD speech end duration_ms={} start_ms={} end_ms={}", (int) durationMs, (int) currentSpeechStartMs, (int) segmentEndMs);
                        newSegments.add(new VadSegment(currentSpeechBuffer.clone(), currentSpeechStartMs, segmentEndMs));
                    } else {
                        logger.info("VAD speech discarded duration_ms={} (outside min/max)", (int) durationMs);
                    }

                    inSpeech = false;
                    speechCounter = 0;
                    silenceCounter = 0;
                    currentSpeechBuffer = new double[0];
                }
            }

            int shiftSamples = frameSamples;
            buffer = trimArray(buffer, shiftSamples);
            bufferStartMs += (shiftSamples / (double) sampleRate) * 1000.0;
        }

        segments.addAll(newSegments);
        return newSegments;
    }

    public List<VadSegment> flush() {
        if (inSpeech && currentSpeechBuffer.length > 0) {
            double segmentEndMs = currentStreamMs;
            double durationMs = segmentEndMs - currentSpeechStartMs;
            if (durationMs >= clipMinMs && durationMs <= clipMaxMs) {
                logger.info("VAD flush emitted trailing segment duration_ms={} start_ms={} end_ms={}", (int) durationMs, (int) currentSpeechStartMs, (int) segmentEndMs);
                segments.add(new VadSegment(currentSpeechBuffer.clone(), currentSpeechStartMs, segmentEndMs));
            } else {
                logger.info("VAD flush discarded trailing segment duration_ms={} (outside min/max)", (int) durationMs);
            }
        }
        List<VadSegment> result = new ArrayList<>(segments);
        if (result.isEmpty()) {
            logger.info("VAD flush produced no segments");
        } else {
            logger.info("VAD flush produced {} segment(s)", result.size());
        }
        reset();
        return result;
    }

    private boolean isSpeechFrame(double[] frame) {
        double energy = computeEnergy(frame);
        double baseThreshold = 0.001;
        double threshold = baseThreshold / vadSensitivity;
        return energy > threshold;
    }

    private double computeEnergy(double[] frame) {
        double sum = 0;
        for (double v : frame) {
            sum += v * v;
        }
        return sum / frame.length;
    }

    private double[] trimArray(double[] arr, int offset) {
        if (offset >= arr.length) {
            return new double[0];
        }
        double[] result = new double[arr.length - offset];
        System.arraycopy(arr, offset, result, 0, result.length);
        return result;
    }
}
