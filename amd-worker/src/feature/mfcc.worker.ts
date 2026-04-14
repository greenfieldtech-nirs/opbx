import { computeMfcc } from './mfcc';

export interface MfccWorkerInput {
    pcmData: number[];
    sampleRate: number;
    numCoeffs: number;
    fftSize: number;
    hopLength: number;
}

export interface MfccWorkerOutput {
    features: number[];
}

export default function extractMfcc(input: MfccWorkerInput): MfccWorkerOutput {
    const pcm = new Float32Array(input.pcmData);
    const features = computeMfcc(pcm, input.sampleRate, input.numCoeffs, input.fftSize, input.hopLength);
    return { features };
}
