import { describe, expect, it } from 'vitest';
import { firstIvrMenuError } from '../ivrMenu';

const valid = () => ({
  name: 'Main',
  tts_text: 'Press 1',
  max_timeout: 5,
  inter_digit_timeout: 2,
  max_turns: 3,
  failover_destination_type: 'hangup',
  status: 'active',
  options: [{ input_digits: '1', destination_type: 'extension', destination_id: '12', priority: 1 }],
});

describe('ivrMenuSchema', () => {
  it('accepts a valid menu', () => {
    expect(firstIvrMenuError(valid())).toBeNull();
  });

  it('enforces the TTS 1000 character limit', () => {
    expect(firstIvrMenuError({ ...valid(), tts_text: 'a'.repeat(1000) })).toBeNull();
    expect(firstIvrMenuError({ ...valid(), tts_text: 'a'.repeat(1001) })).toMatch(/1000/);
  });

  it('requires an audio source and 1-20 options', () => {
    expect(firstIvrMenuError({ ...valid(), tts_text: undefined })).toMatch(/audio source/);
    expect(firstIvrMenuError({ ...valid(), options: [] })).toMatch(/At least one/);
    const many = Array.from({ length: 21 }, (_, i) => ({ ...valid().options[0], priority: i + 1 }));
    expect(firstIvrMenuError({ ...valid(), options: many })).toMatch(/Maximum 20/);
  });

  it('lets unavailable options omit a destination', () => {
    const o = { input_digits: '2', destination_type: 'unavailable', priority: 1 };
    expect(firstIvrMenuError({ ...valid(), options: [o] })).toBeNull();
  });
});
