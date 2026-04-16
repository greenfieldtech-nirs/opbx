package com.cloudonix.opbx.amd.model;

import ai.onnxruntime.*;

import java.util.HashMap;
import java.util.Map;

public class OnnxModel {
    private final String modelPath;
    private OrtEnvironment env;
    private OrtSession session;

    public OnnxModel(String modelPath) {
        this.modelPath = modelPath;
    }

    public void load() throws OrtException {
        this.env = OrtEnvironment.getEnvironment();
        OrtSession.SessionOptions opts = new OrtSession.SessionOptions();
        this.session = env.createSession(modelPath, opts);
    }

    public boolean isLoaded() {
        return session != null;
    }

    public long predict(float[] mfccFeatures) throws OrtException {
        if (session == null) {
            throw new IllegalStateException("ONNX model not loaded");
        }
        float[][] inputData = new float[1][mfccFeatures.length];
        inputData[0] = mfccFeatures;
        try (OnnxTensor inputTensor = OnnxTensor.createTensor(env, inputData)) {
            Map<String, OnnxTensor> inputs = new HashMap<>();
            inputs.put("mfcc_features", inputTensor);
            try (OrtSession.Result results = session.run(inputs)) {
                OnnxValue output = results.get(0);
                long[] data = (long[]) output.getValue();
                return data[0];
            }
        }
    }

    public void close() {
        if (session != null) {
            try {
                session.close();
            } catch (OrtException e) {
                throw new RuntimeException("Failed to close ONNX session", e);
            }
            session = null;
        }
        if (env != null) {
            env.close();
            env = null;
        }
    }
}
