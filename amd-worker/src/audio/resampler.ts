export function resampleLinear(input: Float32Array, inputRate: number, outputRate: number): Float32Array {
    if (inputRate === outputRate) {
        return input;
    }
    const ratio = inputRate / outputRate;
    const outputLength = Math.floor(input.length / ratio);
    const output = new Float32Array(outputLength);

    for (let i = 0; i < outputLength; i++) {
        const pos = i * ratio;
        const index = Math.floor(pos);
        const frac = pos - index;
        const s0 = input[index] ?? 0;
        const s1 = input[index + 1] ?? s0;
        output[i] = s0 + frac * (s1 - s0);
    }

    return output;
}
