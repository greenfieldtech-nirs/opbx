export class AudioBuffer {
    private buffer: Float32Array = new Float32Array(0);
    private sampleRate: number;
    private totalMs = 0;

    constructor(sampleRate: number) {
        this.sampleRate = sampleRate;
    }

    reset(): void {
        this.buffer = new Float32Array(0);
        this.totalMs = 0;
    }

    append(pcmData: Float32Array): void {
        const newBuffer = new Float32Array(this.buffer.length + pcmData.length);
        newBuffer.set(this.buffer);
        newBuffer.set(pcmData, this.buffer.length);
        this.buffer = newBuffer;
        this.totalMs += (pcmData.length / this.sampleRate) * 1000;
    }

    getBuffer(): Float32Array {
        return this.buffer;
    }

    getDurationMs(): number {
        return this.totalMs;
    }

    consume(samples: number): void {
        this.buffer = this.buffer.subarray(samples);
    }
}
