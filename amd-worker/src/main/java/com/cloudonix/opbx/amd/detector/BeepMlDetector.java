package com.cloudonix.opbx.amd.detector;

import com.cloudonix.opbx.amd.feature.MfccExtractor;
import com.cloudonix.opbx.amd.model.OnnxModel;

public class BeepMlDetector implements Detector {
    private final OnnxModel model;

    public BeepMlDetector(OnnxModel model) {
        this.model = model;
    }

    @Override
    public String name() {
        return "beep_ml";
    }

    public DetectionResult processAsync(AudioSegment segment) throws Exception {
        if (!model.isLoaded()) {
            return null;
        }
        double[] mfcc = MfccExtractor.computeMfcc(segment.pcmData, segment.sampleRate, 40, 2048, 512);
        float[] mfccFloat = new float[mfcc.length];
        for (int i = 0; i < mfcc.length; i++) {
            mfccFloat[i] = (float) mfcc[i];
        }
        long prediction = model.predict(mfccFloat);
        if (prediction == 0) {
            return new DetectionResult(
                name(),
                ResultType.VOICEMAIL,
                0.95,
                "Beep tone detected by ML model (prediction=" + prediction + ")",
                (long) (segment.startTimestampMs + segment.durationMs)
            );
        }
        return null;
    }

    @Override
    public DetectionResult process(AudioSegment segment) {
        throw new UnsupportedOperationException("Use processAsync");
    }

    @Override
    public void reset() {
    }
}
