package com.cloudonix.opbx.acd;

/**
 * Central configuration for the ACD (call queue) worker.
 *
 * All values are read from environment variables with sensible defaults.
 * The worker holds ephemeral queue state only; MySQL is owned by Laravel.
 */
public class Config {
    public final int httpPort;
    public final int healthPort;
    public final String apiToken;
    public final String redisHost;
    public final int redisPort;
    public final String redisPassword;
    public final String logLevel;

    public Config() {
        this.httpPort = Integer.parseInt(getEnv("ACD_HTTP_PORT", "8084"));
        this.healthPort = Integer.parseInt(getEnv("ACD_HEALTH_PORT", "8085"));
        this.apiToken = getEnv("ACD_WORKER_API_TOKEN", "");
        this.redisHost = getEnv("ACD_REDIS_HOST", "redis");
        this.redisPort = Integer.parseInt(getEnv("ACD_REDIS_PORT", "6379"));
        this.redisPassword = getEnv("ACD_REDIS_PASSWORD", "");
        this.logLevel = getEnv("ACD_LOG_LEVEL", "info");
    }

    private static String getEnv(String key, String defaultValue) {
        String value = System.getenv(key);
        return value != null ? value : defaultValue;
    }

    public String redisUri() {
        String auth = redisPassword.isEmpty() ? "" : ":" + redisPassword + "@";
        return "redis://" + auth + redisHost + ":" + redisPort;
    }
}
