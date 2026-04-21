package com.cloudonix.opbx.amd;

import com.cloudonix.opbx.amd.audio.EnergyVad;
import com.cloudonix.opbx.amd.detector.Detector;
import com.cloudonix.opbx.amd.detector.ToneEnergyDetector;

public class DebugToneDetector {
    public static void main(String[] args) {
        // Generate 1000Hz sine wave at 16kHz for 1 second
        int sampleRate = 16000;
        int durationMs = 1000;
        int samples = (int) ((durationMs / 1000.0) * sampleRate);
        double[] pcm = new double[samples];
        double amplitude = 8000.0 / 32768.0; // normalize to [-1, 1]
        for (int i = 0; i < samples; i++) {
            pcm[i] = amplitude * Math.sin(2 * Math.PI * 1000 * i / sampleRate);
        }

        EnergyVad vad = new EnergyVad(16000, 10, 200, 3000, 3, 20);
        var segments = vad.process(pcm, 0);

        System.out.println("VAD segments produced: " + segments.size());
        for (var seg : segments) {
            System.out.println("Segment: start=" + seg.startMs() + "ms, end=" + seg.endMs() + "ms, duration=" + (seg.endMs() - seg.startMs()) + "ms");
            ToneEnergyDetector tone = new ToneEnergyDetector();
            var result = tone.process(new Detector.AudioSegment(seg.pcmData(), 16000, seg.endMs() - seg.startMs(), seg.startMs()));
            System.out.println("Tone detector result: " + result);
        }
    }
}
