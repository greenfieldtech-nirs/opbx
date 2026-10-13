import { describe, expect, it } from 'vitest';
import { labelForRoutingType } from '../destination-config';

describe('labelForRoutingType', () => {
  it('uses the configured labels', () => {
    expect(labelForRoutingType('call_queue')).toBe('Call Queue');
    expect(labelForRoutingType('ring_group')).toBe('Ring Group');
    expect(labelForRoutingType('ivr_menu')).toBe('IVR Menu');
  });
  it('title-cases unknown values without underscores', () => {
    expect(labelForRoutingType('call_tracking')).toBe('Call Tracking');
    expect(labelForRoutingType('active')).toBe('Active');
  });
});
