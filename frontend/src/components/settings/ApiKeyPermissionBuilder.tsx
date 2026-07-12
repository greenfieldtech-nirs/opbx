/**
 * ApiKeyPermissionBuilder
 *
 * Renders one row per grantable resource with a 3-way selector
 * (none / read / write). "none" removes the resource from the value.
 *
 * No shadcn ToggleGroup exists in this repo, so this uses Button variants
 * as an accessible segmented control (buttons with aria-pressed).
 */

import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
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

const OPTIONS: Selection[] = ['none', 'read', 'write'];

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
      <div className="space-y-1 rounded-md border p-2">
        {resources.map((resource) => {
          const current = levelFor(resource);
          return (
            <div
              key={resource}
              className="flex items-center justify-between gap-4 py-1"
            >
              <span className="text-sm">{humanize(resource)}</span>
              <div
                role="group"
                aria-label={`${humanize(resource)} access level`}
                className="inline-flex rounded-md border"
              >
                {OPTIONS.map((option, index) => {
                  const selected = current === option;
                  return (
                    <Button
                      key={option}
                      type="button"
                      size="sm"
                      variant={selected ? 'default' : 'ghost'}
                      aria-pressed={selected}
                      onClick={() => setLevel(resource, option)}
                      className={
                        index === 0
                          ? 'rounded-r-none'
                          : index === OPTIONS.length - 1
                            ? 'rounded-l-none border-l'
                            : 'rounded-none border-l'
                      }
                    >
                      {option.charAt(0).toUpperCase() + option.slice(1)}
                    </Button>
                  );
                })}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}

export default ApiKeyPermissionBuilder;
