#!/usr/bin/env python3
"""Generate a minimal GaussianNB ONNX model for beep detection.

This script creates a synthetic dataset and trains a Gaussian Naive Bayes
classifier, then exports it to ONNX format. In production, replace the
generated model with one converted from the reference scikit-learn model.
"""

import numpy as np

try:
    from sklearn.naive_bayes import GaussianNB
    from skl2onnx import convert_sklearn
    from skl2onnx.common.data_types import FloatTensorType
except ImportError as e:
    print(f"Missing dependency: {e}")
    print("Install with: pip install scikit-learn skl2onnx numpy")
    raise

# Seed for reproducibility
np.random.seed(42)

# Generate synthetic MFCC features (40 coefficients)
# Class 0 = beep (narrow spectral content, low variance across coeffs)
# Class 1 = speech (broader spectral content, higher variance)
n_samples = 2000
n_features = 40

# Beep samples: concentrated energy in mid frequencies, low overall variance
beep_mean = np.zeros(n_features)
beep_mean[10:25] = 0.8
beep_samples = np.random.normal(beep_mean, 0.1, size=(n_samples // 2, n_features))

# Speech samples: more varied, wider spectral spread
speech_mean = np.zeros(n_features)
speech_mean[5:35] = 0.4
speech_samples = np.random.normal(speech_mean, 0.3, size=(n_samples // 2, n_features))

X = np.vstack([beep_samples, speech_samples])
y = np.array([0] * (n_samples // 2) + [1] * (n_samples // 2))

# Train GaussianNB
model = GaussianNB()
model.fit(X, y)

# Export to ONNX
initial_type = [("mfcc_features", FloatTensorType([None, n_features]))]
onnx_model = convert_sklearn(model, initial_types=initial_type)

output_path = "amd-worker/models/beep_detector.onnx"
with open(output_path, "wb") as f:
    f.write(onnx_model.SerializeToString())

print(f"Model saved to {output_path}")
print(f"Input shape: (batch_size, {n_features})")
print(f"Classes: 0=beep (voicemail), 1=speech (human)")

# Quick validation
predictions = model.predict(X[:5])
print(f"Sample predictions: {predictions}")
