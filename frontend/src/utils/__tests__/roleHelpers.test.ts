import { describe, it, expect } from 'vitest';
import { can } from '../roleHelpers';

describe('can()', () => {
  it('limits disconnect to the owner', () => {
    expect(can('owner', 'liveCalls.disconnect')).toBe(true);
    for (const r of ['pbx_admin', 'supervisor', 'reporter', 'pbx_user']) {
      expect(can(r, 'liveCalls.disconnect')).toBe(false);
    }
  });

  it('gives auto dialer writes to owner and pbx_admin only', () => {
    expect(can('pbx_admin', 'autoDialer.manage')).toBe(true);
    expect(can('reporter', 'autoDialer.manage')).toBe(false);
    expect(can('pbx_admin', 'autoDialer.destroy')).toBe(false);
  });

  it('is false without a role', () => {
    expect(can(undefined, 'recordings.manage')).toBe(false);
  });
});
