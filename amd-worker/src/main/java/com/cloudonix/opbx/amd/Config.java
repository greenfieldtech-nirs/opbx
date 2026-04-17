package com.cloudonix.opbx.amd;

import java.util.Arrays;
import java.util.List;

/**
 * Central configuration for the AMD worker.
 *
 * All values are read from environment variables with sensible defaults.
 * No secrets are hardcoded; the service fails safe if a required variable
 * is missing (e.g., WebSocket auth token is optional but recommended).
 */
public class Config {
    public final int websocketPort;
    public final int httpPort;
    public final String modelPath;
    public final int maxConcurrentStreams;
    public final int defaultTimeoutSeconds;
    public final String logLevel;
    public final List<String> detectors;
    public final boolean dumpAudio;
    public final String dumpAudioPath;
    public final String apiToken;

    public Config() {
        this.websocketPort = Integer.parseInt(getEnv("AMD_WEBSOCKET_PORT", "8082"));
        this.httpPort = Integer.parseInt(getEnv("AMD_HTTP_PORT", "8083"));
        this.modelPath = getEnv("AMD_MODEL_PATH", "./models/beep_detector.onnx");
        this.maxConcurrentStreams = Integer.parseInt(getEnv("AMD_MAX_CONCURRENT_STREAMS", "100"));
        this.defaultTimeoutSeconds = 45; // Hardcoded per requirements
        this.logLevel = getEnv("AMD_LOG_LEVEL", "info");
        String detectorsEnv = getEnv("AMD_DETECTORS", "beep_ml,tone_energy");
        this.detectors = Arrays.asList(detectorsEnv.split(","));
        this.dumpAudio = Boolean.parseBoolean(getEnv("AMD_DUMP_AUDIO", "false"));
        this.dumpAudioPath = getEnv("AMD_DUMP_AUDIO_PATH", "/tmp/amd-dumps");
        this.apiToken = getEnv("AMD_WORKER_API_TOKEN", "");
    }

    private static String getEnv(String key, String defaultValue) {
        String value = System.getenv(key);
        return value != null ? value : defaultValue;
    }
}
