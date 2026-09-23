/**
 * AnnouncementBanner
 *
 * Notification banner above the home page navigation, cycling announcements
 * from the static /announcements.json (edit that file to add or change
 * announcements - no rebuild required in production, only a refresh).
 * Sorted latest date first; cycles newest → oldest.
 */

import { useEffect, useState } from 'react';
import { Megaphone } from 'lucide-react';

interface Announcement {
  date: string;
  type: string;
  text: string;
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

  return (
    <div className="relative z-[60] flex items-center justify-center gap-3 bg-primary px-4 py-2 text-center">
      <Megaphone className="h-4 w-4 shrink-0 text-primary-foreground" />
      <span className="inline-flex flex-wrap items-center justify-center gap-x-2 text-sm text-primary-foreground">
        <span className="rounded-full bg-primary-foreground/20 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide">
          {current.type}
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
