import { describe, expect, it } from 'vitest';
import { AMD_DEFAULT_ACTIONS } from '../amdDefaults';

describe('AMD_DEFAULT_ACTIONS', () => {
  it('defaults the Unclear action to CONTINUE', () => {
    expect(AMD_DEFAULT_ACTIONS.action_unknown).toBe('CONTINUE');
    expect(AMD_DEFAULT_ACTIONS.action_voicemail).toBe('HANGUP');
    expect(AMD_DEFAULT_ACTIONS.action_human).toBe('CONTINUE');
  });
});
