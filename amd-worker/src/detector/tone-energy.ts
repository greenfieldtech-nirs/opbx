import { Detector, DetectionResult, AudioSegment, ResultType } from './types';
import { computeEnergyBands } from '../feature/energy';

export class ToneEnergyDetector implements Detector {
    readonly name = 'tone_energy';
    private state: {
        toneStartMs: number | null;
        inTone: boolean;
    } = {
        toneStartMs: null,
        inTone: false,
    };

    private readonly minToneMs = 200;
    private readonly windowMs = 50;
    private readonly sampleRate = 16000;
    private readonly toneRatioThreshold = 2.0;

    reset(): void {
        this.state = {
            toneStartMs: null,
            inTone: false,
        };
    }

    process(segment: AudioSegment): DetectionResult | null {
        const windowSamples = Math.floor((this.windowMs / 1000) * this.sampleRate);
        const numWindows = Math.floor(segment.pcmData.length / windowSamples);

        for (let i = 0; i < numWindows; i++) {
            const startSample = i * windowSamples;
            const window = segment.pcmData.subarray(startSample, startSample + windowSamples);
            const windowStartMs = segment.startTimestampMs + (i * this.windowMs);
            const bands = computeEnergyBands(window, this.sampleRate, 800, 2500);

            const isTone = bands.targetBandEnergy > 0.0001 && bands.ratio > this.toneRatioThreshold;

            if (isTone) {
                if (!this.state.inTone) {
                    this.state.inTone = true;
                    this.state.toneStartMs = windowStartMs;
                }
            } else {
                if (this.state.inTone && this.state.toneStartMs !== null) {
                    const toneDurationMs = windowStartMs - this.state.toneStartMs;
                    if (toneDurationMs >= this.minToneMs) {
                        return {
                            detector: this.name,
                            result: ResultType.VOICEMAIL,
                            confidence: 0.85,
                            reason: `Pure tone detected in 800-2500Hz band for ${toneDurationMs.toFixed(0)}ms`,
                            timestampMs: windowStartMs,
                        };
                    }
                }
                this.state.inTone = false;
                this.state.toneStartMs = null;
            }
        }

        // Check if tone extends to end of segment
        if (this.state.inTone && this.state.toneStartMs !== null) {
            const toneDurationMs = segment.startTimestampMs + segment.durationMs - this.state.toneStartMs;
            if (toneDurationMs >= this.minToneMs) {
                return {
                    detector: this.name,
                    result: ResultType.VOICEMAIL,
                    confidence: 0.85,
                    reason: `Pure tone detected in 800-2500Hz band for ${toneDurationMs.toFixed(0)}ms`,
                    timestampMs: segment.startTimestampMs + segment.durationMs,
                };
            }
        }

        return null;
    }
}
