import { afterEach, describe, expect, it, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { AnnouncementBanner, BADGES, badgeFor } from '../AnnouncementBanner';

describe('announcement badges', () => {
  it('gives every badge its own background and icon', () => {
    const styles = Object.values(BADGES);

    expect(new Set(styles.map((b) => b.className)).size).toBe(styles.length);
    expect(new Set(styles.map((b) => b.icon)).size).toBe(styles.length);
  });

  it('falls back to the neutral style for a missing or unknown badge', () => {
    expect(badgeFor(undefined).label).toBe('Announcement');
    expect(badgeFor('toString').label).toBe('Announcement');
    expect(badgeFor('critical')).toBe(BADGES.critical);
  });
});

describe('AnnouncementBanner', () => {
  afterEach(() => vi.unstubAllGlobals());

  it('shows the newest announcement with its badge style, labelled by type or the badge name', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => [
        { date: '2026-10-01', badge: 'feature', text: 'Older feature' },
        { date: '2026-10-26', badge: 'critical', type: 'Emergency Security Release', text: 'Patch now' },
      ],
    }));

    render(<AnnouncementBanner />);

    const label = await screen.findByText('Emergency Security Release');
    expect(label.className).toContain('bg-red-600');
    expect(screen.getByText('Patch now')).toBeTruthy();
  });

  it('labels an entry without a type with the badge name', async () => {
    vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
      ok: true,
      json: async () => [{ date: '2026-10-20', badge: 'new_release', text: 'v0.1.1 is out' }],
    }));

    render(<AnnouncementBanner />);

    expect((await screen.findByText('New Release')).className).toContain('bg-emerald-500');
  });
});
