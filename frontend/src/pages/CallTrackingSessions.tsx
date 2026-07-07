import { useState, useMemo, useEffect } from 'react';
import { Phone, Search, RefreshCw, CheckCircle, PhoneOff, X } from 'lucide-react';
import { format } from 'date-fns';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import {
  StandardDataTable,
  Column,
  EmptyState,
} from '@/components/design-system';
import { useAuth } from '@/hooks/useAuth';
import { useCallTrackingSessions } from '@/hooks/useCallTrackingSessions';
import { cn } from '@/lib/utils';
import type { CallTrackingSession } from '@/types/callTracking';

function formatDuration(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${s.toString().padStart(2, '0')}`;
}

export default function CallTrackingSessions() {
  const { user } = useAuth();
  const isReadOnly = ['reporter', 'pbx_user'].includes(user?.role || '');

  const today = useMemo(() => new Date(), []);
  const thirtyDaysAgo = useMemo(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d;
  }, []);

  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [startDate, setStartDate] = useState<string>(format(thirtyDaysAgo, 'yyyy-MM-dd'));
  const [endDate, setEndDate] = useState<string>(format(today, 'yyyy-MM-dd'));
  const [convertedOnly, setConvertedOnly] = useState(false);
  const [currentPage, setCurrentPage] = useState(1);
  const perPage = 25;

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  useEffect(() => {
    setCurrentPage(1);
  }, [startDate, endDate, convertedOnly]);

  const params = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      start_date: startDate,
      end_date: endDate,
      is_converted: convertedOnly || undefined,
      page: currentPage,
      per_page: perPage,
    }),
    [debouncedSearch, startDate, endDate, convertedOnly, currentPage]
  );

  const isDateRangeValid = startDate <= endDate;

  const { data, isLoading, isError, error, refetch, isRefetching } = useCallTrackingSessions(params, {
    enabled: isDateRangeValid,
  });

  const sessions = data?.data ?? [];
  const totalSessions = data?.meta?.total ?? 0;
  const totalPages = data?.meta?.last_page ?? 1;

  const hasActiveFilters =
    debouncedSearch !== '' ||
    convertedOnly ||
    startDate !== format(thirtyDaysAgo, 'yyyy-MM-dd') ||
    endDate !== format(today, 'yyyy-MM-dd');

  const clearFilters = () => {
    setSearchQuery('');
    setStartDate(format(thirtyDaysAgo, 'yyyy-MM-dd'));
    setEndDate(format(today, 'yyyy-MM-dd'));
    setConvertedOnly(false);
    setCurrentPage(1);
  };

  const columns: Column<CallTrackingSession>[] = [
    {
      header: 'Called Number',
      cell: (session) => (
        <span className="text-sm text-muted-foreground">{session.called_number}</span>
      ),
    },
    {
      header: 'Campaign',
      cell: (session) => (
        <span className="text-sm text-muted-foreground">
          {session.campaign_name || '—'}
        </span>
      ),
    },
    {
      header: 'Source / Medium',
      cell: (session) => (
        <span className="text-sm text-muted-foreground">
          {session.source || '—'} / {session.medium || '—'}
        </span>
      ),
    },
    {
      header: 'Duration',
      cell: (session) => (
        <span className="text-sm text-muted-foreground">{formatDuration(session.duration)}</span>
      ),
    },
    {
      header: 'Disposition',
      cell: (session) => (
        <Badge variant={session.is_answered ? 'default' : 'secondary'}>
          {session.is_answered ? 'Answered' : session.disposition}
        </Badge>
      ),
    },
    {
      header: 'Converted',
      cell: (session) => (
        session.is_converted ? (
          <div className="flex items-center gap-1 text-green-700">
            <CheckCircle className="h-4 w-4" />
            <span className="text-sm">Yes</span>
          </div>
        ) : (
          <div className="flex items-center gap-1 text-muted-foreground">
            <PhoneOff className="h-4 w-4" />
            <span className="text-sm">No</span>
          </div>
        )
      ),
    },
  ];

  if (isError) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-start">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-3xl font-bold flex items-center gap-2">
                <Phone className="h-8 w-8" />
                Call Tracking Sessions
              </h1>
            </div>
            <p className="text-muted-foreground mt-1">View attributed calls and conversions across campaigns</p>
          </div>
        </div>
        <Card>
          <CardContent className="p-6 text-center">
            <p className="text-red-600 mb-4">Failed to load sessions: {(error as Error)?.message || 'Unknown error'}</p>
            <Button onClick={() => refetch()}>Try Again</Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Phone className="h-8 w-8" />
              Call Tracking Sessions
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">View attributed calls and conversions across campaigns</p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Call Tracking Sessions</span>
          </div>
        </div>
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3 items-center">
            <div className="relative flex-1 min-w-[250px]">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search caller or called number..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
                autoComplete="off"
              />
            </div>

            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              disabled={isRefetching}
              title="Refresh"
            >
              <RefreshCw className={cn('h-4 w-4', isRefetching && 'animate-spin')} />
            </Button>

            <Input
              type="date"
              value={startDate}
              onChange={(e) => setStartDate(e.target.value)}
              className="w-[150px]"
            />
            <Input
              type="date"
              value={endDate}
              onChange={(e) => setEndDate(e.target.value)}
              className="w-[150px]"
            />

            <div className="flex items-center gap-2">
              <Switch
                id="converted-only"
                checked={convertedOnly}
                onCheckedChange={setConvertedOnly}
              />
              <Label htmlFor="converted-only" className="cursor-pointer">Converted Only</Label>
            </div>

            {hasActiveFilters && (
              <Button variant="ghost" size="sm" onClick={clearFilters}>
                <X className="h-4 w-4 mr-2" />
                Clear Filters
              </Button>
            )}
          </div>

          {!isDateRangeValid && (
            <p className="text-sm text-red-600 mt-3">Start date must be before or equal to end date.</p>
          )}
        </CardContent>
      </Card>

      {/* Table */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<CallTrackingSession>
            data={sessions}
            isLoading={isLoading}
            identityIcon={Phone}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(session) => session.caller_number}
            getIdentitySecondary={(session) => session.campaign_name || session.called_number}
            sortField={undefined}
            sortDirection={undefined}
            onSort={undefined}
            canView={false}
            canEdit={false}
            canDelete={false}
            columns={columns}
            emptyState={
              <EmptyState
                icon={Phone}
                title="No sessions found"
                description={hasActiveFilters ? 'Try adjusting your filters' : 'Sessions will appear once calls are tracked.'}
              />
            }
          />

          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalSessions)} of {totalSessions} sessions
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                  disabled={currentPage === 1}
                >
                  Previous
                </Button>
                <div className="text-sm">Page {currentPage} of {totalPages}</div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                  disabled={currentPage === totalPages}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
