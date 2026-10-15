/**
 * AuditLogEntry Component
 *
 * Displays a single audit log entry with details and JSON diff.
 */

import { useState } from 'react';
import { ChevronDown, ChevronUp, User, Building2, Settings, Shield, LogIn, LogOut } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { PlatformAuditLogEntry } from '@/types/platform';

interface AuditLogEntryProps {
  entry: PlatformAuditLogEntry;
}

// Exactly the action names the server writes (PlatformAuditService callers).
const actionIcons: Record<string, React.ElementType> = {
  'user.created': User,
  'user.updated': User,
  'user.deleted': User,
  'user.password.updated': User,
  'user.platform_manager.granted': Shield,
  'user.platform_manager.revoked': Shield,
  'organization.settings.updated': Settings,
  'organization.status.updated': Building2,
  'organization.deleted': Building2,
  'operate_as.started': LogIn,
  'operate_as.stopped': LogOut,
  default: Settings,
};

export const actionLabels: Record<string, string> = {
  'user.created': 'User Created',
  'user.updated': 'User Updated',
  'user.deleted': 'User Deleted',
  'user.password.updated': 'User Password Changed',
  'user.platform_manager.granted': 'Platform Manager Granted',
  'user.platform_manager.revoked': 'Platform Manager Revoked',
  'organization.settings.updated': 'Organization Settings Updated',
  'organization.status.updated': 'Organization Status Changed',
  'organization.deleted': 'Organization Deleted',
  'operate_as.started': 'Operate As Started',
  'operate_as.stopped': 'Operate As Stopped',
};

// Unknown (future) actions: "foo.bar_baz" -> "Foo Bar Baz" rather than the raw name.
const humanizeAction = (action: string) =>
  action.split(/[._]/).map((w) => w.charAt(0).toUpperCase() + w.slice(1)).join(' ');

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

function formatValue(value: unknown): string {
  if (value === null) return 'null';
  if (value === undefined) return '-';
  if (typeof value === 'object') {
    // Pretty print objects/arrays with indentation
    return JSON.stringify(value, null, 2);
  }
  return String(value);
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
    <div className="space-y-2 text-sm font-mono">
      {Array.from(allKeys).map((key) => {
        const oldValue = previous?.[key];
        const newValue = current?.[key];
        const hasChanged = JSON.stringify(oldValue) !== JSON.stringify(newValue);
        const isObject = typeof oldValue === 'object' || typeof newValue === 'object';

        if (isObject) {
          // For objects, show them in a more readable format
          return (
            <div key={key} className="border rounded-sm p-2 bg-muted/50">
              <div className="font-medium text-muted-foreground mb-1">{key}:</div>
              <div className="grid grid-cols-2 gap-2">
                <div className={cn(hasChanged && 'opacity-50')}>
                  <div className="text-xs text-muted-foreground mb-1">Before:</div>
                  <pre className="text-xs whitespace-pre-wrap break-all overflow-x-auto">
                    {formatValue(oldValue)}
                  </pre>
                </div>
                {hasChanged && (
                  <div>
                    <div className="text-xs text-muted-foreground mb-1">After:</div>
                    <pre className="text-xs whitespace-pre-wrap break-all overflow-x-auto text-green-600">
                      {formatValue(newValue)}
                    </pre>
                  </div>
                )}
              </div>
            </div>
          );
        }

        return (
          <div key={key} className="grid grid-cols-3 gap-2 items-center">
            <span className="text-muted-foreground">{key}:</span>
            <span className={cn(
              'truncate',
              hasChanged && 'text-red-600 line-through'
            )}>
              {formatValue(oldValue)}
            </span>
            {hasChanged && (
              <span className="text-green-600 truncate">
                {formatValue(newValue)}
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
  const actionLabel = actionLabels[entry.action] || humanizeAction(entry.action);
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
        <div className="shrink-0 w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
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

        <Button variant="ghost" size="sm" className="shrink-0">
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
              <div className="bg-background rounded-sm border p-3">
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
