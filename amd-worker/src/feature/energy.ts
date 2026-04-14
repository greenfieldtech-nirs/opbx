export interface EnergyBands {
    totalEnergy: number;
    targetBandEnergy: number; // 800-2500 Hz
    otherEnergy: number;
    ratio: number;
}

export function computeEnergyBands(
    pcmData: Float32Array,
    sampleRate: number,
    lowFreq = 800,
    highFreq = 2500
): EnergyBands {
    const fft = require('fft-js').fft;
    const fftUtil = require('fft-js').util;

    // Use nearest power of 2 for FFT
    const n = Math.pow(2, Math.ceil(Math.log2(pcmData.length)));
    const padded = new Float32Array(n);
    padded.set(pcmData);

    const phasors = fft(padded);
    const magnitudes = fftUtil.fftMag(phasors);

    const binSize = sampleRate / n;
    let totalEnergy = 0;
    let targetBandEnergy = 0;

    for (let i = 0; i < magnitudes.length; i++) {
        const freq = i * binSize;
        const energy = magnitudes[i] * magnitudes[i];
        totalEnergy += energy;
        if (freq >= lowFreq && freq <= highFreq) {
            targetBandEnergy += energy;
        }
    }

    const otherEnergy = totalEnergy - targetBandEnergy;
    const ratio = otherEnergy > 0 ? targetBandEnergy / otherEnergy : targetBandEnergy;

    return {
        totalEnergy,
        targetBandEnergy,
        otherEnergy,
        ratio,
    };
}
