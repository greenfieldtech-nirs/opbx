package com.cloudonix.opbx.amd.stream;

import com.fasterxml.jackson.annotation.JsonIgnoreProperties;
import com.fasterxml.jackson.annotation.JsonProperty;
import com.fasterxml.jackson.databind.ObjectMapper;

import java.util.List;
import java.util.Map;

/**
 * DTO for Cloudonix WebSocket Stream protocol messages.
 *
 * Protocol reference:
 * https://developers.cloudonix.com/Documentation/voiceApplication/Verb/start/stream
 *
 * Events: connected, start, media, dtmf, stop
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class StreamMessage {
    public String event;

    @JsonProperty("sequenceNumber")
    public String sequenceNumber;

    @JsonProperty("streamSid")
    public String streamSid;

    @JsonProperty("protocol")
    public String protocol;

    @JsonProperty("version")
    public String version;

    @JsonProperty("start")
    public Start start;

    @JsonProperty("media")
    public Media media;

    @JsonProperty("dtmf")
    public Dtmf dtmf;

    @JsonProperty("stop")
    public Stop stop;

    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class Start {
        @JsonProperty("streamSid")
        public String streamSid;
        @JsonProperty("session")
        public String session;
        @JsonProperty("callSid")
        public String callSid;
        @JsonProperty("tracks")
        public List<String> tracks;
        @JsonProperty("customParameters")
        public Map<String, Object> customParameters;
        @JsonProperty("mediaFormat")
        public MediaFormat mediaFormat;
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class MediaFormat {
        public String encoding;
        public int sampleRate;
        public int channels;
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class Media {
        public String track;
        public int chunk;
        public String timestamp;
        public String payload;
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class Dtmf {
        public String track;
        public String digit;
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class Stop {
        @JsonProperty("session")
        public String session;
        @JsonProperty("callSid")
        public String callSid;
    }

    public static StreamMessage parse(String json, ObjectMapper mapper) {
        try {
            return mapper.readValue(json, StreamMessage.class);
        } catch (Exception e) {
            // Log truncated payload to avoid flooding logs with huge Base64 strings
            String preview = json.length() > 200 ? json.substring(0, 200) + "..." : json;
            System.err.println("Failed to parse stream message: " + e.getMessage() + " | payload=" + preview);
            return null;
        }
    }
}
