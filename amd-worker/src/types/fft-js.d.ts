declare module 'fft-js' {
    export function fft(signal: number[]): number[][];
    export namespace util {
        export function fftMag(phasors: number[][]): number[];
    }
}
