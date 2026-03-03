/**
 * AuditLogEntry Component
 *
 * Displays a single audit log entry with details and JSON diff.
 */

import { useState } from 'react';
import { ChevronDown, ChevronUp, User, Building2, Settings, Shield } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { PlatformAuditLogEntry } from '@/types/platform';

interface AuditLogEntryProps {
  entry: PlatformAuditLogEntry;
}

const actionIcons: Record<string, React.ElementType> = {
  'user.create': User,
  'user.update': User,
  'user.delete': User,
  'user.set_platform_manager': Shield,
  'user.revoke_platform_manager': Shield,
  'organization.create': Building2,
  'organization.update': Settings,
  'organization.update_status': Building2,
  'organization.soft_delete': Building2,
  'organization.restore': Building2,
  default: Settings,
};

const actionLabels: Record<string, string> = {
  'user.create': 'User Created',
  'user.update': 'User Updated',
  'user.delete': 'User Deleted',
  'user.set_platform_manager': 'Platform Manager Set',
  'user.revoke_platform_manager': 'Platform Manager Revoked',
  'organization.create': 'Organization Created',
  'organization.update': 'Organization Updated',
  'organization.update_status': 'Status Changed',
  'organization.soft_delete': 'Organization Deleted',
  'organization.restore': 'Organization Restored',
};

function formatDate(dateString: string): string {
  const date = new Date(dateString);
  return date.toLocaleString('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  });
}

function JsonDiff({ previous, current }: { previous: Record<string, unknown> | null; current: Record<string, unknown> | null }) {
  if (!previous && !current) {
    return <p className="text-sm text-muted-foreground italic">No changes recorded</p>;
  }

  const allKeys = new Set([
    ...Object.keys(previous || {}),
    ...Object.keys(current || {}),
  ]);

  return (
    <div className="space-y-1 text-sm font-mono">
      {Array.from(allKeys).map((key) => {
        const oldValue = previous?.[key];
        const newValue = current?.[key];
        const hasChanged = JSON.stringify(oldValue) !== JSON.stringify(newValue);

        return (
          <div key={key} className="grid grid-cols-3 gap-2">
            <span className="text-muted-foreground">{key}:</span>
            <span className={cn(
              'truncate',
              hasChanged && 'text-red-600 line-through'
            )}>
              {oldValue !== undefined ? String(oldValue) : '-'}
            </span>
            {hasChanged && (
              <span className="text-green-600 truncate">
                {newValue !== undefined ? String(newValue) : '-'}
              </span>
            )}
          </div>
        );
      })}
    </div>
  );
}

export function AuditLogEntry({ entry }: AuditLogEntryProps) {
  const [isExpanded, setIsExpanded] = useState(false);

  const Icon = actionIcons[entry.action] || actionIcons.default;
  const actionLabel = actionLabels[entry.action] || entry.action;
  const performedBy = entry.platform_manager;
  const organization = entry.target_organization;

  return (
    <div className="border rounded-lg overflow-hidden">
      <div
        className={cn(
          'flex items-center gap-3 p-3 cursor-pointer hover:bg-muted/50 transition-colors',
          isExpanded && 'bg-muted/50'
        )}
        onClick={() => setIsExpanded(!isExpanded)}
      >
        <div className="flex-shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
          <Icon className="h-4 w-4 text-primary" />
        </div>

        <div className="flex-1 min-w-0">
          <div className="flex items-center gap-2">
            <span className="font-medium text-sm">{actionLabel}</span>
            {entry.target_entity_type && entry.target_entity_id && (
              <span className="text-xs text-muted-foreground">
                {entry.target_entity_type} #{String(entry.target_entity_id).slice(-8)}
              </span>
            )}
          </div>
          <div className="text-xs text-muted-foreground">
            by {performedBy?.name || 'System'} • {formatDate(entry.created_at)}
          </div>
        </div>

        {organization && (
          <div className="hidden sm:flex items-center gap-1.5 text-xs text-muted-foreground">
            <Building2 className="h-3.5 w-3.5" />
            <span className="truncate max-w-[150px]">{organization.name}</span>
          </div>
        )}

        <Button variant="ghost" size="sm" className="flex-shrink-0">
          {isExpanded ? (
            <ChevronUp className="h-4 w-4" />
          ) : (
            <ChevronDown className="h-4 w-4" />
          )}
        </Button>
      </div>

      {isExpanded && (
        <div className="border-t bg-muted/30 px-3 py-3">
          {entry.reason && (
            <p className="text-sm mb-3">Reason: {entry.reason}</p>
          )}

          <div className="space-y-3">
            <div>
              <h4 className="text-xs font-semibold uppercase tracking-wider text-muted-foreground mb-2">
                Changes
              </h4>
              <div className="bg-background rounded border p-3">
                <JsonDiff
                  previous={entry.before_state}
                  current={entry.after_state}
                />
              </div>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
