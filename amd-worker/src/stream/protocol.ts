export interface ConnectedMessage {
    event: 'connected';
    protocol: string;
    version: string;
}

export interface StartMessage {
    event: 'start';
    streamSid: string;
    start: {
        streamSid: string;
        accountSid: string;
        callSid: string;
        tracks: string[];
        customParameters?: Record<string, string>;
        mediaFormat: {
            encoding: string;
            sampleRate: number;
            channels: number;
        };
    };
}

export interface MediaMessage {
    event: 'media';
    streamSid: string;
    media: {
        track: string;
        chunk: number;
        timestamp: string;
        payload: string; // base64
    };
}

export interface DtmfMessage {
    event: 'dtmf';
    streamSid: string;
    dtmf: {
        track: string;
        digit: string;
    };
}

export interface StopMessage {
    event: 'stop';
    streamSid: string;
    stop: {
        accountSid: string;
        callSid: string;
    };
}

export type StreamMessage = ConnectedMessage | StartMessage | MediaMessage | DtmfMessage | StopMessage;

export function parseMessage(data: string): StreamMessage | null {
    try {
        const parsed = JSON.parse(data) as StreamMessage;
        if (!parsed.event) {
            return null;
        }
        return parsed;
    } catch {
        return null;
    }
}
