import Piscina from 'piscina';
import { Detector, DetectionResult, AudioSegment, ResultType } from './types';
import { OnnxModel } from '../model/onnx';
import { computeMfcc } from '../feature/mfcc';
import { resolve } from 'path';

export class BeepMlDetector implements Detector {
    readonly name = 'beep_ml';
    private pool: Piscina | null = null;
    private useWorker = true;

    constructor(private model: OnnxModel) {
        // Try to set up piscina worker pool for MFCC extraction
        try {
            const workerPath = resolve(__dirname, '../feature/mfcc.worker.js');
            this.pool = new Piscina({
                filename: workerPath,
                maxThreads: 4,
            });
        } catch {
            this.useWorker = false;
        }
    }

    reset(): void {
        // nothing to reset
    }

    async processAsync(segment: AudioSegment): Promise<DetectionResult | null> {
        if (!this.model.isLoaded()) {
            return null;
        }

        let mfcc: number[];
        if (this.useWorker && this.pool) {
            const result = await this.pool.run({
                pcmData: Array.from(segment.pcmData),
                sampleRate: segment.sampleRate,
                numCoeffs: 40,
                fftSize: 2048,
                hopLength: 512,
            });
            mfcc = (result as { features: number[] }).features;
        } else {
            mfcc = computeMfcc(segment.pcmData, segment.sampleRate, 40, 2048, 512);
        }

        const prediction = await this.model.predict(mfcc);
        // prediction 0 = beep (voicemail), 1 = speech (human)
        if (prediction === 0) {
            return {
                detector: this.name,
                result: ResultType.VOICEMAIL,
                confidence: 0.95,
                reason: `Beep tone detected by ML model (prediction=${prediction})`,
                timestampMs: segment.startTimestampMs + segment.durationMs,
            };
        }
        return null;
    }

    process(segment: AudioSegment): DetectionResult | null {
        // Synchronous wrapper - should be called with await in practice
        throw new Error('BeepMlDetector requires async processAsync');
    }
}
