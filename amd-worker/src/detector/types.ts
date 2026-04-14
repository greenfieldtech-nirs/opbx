export enum ResultType {
    VOICEMAIL = 'voicemail',
    HUMAN = 'human',
    UNKNOWN = 'unknown',
}

export interface DetectionResult {
    detector: string;
    result: ResultType;
    confidence: number;
    reason: string;
    timestampMs: number;
}

export interface AudioSegment {
    pcmData: Float32Array;
    sampleRate: number;
    durationMs: number;
    startTimestampMs: number;
}

export interface Detector {
    readonly name: string;
    process(segment: AudioSegment): DetectionResult | null;
    reset(): void;
}
