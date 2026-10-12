/**
 * "Unprovisioned" marker: a resource saved while one of its routes is 'unavailable' (set up for future use).
 * The API exposes it as `is_unprovisioned` and as the `unprovisioned=1` list filter.
 */

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export function UnprovisionedBadge({ className }: { className?: string }) {
  return (
    <Badge
      variant="outline"
      title="A route of this resource has no destination yet (Destination unavailable)"
      className={cn('border-amber-300 bg-amber-50 text-amber-800', className)}
    >
      Unprovisioned
    </Badge>
  );
}

/** Toggle for the list filter bar; pass `unprovisioned: on ? 1 : undefined` to the list query. */
export function UnprovisionedFilter({ value, onChange }: { value: boolean; onChange: (value: boolean) => void }) {
  return (
    <Button
      type="button"
      variant={value ? 'default' : 'outline'}
      aria-pressed={value}
      onClick={() => onChange(!value)}
      className="w-full md:w-auto"
    >
      Unprovisioned
    </Button>
  );
}
