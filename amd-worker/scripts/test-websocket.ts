#!/usr/bin/env ts-node
/**
 * Manual test script for the AMD Worker WebSocket endpoint.
 * Connects through nginx proxy and sends synthetic Cloudonix stream messages.
 *
 * Usage:
 *   npx ts-node scripts/test-websocket.ts [options]
 *
 * Options:
 *   --url       WebSocket URL (default: ws://localhost/ws/amd/detect)
 *   --mode      Audio mode: beep | noise | silence (default: beep)
 *   --duration  Audio duration in seconds (default: 10)
 *   --chunk     Chunk size in milliseconds (default: 20)
 *
 * Examples:
 *   npx ts-node scripts/test-websocket.ts --mode=beep --duration=3
 *   npx ts-node scripts/test-websocket.ts --mode=silence --duration=50
 */

import WebSocket from 'ws';

// Build inverse lookup table from the decoder's MULAW_TO_PCM
const MULAW_TO_PCM = new Int16Array(256);
(function buildDecodeTable() {
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

// Brute-force inverse table: map PCM16 (-32768..32767) -> µ-law byte
const PCM_TO_MULAW = new Uint8Array(65536);
(function buildEncodeTable() {
    for (let pcm = -32768; pcm <= 32767; pcm++) {
        const idx = pcm + 32768;
        let bestU = 0;
        let bestDist = Infinity;
        for (let u = 0; u < 256; u++) {
            const dist = Math.abs(pcm - MULAW_TO_PCM[u]);
            if (dist < bestDist) {
                bestDist = dist;
                bestU = u;
            }
        }
        PCM_TO_MULAW[idx] = bestU;
    }
})();

function pcm16ToMulaw(pcm16: Int16Array): Buffer {
    const ulaw = Buffer.alloc(pcm16.length);
    for (let i = 0; i < pcm16.length; i++) {
        ulaw[i] = PCM_TO_MULAW[pcm16[i] + 32768];
    }
    return ulaw;
}

function generateSineWave(freqHz: number, sampleRate: number, durationMs: number): Int16Array {
    const samples = Math.floor((durationMs / 1000) * sampleRate);
    const pcm = new Int16Array(samples);
    const amplitude = 8000;
    for (let i = 0; i < samples; i++) {
        const t = i / sampleRate;
        pcm[i] = Math.round(amplitude * Math.sin(2 * Math.PI * freqHz * t));
    }
    return pcm;
}

function generateNoise(sampleRate: number, durationMs: number): Int16Array {
    const samples = Math.floor((durationMs / 1000) * sampleRate);
    const pcm = new Int16Array(samples);
    const amplitude = 4000;
    for (let i = 0; i < samples; i++) {
        pcm[i] = Math.round(amplitude * (Math.random() * 2 - 1));
    }
    return pcm;
}

function generateSilence(sampleRate: number, durationMs: number): Int16Array {
    const samples = Math.floor((durationMs / 1000) * sampleRate);
    return new Int16Array(samples);
}

function parseArgs(): { url: string; mode: string; duration: number; chunkMs: number } {
    const args = process.argv.slice(2);
    let url = 'ws://localhost/ws/amd/detect';
    let mode = 'beep';
    let duration = 10;
    let chunkMs = 20;

    for (const arg of args) {
        if (arg.startsWith('--url=')) url = arg.split('=')[1];
        if (arg.startsWith('--mode=')) mode = arg.split('=')[1];
        if (arg.startsWith('--duration=')) duration = parseInt(arg.split('=')[1], 10);
        if (arg.startsWith('--chunk=')) chunkMs = parseInt(arg.split('=')[1], 10);
    }

    return { url, mode, duration, chunkMs };
}

async function main(): Promise<void> {
    const { url, mode, duration, chunkMs } = parseArgs();
    const callSid = `TEST-${Date.now()}`;
    const streamSid = `STREAM-${Date.now()}`;

    console.log('Connecting to:', url);
    console.log('Mode:', mode, '| Duration:', duration, 's | Chunk:', chunkMs, 'ms');

    const ws = new WebSocket(url);

    ws.on('open', () => {
        console.log('WebSocket connected');

        // Send connected
        ws.send(JSON.stringify({ event: 'connected', protocol: 'Call', version: '1.0.0' }));

        // Send start
        ws.send(JSON.stringify({
            event: 'start',
            streamSid,
            start: {
                streamSid,
                accountSid: 'AC_test',
                callSid,
                tracks: ['inbound_track'],
                mediaFormat: {
                    encoding: 'audio/x-mulaw',
                    sampleRate: 8000,
                    channels: 1,
                },
            },
        }));

        console.log('Sent start for callSid:', callSid);

        // Generate audio
        let pcm16: Int16Array;
        if (mode === 'beep') {
            pcm16 = generateSineWave(1000, 8000, duration * 1000);
        } else if (mode === 'noise') {
            pcm16 = generateNoise(8000, duration * 1000);
        } else {
            pcm16 = generateSilence(8000, duration * 1000);
        }

        const mulaw = pcm16ToMulaw(pcm16);
        const samplesPerChunk = Math.floor((chunkMs / 1000) * 8000);
        let chunkIndex = 1;
        let offset = 0;

        const interval = setInterval(() => {
            if (offset >= mulaw.length || ws.readyState !== WebSocket.OPEN) {
                clearInterval(interval);
                // Send stop after audio finishes
                setTimeout(() => {
                    if (ws.readyState === WebSocket.OPEN) {
                        ws.send(JSON.stringify({
                            event: 'stop',
                            streamSid,
                            stop: {
                                accountSid: 'AC_test',
                                callSid,
                            },
                        }));
                        console.log('Sent stop');
                    }
                }, 500);
                return;
            }

            const chunk = mulaw.subarray(offset, offset + samplesPerChunk);
            const payload = chunk.toString('base64');
            ws.send(JSON.stringify({
                event: 'media',
                streamSid,
                media: {
                    track: 'inbound_track',
                    chunk: chunkIndex++,
                    timestamp: String((offset / 8000) * 1000),
                    payload,
                },
            }));

            offset += samplesPerChunk;
        }, chunkMs);
    });

    ws.on('message', (data) => {
        console.log('Message from server:', data.toString());
    });

    ws.on('close', (code, reason) => {
        console.log('WebSocket closed:', code, reason.toString());
        process.exit(0);
    });

    ws.on('error', (err) => {
        console.error('WebSocket error:', err.message);
        process.exit(1);
    });
}

main().catch(console.error);
