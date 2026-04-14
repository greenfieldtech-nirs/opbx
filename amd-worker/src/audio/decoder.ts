// µ-law to linear PCM 16-bit conversion lookup table
const MULAW_TO_PCM = new Int16Array(256);

(function buildTable() {
    const BIAS = 33;
    for (let i = 0; i < 256; i++) {
        const sign = (i & 0x80) ? -1 : 1;
        const exponent = (i & 0x70) >> 4;
        const mantissa = i & 0x0f;
        let value = mantissa << (exponent + 3);
        value += BIAS << exponent;
        if (sign < 0) {
            value = BIAS - value;
        } else {
            value = value - BIAS;
        }
        MULAW_TO_PCM[i] = value;
    }
})();

export function decodeMulawToPcm16(mulawData: Buffer): Int16Array {
    const pcm = new Int16Array(mulawData.length);
    for (let i = 0; i < mulawData.length; i++) {
        pcm[i] = MULAW_TO_PCM[mulawData[i]];
    }
    return pcm;
}

export function pcm16ToFloat32(pcm16: Int16Array): Float32Array {
    const float32 = new Float32Array(pcm16.length);
    for (let i = 0; i < pcm16.length; i++) {
        float32[i] = pcm16[i] / 32768.0;
    }
    return float32;
}
