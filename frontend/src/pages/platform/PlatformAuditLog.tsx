/**
 * Platform Audit Log Page
 *
 * View all platform management audit logs.
 */

import { useState, useEffect, useRef } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from '@/hooks/useAuth';
import {
  ScrollText,
  Search,
  X,
  RefreshCw,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent } from '@/components/ui/card';
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
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const { data, isLoading, isRefetching, refetch } = usePlatformAuditLogs({
    page: currentPage,
    per_page: perPage,
    platform_manager_user_id: platformManagerUserId || undefined,
    action: action === 'all' ? undefined : action,
  });

  const entries = data?.data || [];
  const totalEntries = data?.meta?.total || 0;
  const totalPages = Math.ceil(totalEntries / perPage);

  const hasActiveFilters = platformManagerUserId || action !== 'all';

  const clearFilters = () => {
    setPlatformManagerUserId('');
    setAction('all');
    setCurrentPage(1);
  };

  return (
    <PlatformLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <ScrollText className="h-8 w-8" />
              Audit Log
            </h1>
            <p className="text-muted-foreground mt-1">
              {totalEntries} entr{totalEntries !== 1 ? 'ies' : 'y'} recorded
            </p>
          </div>
        </div>

        {/* Filters */}
        <Card>
          <CardContent className="p-4">
            <div className="flex flex-wrap gap-3">
              {/* Search */}
              <div className="relative flex-1 min-w-[250px]">
                <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  placeholder="Search by platform manager user ID..."
                  value={platformManagerUserId}
                  onChange={(e) => {
                    setPlatformManagerUserId(e.target.value);
                    setCurrentPage(1);
                  }}
                  className="pl-9"
                  autoComplete="off"
                />
              </div>

              {/* Refresh Button */}
              <Button
                variant="outline"
                size="icon"
                onClick={() => refetch()}
                disabled={isRefetching}
                title="Refresh"
              >
                <RefreshCw className={cn('h-4 w-4', isRefetching && 'animate-spin')} />
              </Button>

              {/* Action Filter */}
              <Select
                value={action}
                onValueChange={(value) => {
                  setAction(value);
                  setCurrentPage(1);
                }}
              >
                <SelectTrigger className="w-[220px]">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {actionTypes.map((type) => (
                    <SelectItem key={type.value} value={type.value}>
                      {type.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>

              {/* Clear Filters */}
              {hasActiveFilters && (
                <Button variant="ghost" size="sm" onClick={clearFilters}>
                  <X className="h-4 w-4 mr-2" />
                  Clear Filters
                </Button>
              )}
            </div>
          </CardContent>
        </Card>

        {/* Audit Log Entries */}
        <Card>
          <CardContent className="pt-6">
            {isLoading ? (
              <div className="flex items-center justify-center h-32">
                <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
              </div>
            ) : !entries || entries.length === 0 ? (
              <div className="text-center py-12">
                <ScrollText className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                <h3 className="text-lg font-semibold mb-2">No audit entries found</h3>
                <p className="text-muted-foreground">
                  {hasActiveFilters ? 'Try adjusting your filters' : 'No activity recorded yet'}
                </p>
              </div>
            ) : (
              <div className="space-y-3">
                {entries.map((entry) => (
                  <AuditLogEntry key={entry.id} entry={entry} />
                ))}
              </div>
            )}

            {/* Pagination */}
            {totalPages > 1 && (
              <div className="flex items-center justify-between mt-4 pt-4 border-t">
                <div className="flex items-center gap-2">
                  <p className="text-sm text-muted-foreground">Rows per page:</p>
                  <Select
                    value={perPage.toString()}
                    onValueChange={(value) => {
                      setPerPage(parseInt(value));
                      setCurrentPage(1);
                    }}
                  >
                    <SelectTrigger className="w-[100px]">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="25">25</SelectItem>
                      <SelectItem value="50">50</SelectItem>
                      <SelectItem value="100">100</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div className="flex items-center gap-4">
                  <p className="text-sm text-muted-foreground">
                    Page {currentPage} of {totalPages}
                  </p>
                  <div className="flex gap-2">
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage - 1)}
                      disabled={currentPage === 1}
                    >
                      Previous
                    </Button>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => setCurrentPage(currentPage + 1)}
                      disabled={currentPage === totalPages}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              </div>
            )}
          </CardContent>
        </Card>
      </div>
    </PlatformLayout>
  );
}
