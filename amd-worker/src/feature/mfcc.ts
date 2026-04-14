import * as fftJs from 'fft-js';

// MFCC computation using fft-js
// Parameters matching librosa defaults as closely as possible

function melFilterbank(
    numFilters: number,
    fftSize: number,
    sampleRate: number
): number[][] {
    const fMin = 0;
    const fMax = sampleRate / 2;

    const melMin = 2595 * Math.log10(1 + fMin / 700);
    const melMax = 2595 * Math.log10(1 + fMax / 700);

    const melPoints: number[] = [];
    for (let i = 0; i <= numFilters + 1; i++) {
        const mel = melMin + (i * (melMax - melMin)) / (numFilters + 1);
        melPoints.push(mel);
    }

    const hzPoints = melPoints.map(mel => 700 * (Math.pow(10, mel / 2595) - 1));
    const binPoints = hzPoints.map(hz => Math.floor(((fftSize + 1) * hz) / sampleRate));

    const filterbank: number[][] = [];
    for (let i = 1; i <= numFilters; i++) {
        const filter = new Array(Math.floor(fftSize / 2) + 1).fill(0);
        for (let j = binPoints[i - 1]; j < binPoints[i]; j++) {
            filter[j] = (j - binPoints[i - 1]) / (binPoints[i] - binPoints[i - 1]);
        }
        for (let j = binPoints[i]; j < binPoints[i + 1]; j++) {
            filter[j] = (binPoints[i + 1] - j) / (binPoints[i + 1] - binPoints[i]);
        }
        filterbank.push(filter);
    }

    return filterbank;
}

function dct(input: number[], numCoeffs: number): number[] {
    const result: number[] = [];
    const N = input.length;
    for (let k = 0; k < numCoeffs; k++) {
        let sum = 0;
        for (let n = 0; n < N; n++) {
            sum += input[n] * Math.cos((Math.PI * k * (2 * n + 1)) / (2 * N));
        }
        result.push(sum * Math.sqrt(2 / N));
    }
    return result;
}

export function computeMfcc(
    pcmData: Float32Array,
    sampleRate: number,
    numCoeffs = 40,
    fftSize = 2048,
    hopLength = 512
): number[] {
    // Frame the audio with hop length
    const frames: Float32Array[] = [];
    for (let i = 0; i + fftSize <= pcmData.length; i += hopLength) {
        const frame = new Float32Array(fftSize);
        frame.set(pcmData.subarray(i, i + fftSize));
        // Apply Hann window
        for (let j = 0; j < fftSize; j++) {
            frame[j] *= 0.5 * (1 - Math.cos((2 * Math.PI * j) / (fftSize - 1)));
        }
        frames.push(frame);
    }

    if (frames.length === 0) {
        return new Array(numCoeffs).fill(0);
    }

    const filters = melFilterbank(numCoeffs, fftSize, sampleRate);
    const mfccFrames: number[][] = [];

    for (const frame of frames) {
        const phasors = fftJs.fft(frame as unknown as number[]);
        const mags = fftJs.util.fftMag(phasors);
        const powerSpectrum = mags.map((m: number) => m * m);

        const melEnergies: number[] = [];
        for (const filter of filters) {
            let energy = 0;
            for (let i = 0; i < powerSpectrum.length; i++) {
                energy += powerSpectrum[i] * filter[i];
            }
            melEnergies.push(Math.log(energy + 1e-10));
        }

        const coeffs = dct(melEnergies, numCoeffs);
        mfccFrames.push(coeffs);
    }

    // Average over time axis
    const averaged = new Array(numCoeffs).fill(0);
    for (let c = 0; c < numCoeffs; c++) {
        let sum = 0;
        for (let f = 0; f < mfccFrames.length; f++) {
            sum += mfccFrames[f][c];
        }
        averaged[c] = sum / mfccFrames.length;
    }

    return averaged;
}
