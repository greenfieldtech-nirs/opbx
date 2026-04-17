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
 * <p>The stream protocol sends five event types over the WebSocket:
 * <ul>
 *   <li>{@code connected} — sent when the WebSocket first connects</li>
 *   <li>{@code start} — sent immediately after {@code connected}, carries stream metadata</li>
 *   <li>{@code media} — sent every 20ms with audio payload</li>
 *   <li>{@code dtmf} — sent when a touch-tone key is pressed</li>
 *   <li>{@code stop} — sent when the stream ends</li>
 * </ul>
 */
@JsonIgnoreProperties(ignoreUnknown = true)
public class StreamMessage {
    /** Event type: {@code connected}, {@code start}, {@code media}, {@code dtmf}, or {@code stop}. */
    public String event;

    /** Sequence number for this message in the protocol. First message after {@code connected} is "1". */
    @JsonProperty("sequenceNumber")
    public String sequenceNumber;

    /** Unique stream identifier for this stream. */
    @JsonProperty("streamSid")
    public String streamSid;

    /** Protocol name — always "Call" for voice applications. */
    @JsonProperty("protocol")
    public String protocol;

    /** Semantic version of the stream protocol. */
    @JsonProperty("version")
    public String version;

    /** Present on {@code start} events. Contains stream metadata. */
    @JsonProperty("start")
    public Start start;

    /** Present on {@code media} events. Contains audio metadata and Base64 payload. */
    @JsonProperty("media")
    public Media media;

    /** Present on {@code dtmf} events. Contains DTMF digit information. */
    @JsonProperty("dtmf")
    public Dtmf dtmf;

    /** Present on {@code stop} events. Contains stream termination metadata. */
    @JsonProperty("stop")
    public Stop stop;

    /**
     * Start message metadata object.
     *
     * <p>Sent as part of the {@code start} event immediately after the {@code connected} message.
     * Contains metadata about the stream that has started.
     *
     * <p>Cloudonix documentation:
     * <pre>
     * Parameter        Description
     * ──────────────────────────────────────────────────────────────────────────
     * streamSid        The unique stream identifier for this stream.
     * session          The session token for the connected call.
     * callSid          The unique call ID associated with the connected call.
     * tracks           Array of tracks: "inbound", "outbound", or both.
     * customParameters Object containing all custom parameters for the stream.
     * mediaFormat      Object specifying the format of media payloads. See {@link MediaFormat}.
     * </pre>
     */
    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class Start {
        @JsonProperty("streamSid")
        public String streamSid;

        /** Session token for the connected call. */
        @JsonProperty("session")
        public String session;

        /** Unique call ID associated with the connected call. */
        @JsonProperty("callSid")
        public String callSid;

        /**
         * List of tracks that were requested.
         * Values: {@code "inbound"}, {@code "outbound"}, or both.
         */
        @JsonProperty("tracks")
        public List<String> tracks;

        /** All custom parameters passed to the stream. */
        @JsonProperty("customParameters")
        public Map<String, Object> customParameters;

        /** Audio format specification for media payloads on this connection. */
        @JsonProperty("mediaFormat")
        public MediaFormat mediaFormat;
    }

    /**
     * Audio format specification.
     *
     * <p>Cloudonix documentation:
     * <pre>
     * Parameter   Description
     * ──────────────────────────────────────────────────────────────────────────
     * encoding    Audio encoding of media payloads. Supported: audio/x-mulaw
     * sampleRate  Sample rate in Hz. Supported: 8000
     * channels    Number of channels. Supported: 1
     * </pre>
     */
    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class MediaFormat {
        /** Audio encoding — always {@code audio/x-mulaw} for Cloudonix streams. */
        public String encoding;

        /** Sample rate in Hz — always {@code 8000} for Cloudonix streams. */
        public int sampleRate;

        /** Number of audio channels — always {@code 1} (mono) for Cloudonix streams. */
        public int channels;
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class Media {
        /** Track this media is for: {@code "inbound"} or {@code "outbound"}. */
        public String track;

        /** Chunk sequence number for this track. Starts at 1. */
        public int chunk;

        /** Presentation timestamp in milliseconds from the start of the stream. */
        public String timestamp;

        /** Audio payload as raw audio data encoded in Base64. */
        public String payload;
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class Dtmf {
        /** Track this DTMF event is for: {@code "inbound"} or {@code "outbound"}. */
        public String track;

        /** Key pressed: {@code 0-9}, {@code #}, or {@code *}. */
        public String digit;
    }

    @JsonIgnoreProperties(ignoreUnknown = true)
    public static class Stop {
        /** Session token for the connected call. */
        @JsonProperty("session")
        public String session;

        /** Unique call ID associated with the connected call. */
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
