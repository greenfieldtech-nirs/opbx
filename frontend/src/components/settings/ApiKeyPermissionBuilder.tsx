/**
 * ApiKeyPermissionBuilder
 *
 * Renders one cell per grantable resource with a select box
 * (none / read / write), arranged in a responsive 3-column grid.
 * "none" removes the resource from the value.
 */

import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { ApiKeyPermission, ApiKeyPermissionLevel } from '@/services/apiKeys.service';

interface ApiKeyPermissionBuilderProps {
  resources: string[];
  value: ApiKeyPermission[];
  onChange: (permissions: ApiKeyPermission[]) => void;
}

type Selection = 'none' | ApiKeyPermissionLevel;

/** "business-hours" -> "Business Hours" */
function humanize(slug: string): string {
  return slug
    .split('-')
    .map((word) => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ');
}

const OPTIONS: { value: Selection; label: string }[] = [
  { value: 'none', label: 'No Access' },
  { value: 'read', label: 'Read' },
  { value: 'write', label: 'Write' },
];

export function ApiKeyPermissionBuilder({
  resources,
  value,
  onChange,
}: ApiKeyPermissionBuilderProps) {
  const levelFor = (resource: string): Selection =>
    value.find((p) => p.resource === resource)?.level ?? 'none';

  const setLevel = (resource: string, selection: Selection) => {
    const without = value.filter((p) => p.resource !== resource);
    if (selection === 'none') {
      onChange(without);
      return;
    }
    onChange([...without, { resource, level: selection }]);
  };

  if (resources.length === 0) {
    return (
      <p className="text-sm text-muted-foreground">
        No grantable resources are available.
      </p>
    );
  }

  return (
    <div className="space-y-2">
      <Label>Permissions</Label>
      <div className="grid grid-cols-1 gap-2 rounded-md border p-2 sm:grid-cols-2 lg:grid-cols-3">
        {resources.map((resource) => {
          const current = levelFor(resource);
          return (
            <div
              key={resource}
              className="flex flex-col gap-1.5 rounded-md px-2 py-1.5"
            >
              <span className="truncate text-sm" title={humanize(resource)}>
                {humanize(resource)}
              </span>
              <Select
                value={current}
                onValueChange={(v) => setLevel(resource, v as Selection)}
              >
                <SelectTrigger
                  className="h-8 w-full"
                  aria-label={`${humanize(resource)} access level`}
                >
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {OPTIONS.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          );
        })}
      </div>
    </div>
  );
}

export default ApiKeyPermissionBuilder;
