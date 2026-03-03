/**
 * Platform Audit Log Page
 *
 * View all platform management audit logs.
 */

import { useState, useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from '@/hooks/useAuth';
import { ScrollText, Search, Filter } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { PlatformLayout } from '@/components/platform';
import { AuditLogEntry } from '@/components/platform';
import { usePlatformAuditLogs } from '@/hooks/platform';

const actionTypes = [
  { value: 'all', label: 'All Actions' },
  { value: 'user.create', label: 'User Created' },
  { value: 'user.update', label: 'User Updated' },
  { value: 'user.delete', label: 'User Deleted' },
  { value: 'user.set_platform_manager', label: 'Platform Manager Set' },
  { value: 'user.revoke_platform_manager', label: 'Platform Manager Revoked' },
  { value: 'organization.create', label: 'Organization Created' },
  { value: 'organization.update', label: 'Organization Updated' },
  { value: 'organization.update_status', label: 'Status Changed' },
  { value: 'organization.soft_delete', label: 'Organization Deleted' },
  { value: 'organization.restore', label: 'Organization Restored' },
];

export default function PlatformAuditLog() {
  const queryClient = useQueryClient();
  const { refreshUser } = useAuth();
  const hasRefreshed = useRef(false);

  // Refresh cache on mount (only once)
  useEffect(() => {
    if (hasRefreshed.current) return;
    hasRefreshed.current = true;

    // Clear platform-related queries to ensure fresh data
    queryClient.invalidateQueries({ queryKey: ['platform'] });
    // Refresh user data from server
    refreshUser();
  }, [queryClient, refreshUser]);

  const [platformManagerUserId, setPlatformManagerUserId] = useState('');
  const [action, setAction] = useState('all');

  const { data, isLoading } = usePlatformAuditLogs({
    platform_manager_user_id: platformManagerUserId || undefined,
    action: action === 'all' ? undefined : action,
  });

  return (
    <PlatformLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Audit Log</h1>
            <p className="text-muted-foreground">Track all platform management activities</p>
          </div>
        </div>

        {/* Filters */}
        <Card>
          <CardHeader>
            <div className="flex items-center gap-4">
              <div className="relative flex-1 max-w-sm">
                <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search by user ID..."
                  value={platformManagerUserId}
                  onChange={(e) => setPlatformManagerUserId(e.target.value)}
                  className="pl-9"
                />
              </div>
              <Select value={action} onValueChange={setAction}>
                <SelectTrigger className="w-[220px]">
                  <SelectValue placeholder="All Actions" />
                </SelectTrigger>
                <SelectContent>
                  {actionTypes.map((type) => (
                    <SelectItem key={type.value} value={type.value}>
                      {type.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
          </CardHeader>
          <CardContent>
            {isLoading ? (
              <div className="flex items-center justify-center h-32">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
              </div>
            ) : !data?.data || data.data.length === 0 ? (
              <div className="text-center py-12">
                <ScrollText className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No audit entries found</h3>
                <p className="text-muted-foreground">
                  {action !== 'all' || platformManagerUserId ? 'Try adjusting your filters' : 'No activity recorded yet'}
                </p>
              </div>
            ) : (
              <div className="space-y-3">
                {data.data.map((entry) => (
                  <AuditLogEntry key={entry.id} entry={entry} />
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </PlatformLayout>
  );
}
