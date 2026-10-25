/**
 * AnnouncementBanner
 *
 * Notification banner above the home page navigation, cycling announcements
 * from the static /announcements.json (edit that file to add or change
 * announcements - no rebuild required in production, only a refresh).
 * Sorted latest date first; cycles newest → oldest.
 *
 * Each entry: { date, type, text, badge? }. `type` is the label shown in the
 * badge; `badge` picks its colour and icon (see BADGES). Without a known
 * `badge` the neutral megaphone style is used.
 */

import { useEffect, useState } from 'react';
import { Megaphone, Rocket, ShieldAlert, Sparkles, TriangleAlert, type LucideIcon } from 'lucide-react';

export type AnnouncementBadge = 'new_release' | 'feature' | 'critical' | 'important';

interface Announcement {
  date: string;
  type?: string;
  text: string;
  badge?: AnnouncementBadge;
}

interface BadgeStyle {
  label: string;
  icon: LucideIcon;
  /** Badge pill colours; solid so they stand out on the primary-coloured bar. */
  className: string;
}

export const BADGES: Record<AnnouncementBadge, BadgeStyle> = {
  new_release: { label: 'New Release', icon: Rocket, className: 'bg-emerald-500 text-white' },
  feature: { label: 'Feature Announcement', icon: Sparkles, className: 'bg-violet-500 text-white' },
  critical: { label: 'Critical Announcement', icon: ShieldAlert, className: 'bg-red-600 text-white' },
  important: { label: 'Important Notice', icon: TriangleAlert, className: 'bg-amber-400 text-amber-950' },
};

const DEFAULT_BADGE: BadgeStyle = {
  label: 'Announcement',
  icon: Megaphone,
  className: 'bg-primary-foreground/20 text-primary-foreground',
};

export function badgeFor(badge: string | undefined): BadgeStyle {
  return (badge && Object.hasOwn(BADGES, badge) ? BADGES[badge as AnnouncementBadge] : undefined) ?? DEFAULT_BADGE;
}

const ROTATE_SECONDS = 8;

export function AnnouncementBanner() {
  const [announcements, setAnnouncements] = useState<Announcement[]>([]);
  const [index, setIndex] = useState(0);

  useEffect(() => {
    fetch('/announcements.json')
      .then((res) => (res.ok ? res.json() : []))
      .then((data: Announcement[]) => {
        // Latest date first, then by text for stable ordering within a date.
        const sorted = [...(Array.isArray(data) ? data : [])].sort(
          (a, b) => b.date.localeCompare(a.date) || a.text.localeCompare(b.text),
        );
        setAnnouncements(sorted);
      })
      .catch(() => setAnnouncements([]));
  }, []);

  useEffect(() => {
    if (announcements.length <= 1) {
      return;
    }

    const timer = setInterval(() => {
      setIndex((i) => (i + 1) % announcements.length);
    }, ROTATE_SECONDS * 1000);

    return () => clearInterval(timer);
  }, [announcements.length]);

  if (announcements.length === 0) {
    return null;
  }

  const current = announcements[index % announcements.length];
  const badge = badgeFor(current.badge);
  const Icon = badge.icon;

  return (
    <div className="relative z-[60] flex items-center justify-center gap-3 bg-primary px-4 py-2 text-center">
      <span className="inline-flex flex-wrap items-center justify-center gap-x-2 text-sm text-primary-foreground">
        <span
          className={`inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-semibold uppercase tracking-wide ${badge.className}`}
        >
          <Icon className="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
          {current.type || badge.label}
        </span>
        <span>{current.text}</span>
        <span className="text-xs text-primary-foreground/70">{current.date}</span>
      </span>
      {announcements.length > 1 && (
        <span className="absolute right-4 hidden text-xs text-primary-foreground/70 sm:inline">
          {index + 1}/{announcements.length}
        </span>
      )}
    </div>
  );
}
