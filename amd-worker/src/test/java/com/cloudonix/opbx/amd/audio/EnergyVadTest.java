package com.cloudonix.opbx.amd.audio;

import static org.junit.jupiter.api.Assertions.assertEquals;
import static org.junit.jupiter.api.Assertions.assertNotNull;
import static org.junit.jupiter.api.Assertions.assertNull;
import static org.junit.jupiter.api.Assertions.assertTrue;

import java.util.ArrayList;
import java.util.List;

import org.junit.jupiter.api.Test;

import com.cloudonix.opbx.amd.detector.DetectionResult;
import com.cloudonix.opbx.amd.detector.Detector;
import com.cloudonix.opbx.amd.detector.ResultType;
import com.cloudonix.opbx.amd.detector.ToneEnergyDetector;

class EnergyVadTest {
    private static final int RATE = 16000;

    @Test
    void aShortToneFollowedBySilenceEmitsOneSegment() {
        List<EnergyVad.VadSegment> segments = runVad(concat(tone(500), silence(500)));

        assertEquals(1, segments.size());
        EnergyVad.VadSegment segment = segments.get(0);
        assertTrue(segment.pcmData().length >= RATE * 400 / 1000, "segment keeps the tone audio");
    }

    @Test
    void aBeepAtFourSecondsIsDetectedThroughTheVad() {
        List<EnergyVad.VadSegment> segments = runVad(concat(silence(4000), tone(600), silence(500)));
        assertEquals(1, segments.size());

        DetectionResult result = detect(new ToneEnergyDetector(2000), segments.get(0));

        assertNotNull(result);
        assertEquals(ResultType.VOICEMAIL, result.result);
    }

    @Test
    void aToneBeforeTheMinimumBeepStartIsIgnored() {
        List<EnergyVad.VadSegment> segments = runVad(concat(silence(500), tone(600), silence(500)));
        assertEquals(1, segments.size());

        assertNull(detect(new ToneEnergyDetector(2000), segments.get(0)));
    }

    @Test
    void aToneAlreadyRunningAtAnswerIsNotABeep() {
        // In-band tone from answer to 3.6 s, then a human (seen on real dumps): the part of the
        // tone after minBeepStartMs must not count as a beep, whether from VAD clips or the rolling buffer.
        double[] audio = concat(tone(3600), silence(1000));
        ToneEnergyDetector detector = new ToneEnergyDetector(2000);

        for (EnergyVad.VadSegment s : runVad(audio)) {
            assertNull(detect(detector, s), "VAD segment starting at " + s.startMs());
        }
        double[] rolling = java.util.Arrays.copyOfRange(audio, RATE * 2000 / 1000, RATE * 3500 / 1000);
        assertNull(detector.process(new Detector.AudioSegment(rolling, RATE, 1500, 2000)));
    }

    @Test
    void aBeepInsideTheRollingBufferIsDetected() {
        double[] rolling = concat(silence(700), tone(800)); // window 2.0-3.5 s, beep onset at 2.7 s

        assertNotNull(new ToneEnergyDetector(2000).process(new Detector.AudioSegment(rolling, RATE, 1500, 2000)));
    }

    /** Feeds audio in 20 ms chunks, as the stream does. */
    private static List<EnergyVad.VadSegment> runVad(double[] pcm) {
        EnergyVad vad = new EnergyVad(RATE, 10, 200, 3000, 3, 20);
        List<EnergyVad.VadSegment> out = new ArrayList<>();
        int chunk = RATE / 50;
        for (int i = 0; i + chunk <= pcm.length; i += chunk) {
            double[] part = new double[chunk];
            System.arraycopy(pcm, i, part, 0, chunk);
            out.addAll(vad.process(part, i * 1000.0 / RATE));
        }
        return out;
    }

    private static DetectionResult detect(ToneEnergyDetector detector, EnergyVad.VadSegment s) {
        return detector.process(new Detector.AudioSegment(s.pcmData(), RATE, s.endMs() - s.startMs(), s.startMs()));
    }

    private static double[] tone(int ms) {
        double[] pcm = new double[RATE * ms / 1000];
        for (int i = 0; i < pcm.length; i++) {
            pcm[i] = 0.25 * Math.sin(2 * Math.PI * 850 * i / RATE);
        }
        return pcm;
    }

    private static double[] silence(int ms) {
        return new double[RATE * ms / 1000];
    }

    private static double[] concat(double[]... parts) {
        int n = 0;
        for (double[] p : parts) {
            n += p.length;
        }
        double[] out = new double[n];
        int pos = 0;
        for (double[] p : parts) {
            System.arraycopy(p, 0, out, pos, p.length);
            pos += p.length;
        }
        return out;
    }
}
