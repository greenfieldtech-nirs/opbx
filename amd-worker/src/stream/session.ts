import { DetectionPipeline, DetectorResultHandler } from '../detector/pipeline';
import { EnergyVad } from '../audio/vad';
import { DetectionResult } from '../detector/types';

export interface StreamSessionConfig {
    callSid: string;
    streamSid: string;
    timeoutMs: number;
    detectors: string[];
}

export class StreamSession {
    public callSid: string;
    public streamSid: string;
    public startTimeMs: number;
    public pipeline: DetectionPipeline;
    public vad: EnergyVad;
    public timeoutTimer: NodeJS.Timeout | null = null;
    public resolved = false;
    public onTimeout: (() => void) | null = null;
    public onResultLogged: ((result: DetectionResult) => void) | null = null;

    constructor(config: StreamSessionConfig, beepMlDetector: any) {
        this.callSid = config.callSid;
        this.streamSid = config.streamSid;
        this.startTimeMs = Date.now();
        this.vad = new EnergyVad({
            sampleRate: 16000,
            silenceFrames: 10,
            clipMinMs: 200,
            clipMaxMs: 3000,
            vadSensitivity: 3,
        });

        const resultHandler: DetectorResultHandler = (result) => {
            this.resolved = true;
            this.clearTimeout();
            if (this.onResultLogged) {
                this.onResultLogged(result);
            }
        };

        this.pipeline = new DetectionPipeline(
            config.detectors,
            config.timeoutMs,
            resultHandler,
            beepMlDetector
        );
    }

    startTimeoutTimer(): void {
        this.clearTimeout();
        this.timeoutTimer = setTimeout(() => {
            if (!this.resolved && this.onTimeout) {
                this.onTimeout();
            }
        }, this.pipeline.getTimeoutMs());
    }

    clearTimeout(): void {
        if (this.timeoutTimer) {
            clearTimeout(this.timeoutTimer);
            this.timeoutTimer = null;
        }
    }

    dispose(): void {
        this.clearTimeout();
        this.resolved = true;
    }
}
