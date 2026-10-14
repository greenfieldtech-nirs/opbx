import { describe, expect, it } from 'vitest';
import { getAllDestinationTypeConfigs, labelForRoutingType } from '../destination-config';

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

describe('getAllDestinationTypeConfigs', () => {
  const values = (...args: Parameters<typeof getAllDestinationTypeConfigs>) =>
    getAllDestinationTypeConfigs(...args).map((c) => c.value);

  it('offers Hang Up when allowedTypes lists it', () => {
    expect(values(['extension', 'hangup'])).toEqual(['extension', 'hangup']);
  });
  it('offers Hang Up alongside allowedTypes when includeHangup is set', () => {
    expect(values(['ring_group'], true)).toEqual(['ring_group', 'hangup']);
  });
  it('hides Hang Up otherwise', () => {
    expect(values(['ring_group'])).toEqual(['ring_group']);
    expect(values()).not.toContain('hangup');
  });
});
