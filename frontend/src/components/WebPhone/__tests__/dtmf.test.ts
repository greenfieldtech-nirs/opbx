import { describe, expect, it } from 'vitest';
import { dtmfTransportFor } from '../dtmf';

const sessionWith = (senders: unknown[]) => ({ connection: { getSenders: () => senders } });

describe('dtmfTransportFor', () => {
  it('uses RTP events when the audio sender can insert DTMF', () => {
    const audio = { track: { kind: 'audio' }, dtmf: { canInsertDTMF: true } };
    expect(dtmfTransportFor(sessionWith([{ track: { kind: 'video' } }, audio]))).toBe('RFC2833');
  });

  it('falls back to SIP INFO when telephone-event was not negotiated', () => {
    const audio = { track: { kind: 'audio' }, dtmf: { canInsertDTMF: false } };
    expect(dtmfTransportFor(sessionWith([audio]))).toBe('INFO');
  });

  it('falls back to SIP INFO without an audio sender or connection', () => {
    expect(dtmfTransportFor(sessionWith([]))).toBe('INFO');
    expect(dtmfTransportFor({})).toBe('INFO');
  });
});
