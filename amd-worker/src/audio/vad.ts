export interface VadConfig {
    silenceFrames: number;
    clipMinMs: number;
    clipMaxMs: number;
    vadSensitivity: number; // 1-3, 3 = most aggressive
    frameMs: number;
    sampleRate: number;
}

export interface VadSegment {
    pcmData: Float32Array;
    startMs: number;
    endMs: number;
}

export class EnergyVad {
    private config: VadConfig;
    private buffer: Float32Array = new Float32Array(0);
    private bufferStartMs = 0;
    private silenceCounter = 0;
    private speechCounter = 0;
    private inSpeech = false;
    private segments: VadSegment[] = [];
    private currentStreamMs = 0;

    constructor(config: Partial<VadConfig> = {}) {
        this.config = {
            silenceFrames: config.silenceFrames ?? 10,
            clipMinMs: config.clipMinMs ?? 200,
            clipMaxMs: config.clipMaxMs ?? 3000,
            vadSensitivity: config.vadSensitivity ?? 3,
            frameMs: config.frameMs ?? 20,
            sampleRate: config.sampleRate ?? 16000,
        };
    }

    reset(): void {
        this.buffer = new Float32Array(0);
        this.bufferStartMs = 0;
        this.silenceCounter = 0;
        this.speechCounter = 0;
        this.inSpeech = false;
        this.segments = [];
        this.currentStreamMs = 0;
    }

    process(pcmData: Float32Array, startMs: number): VadSegment[] {
        // Append to buffer
        const newBuffer = new Float32Array(this.buffer.length + pcmData.length);
        newBuffer.set(this.buffer);
        newBuffer.set(pcmData, this.buffer.length);
        this.buffer = newBuffer;
        if (this.buffer.length === pcmData.length) {
            this.bufferStartMs = startMs;
        }

        const frameSamples = Math.floor((this.config.frameMs / 1000) * this.config.sampleRate);
        const newSegments: VadSegment[] = [];

        while (this.buffer.length >= frameSamples) {
            const frame = this.buffer.subarray(0, frameSamples);
            const isSpeech = this.isSpeechFrame(frame);
            this.currentStreamMs = this.bufferStartMs + (frameSamples / this.config.sampleRate) * 1000;

            if (isSpeech) {
                this.speechCounter++;
                this.silenceCounter = 0;
                if (!this.inSpeech && this.speechCounter >= 2) {
                    this.inSpeech = true;
                }
            } else {
                this.silenceCounter++;
                if (this.inSpeech && this.silenceCounter >= this.config.silenceFrames) {
                    // End of speech segment
                    const segmentEndMs = this.currentStreamMs;
                    const segmentStartMs = this.bufferStartMs;
                    const segmentSamples = Math.floor(((segmentEndMs - segmentStartMs) / 1000) * this.config.sampleRate);
                    const segmentData = this.buffer.subarray(0, Math.min(segmentSamples, this.buffer.length));

                    const durationMs = segmentEndMs - segmentStartMs;
                    if (durationMs >= this.config.clipMinMs && durationMs <= this.config.clipMaxMs) {
                        newSegments.push({
                            pcmData: new Float32Array(segmentData),
                            startMs: segmentStartMs,
                            endMs: segmentEndMs,
                        });
                    }

                    // Consume processed samples
                    const consumedSamples = Math.min(segmentSamples, this.buffer.length);
                    this.buffer = this.buffer.subarray(consumedSamples);
                    this.bufferStartMs = segmentEndMs;
                    this.inSpeech = false;
                    this.speechCounter = 0;
                    this.silenceCounter = 0;
                    continue;
                }
            }

            // Shift frame by half for overlap (optional)
            const shiftSamples = frameSamples; // no overlap for simplicity
            this.buffer = this.buffer.subarray(shiftSamples);
            this.bufferStartMs += (shiftSamples / this.config.sampleRate) * 1000;
        }

        this.segments.push(...newSegments);
        return newSegments;
    }

    private isSpeechFrame(frame: Float32Array): boolean {
        const energy = this.computeEnergy(frame);
        // Sensitivity affects threshold: higher = lower threshold = more sensitive
        const baseThreshold = 0.001;
        const threshold = baseThreshold / this.config.vadSensitivity;
        return energy > threshold;
    }

    private computeEnergy(frame: Float32Array): number {
        let sum = 0;
        for (let i = 0; i < frame.length; i++) {
            sum += frame[i] * frame[i];
        }
        return sum / frame.length;
    }

    flush(): VadSegment[] {
        if (this.buffer.length > 0 && this.inSpeech) {
            const segmentStartMs = this.bufferStartMs;
            const segmentEndMs = this.currentStreamMs;
            const durationMs = segmentEndMs - segmentStartMs;
            if (durationMs >= this.config.clipMinMs && durationMs <= this.config.clipMaxMs) {
                this.segments.push({
                    pcmData: new Float32Array(this.buffer),
                    startMs: segmentStartMs,
                    endMs: segmentEndMs,
                });
            }
        }
        const result = this.segments;
        this.reset();
        return result;
    }
}
