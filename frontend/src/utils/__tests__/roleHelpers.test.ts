import { describe, it, expect } from 'vitest';
import { can, canDeleteDistributionList } from '../roleHelpers';

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

  it('lets a PBX Admin delete only failed distribution lists', () => {
    expect(canDeleteDistributionList('owner', 'ready')).toBe(true);
    expect(canDeleteDistributionList('pbx_admin', 'failed')).toBe(true);
    expect(canDeleteDistributionList('pbx_admin', 'ready')).toBe(false);
    expect(canDeleteDistributionList('reporter', 'failed')).toBe(false);
  });
});
