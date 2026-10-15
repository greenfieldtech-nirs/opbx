import type { PlatformHealthStatus } from './platform';

export interface PublicStatusGroup {
  key: string;
  name: string;
  status: PlatformHealthStatus;
  /** Last 6h in 5-minute buckets, worst member status per bucket. */
  history: { at: string; status: PlatformHealthStatus; check_at?: string }[];
}

export interface PublicStatus {
  status: PlatformHealthStatus;
  updated_at: string | null;
  groups: PublicStatusGroup[];
}
