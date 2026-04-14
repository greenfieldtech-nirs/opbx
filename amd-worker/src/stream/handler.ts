import WebSocket from 'ws';
import { parseMessage, StartMessage, MediaMessage, StopMessage } from './protocol';
import { StreamSession } from './session';
import { decodeMulawToPcm16, pcm16ToFloat32 } from '../audio/decoder';
import { resampleLinear } from '../audio/resampler';
import { DetectionResult, ResultType } from '../detector/types';
import { BeepMlDetector } from '../detector/beep-ml';
import * as metrics from '../metrics/metrics';

export interface StreamHandlerConfig {
    maxConcurrentStreams: number;
    defaultTimeoutMs: number;
    detectors: string[];
    beepMlDetector: BeepMlDetector | null;
}

export class StreamHandler {
    private activeStreams = new Map<string, StreamSession>();
    private config: StreamHandlerConfig;

    constructor(config: StreamHandlerConfig) {
        this.config = config;
    }

    handleConnection(ws: WebSocket): void {
        if (this.activeStreams.size >= this.config.maxConcurrentStreams) {
            ws.close(1013, 'Max concurrent streams reached');
            metrics.incrementErrors();
            return;
        }

        let session: StreamSession | null = null;
        let streamSid: string | null = null;

        ws.on('message', async (data: WebSocket.RawData) => {
            const messageStr = data.toString();
            const message = parseMessage(messageStr);

            if (!message) {
                console.error({ level: 'error', msg: 'Failed to parse message', raw: messageStr.substring(0, 200) });
                return;
            }

            switch (message.event) {
                case 'connected': {
                    console.log({ level: 'info', msg: 'Stream connected', protocol: message.protocol, version: message.version });
                    break;
                }

                case 'start': {
                    const startMsg = message as StartMessage;
                    streamSid = startMsg.streamSid;
                    const callSid = startMsg.start.callSid;

                    session = new StreamSession({
                        callSid,
                        streamSid: streamSid!,
                        timeoutMs: this.config.defaultTimeoutMs,
                        detectors: this.config.detectors,
                    }, this.config.beepMlDetector);

                    session.onResultLogged = (result: DetectionResult) => {
                        this.logResultAndClose(ws, session!, result);
                    };

                    session.onTimeout = () => {
                        this.handleTimeout(ws, session!);
                    };

                    session.startTimeoutTimer();
                    this.activeStreams.set(streamSid, session);
                    metrics.incrementActiveStreams();

                    console.log({
                        level: 'info',
                        msg: 'Stream started',
                        call_sid: callSid,
                        stream_sid: streamSid,
                        timeout_ms: this.config.defaultTimeoutMs,
                        detectors: this.config.detectors,
                    });
                    break;
                }

                case 'media': {
                    if (!session || !streamSid) {
                        return;
                    }
                    const mediaMsg = message as MediaMessage;
                    await this.processMedia(session, mediaMsg);
                    break;
                }

                case 'stop': {
                    const stopMsg = message as StopMessage;
                    console.log({
                        level: 'info',
                        msg: 'Stream stopped by Cloudonix',
                        call_sid: stopMsg.stop.callSid,
                        stream_sid: stopMsg.streamSid,
                    });
                    this.cleanupSession(streamSid);
                    ws.close();
                    break;
                }

                case 'dtmf': {
                    // Ignored for AMD
                    break;
                }
            }
        });

        ws.on('close', () => {
            this.cleanupSession(streamSid);
        });

        ws.on('error', (err) => {
            console.error({
                level: 'error',
                msg: 'WebSocket error',
                stream_sid: streamSid,
                error: err.message,
            });
            metrics.incrementErrors();
            this.cleanupSession(streamSid);
        });
    }

    private async processMedia(session: StreamSession, mediaMsg: MediaMessage): Promise<void> {
        if (session.resolved) {
            return;
        }

        // Decode base64 µ-law payload
        const payload = Buffer.from(mediaMsg.media.payload, 'base64');
        const pcm16 = decodeMulawToPcm16(payload);
        const float32_8k = pcm16ToFloat32(pcm16);
        const float32_16k = resampleLinear(float32_8k, 8000, 16000);

        // Calculate timestamp for this chunk
        const chunkDurationMs = (float32_16k.length / 16000) * 1000;
        const streamElapsedMs = Date.now() - session.startTimeMs;
        const chunkStartMs = streamElapsedMs - chunkDurationMs;

        // Feed to VAD
        const segments = session.vad.process(float32_16k, Math.max(0, chunkStartMs));

        // Run detection pipeline on each VAD segment
        for (const segment of segments) {
            if (session.resolved) {
                break;
            }
            await session.pipeline.processSegment({
                pcmData: segment.pcmData,
                sampleRate: 16000,
                durationMs: segment.endMs - segment.startMs,
                startTimestampMs: segment.startMs,
            });
        }
    }

    private handleTimeout(ws: WebSocket, session: StreamSession): void {
        if (session.resolved) {
            return;
        }
        session.resolved = true;
        console.log({
            level: 'info',
            msg: 'WebSocket closed - no voicemail detected',
            call_sid: session.callSid,
            stream_sid: session.streamSid,
            elapsed_ms: Date.now() - session.startTimeMs,
        });
        this.cleanupSession(session.streamSid);
        if (ws.readyState === WebSocket.OPEN) {
            ws.close(1000, 'Timeout - no voicemail detected');
        }
    }

    private logResultAndClose(ws: WebSocket, session: StreamSession, result: DetectionResult): void {
        const elapsedMs = Date.now() - session.startTimeMs;
        metrics.recordDetection(result.result, elapsedMs);

        if (result.result === ResultType.VOICEMAIL) {
            console.log({
                level: 'info',
                msg: 'Voicemail detected',
                call_sid: session.callSid,
                stream_sid: session.streamSid,
                detector: result.detector,
                confidence: result.confidence,
                reason: result.reason,
                detection_time_ms: elapsedMs,
            });
        } else if (result.result === ResultType.HUMAN) {
            console.log({
                level: 'info',
                msg: 'Human detected',
                call_sid: session.callSid,
                stream_sid: session.streamSid,
                detector: result.detector,
                confidence: result.confidence,
                reason: result.reason,
                detection_time_ms: elapsedMs,
            });
        }

        this.cleanupSession(session.streamSid);
        if (ws.readyState === WebSocket.OPEN) {
            ws.close(1000, 'Detection complete');
        }
    }

    private cleanupSession(streamSid: string | null): void {
        if (!streamSid) {
            return;
        }
        const session = this.activeStreams.get(streamSid);
        if (session) {
            session.dispose();
            this.activeStreams.delete(streamSid);
            metrics.decrementActiveStreams();
        }
    }
}
