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
    public final List<String> detectors;
    public final boolean dumpAudio;
    public final String dumpAudioPath;
    public final String apiToken;
    public final String actionCallbackUrl;
    public final String sessionCheckUrl;
    /** Tones starting earlier than this are not taken as a voicemail beep (greeting audio). */
    public final int minBeepStartMs;

    public Config() {
        this.websocketPort = Integer.parseInt(getEnv("AMD_WEBSOCKET_PORT", "8082"));
        this.httpPort = Integer.parseInt(getEnv("AMD_HTTP_PORT", "8083"));
        this.modelPath = getEnv("AMD_MODEL_PATH", "./models/beep_detector.onnx");
        this.maxConcurrentStreams = Integer.parseInt(getEnv("AMD_MAX_CONCURRENT_STREAMS", "100"));
        this.defaultTimeoutSeconds = Integer.parseInt(getEnv("AMD_DEFAULT_TIMEOUT_SECONDS", "30"));
        String detectorsEnv = getEnv("AMD_DETECTORS", "beep_ml,tone_energy");
        this.detectors = Arrays.asList(detectorsEnv.split(","));
        this.dumpAudio = Boolean.parseBoolean(getEnv("AMD_DUMP_AUDIO", "false"));
        this.dumpAudioPath = getEnv("AMD_DUMP_AUDIO_PATH", "/tmp/amd-dumps");
        this.apiToken = requireToken("AMD_WORKER_API_TOKEN", getEnv("AMD_WORKER_API_TOKEN", ""));
        this.actionCallbackUrl = getEnv("AMD_ACTION_CALLBACK_URL", "http://nginx/api/voice/amd-action");
        this.sessionCheckUrl = deriveSessionCheckUrl(actionCallbackUrl, System.getenv("AMD_SESSION_CHECK_URL"));
        this.minBeepStartMs = Integer.parseInt(getEnv("AMD_MIN_BEEP_START_MS", "2000"));
    }

    /**
     * Session-check endpoint: AMD_SESSION_CHECK_URL if set, else the action callback URL
     * with its trailing /amd-action segment replaced by /amd-session. Returns "" when it
     * cannot be derived, which makes every stream fail verification (fail closed).
     */
    public static String deriveSessionCheckUrl(String actionCallbackUrl, String override) {
        if (override != null && !override.isBlank()) {
            return override;
        }
        if (actionCallbackUrl == null || !actionCallbackUrl.endsWith("/amd-action")) {
            return "";
        }
        return actionCallbackUrl.substring(0, actionCallbackUrl.length() - "/amd-action".length()) + "/amd-session";
    }

    /** Refuse to start with an empty or shipped-default token. */
    static String requireToken(String name, String token) {
        if (token == null || token.isBlank() || token.contains("change-in-production")) {
            throw new IllegalStateException(name + " is empty or a shipped default; set a real secret");
        }
        return token;
    }

    private static String getEnv(String key, String defaultValue) {
        String value = System.getenv(key);
        return value != null ? value : defaultValue;
    }
}
