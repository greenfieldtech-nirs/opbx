import { describe, expect, it } from 'vitest';
import { callerIdentity, callerLabel, isLive } from '../incomingCall';

const session = (user: string | undefined, displayName?: string, uri = `sip:${user}@pbx.example.com`) => ({
  remote_identity: { display_name: displayName, uri: { user, toString: () => uri } },
});

describe('callerLabel', () => {
  it('shows the number, not the SIP URI', () => {
    expect(callerLabel(session('+15551234567'))).toBe('+15551234567');
  });

  it('adds the display name when there is one', () => {
    expect(callerLabel(session('1001', 'Alice'))).toBe('Alice (1001)');
    expect(callerLabel(session('1001', '  '))).toBe('1001');
    expect(callerLabel(session('1001', '1001'))).toBe('1001');
  });

  it('falls back to the URI, then to "Unknown caller"', () => {
    expect(callerLabel(session(undefined, undefined, 'sip:anonymous.invalid'))).toBe('sip:anonymous.invalid');
    expect(callerLabel({})).toBe('Unknown caller');
  });
});

describe('callerIdentity', () => {
  it('splits the caller ID from the name, dropping a name that only repeats the number', () => {
    expect(callerIdentity(session('+15551234567', ' Alice '))).toEqual({ id: '+15551234567', name: 'Alice' });
    expect(callerIdentity(session('1001', '1001'))).toEqual({ id: '1001', name: '' });
    expect(callerIdentity(session('1001'))).toEqual({ id: '1001', name: '' });
  });
});

describe('isLive', () => {
  it('is false for no session or an ended one', () => {
    expect(isLive(null)).toBe(false);
    expect(isLive({ isEnded: () => true })).toBe(false);
    expect(isLive({ isEnded: () => false })).toBe(true);
  });
});
