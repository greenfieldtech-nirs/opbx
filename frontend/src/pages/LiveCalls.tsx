/**
 * Live Calls Page
 *
 * Real-time active calls monitoring using session-updates API
 */

import { useQuery } from '@tanstack/react-query';
import { sessionUpdatesService } from '@/services/sessionUpdates.service';
import { useAuth } from '@/hooks/useAuth';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Activity, PhoneCall, Clock, ArrowRightLeft, ArrowUpRight, ArrowDownLeft } from 'lucide-react';
import { cn } from '@/lib/utils';
import { StandardDataTable, EmptyState } from '@/components/design-system';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { ActiveCall } from '@/types/api.types';

/**
 * Get status color for call status badges
 */
const getStatusColor = (status: string) => {
  switch (status) {
    case 'processing':
      return 'bg-blue-100 text-blue-800 border-blue-200';
    case 'ringing':
      return 'bg-yellow-100 text-yellow-800 border-yellow-200';
    case 'connected':
      return 'bg-green-100 text-green-800 border-green-200';
    default:
      return 'bg-gray-100 text-gray-800 border-gray-200';
  }
};

/**
 * Get direction icon
 */
const getDirectionIcon = (direction: string | null) => {
  switch (direction) {
    case 'outgoing':
      return <ArrowUpRight className="h-4 w-4 text-blue-600" />;
    case 'incoming':
      return <ArrowDownLeft className="h-4 w-4 text-green-600" />;
    default:
      return <ArrowRightLeft className="h-4 w-4 text-gray-600" />;
  }
};

export default function LiveCalls() {
  const { user: currentUser } = useAuth();
  const isReadOnly = ['reporter', 'pbx_user'].includes(currentUser?.role);

  // Fetch active calls with polling every 5 seconds (not rate limited)
  const { data: activeCallsResponse, isLoading, error, refetch } = useQuery({
    queryKey: ['active-calls'],
    queryFn: () => sessionUpdatesService.getActiveCalls(),
    refetchInterval: 5000, // Poll every 5 seconds
    staleTime: 2000, // Consider data fresh for 2 seconds
  });

  const activeCalls = ((activeCallsResponse?.data as ActiveCall[]) || []).map(call => ({
    ...call,
    id: call.session_id
  }));
  const meta = activeCallsResponse?.meta;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Activity className="h-8 w-8 text-blue-600" />
              Live Calls
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">
            Real-time active call monitoring using session updates
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Live Calls</span>
          </div>
        </div>
        <div className="flex items-center gap-4">
          <div className="flex items-center gap-2 text-sm text-muted-foreground">
            <Activity className="h-4 w-4 animate-pulse text-green-500" />
            Auto-refresh: 5s
          </div>
          {meta && (
            <div className="text-sm text-muted-foreground">
              Last updated: {new Date(meta.last_updated).toLocaleTimeString()}
            </div>
          )}
        </div>
      </div>

      {/* Statistics Cards */}
      {meta && (
        <div className="grid gap-4 md:grid-cols-4">
          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Total Active
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold">{meta.total_active_calls}</div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Processing
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-blue-600">
                {meta.by_status.processing}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Ringing
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-yellow-600">
                {meta.by_status.ringing}
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader className="pb-3">
              <CardTitle className="text-sm font-medium text-muted-foreground">
                Connected
              </CardTitle>
            </CardHeader>
            <CardContent>
              <div className="text-2xl font-bold text-green-600">
                {meta.by_status.connected}
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Active Calls List */}
      {isLoading ? (
        <Card>
          <CardContent className="p-12 text-center">
            <Activity className="h-12 w-12 text-muted-foreground mx-auto mb-4 animate-spin" />
            <p className="text-muted-foreground">Loading active calls...</p>
          </CardContent>
        </Card>
      ) : error ? (
        <Card>
          <CardContent className="p-12 text-center">
            <div className="text-red-500 mb-4">⚠️ Error loading active calls</div>
            <p className="text-muted-foreground text-sm">
              {error instanceof Error ? error.message : 'Unknown error'}
            </p>
          </CardContent>
        </Card>
      ) : activeCalls.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center">
            <PhoneCall className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
            <p className="text-muted-foreground">No active calls at the moment</p>
            <p className="text-sm text-muted-foreground mt-2">
              Calls will appear here automatically when they become active
            </p>
          </CardContent>
        </Card>
      ) : (
        <>
          {/* Active Calls Table */}
          <Card>
            <CardContent className="pt-6">
              <StandardDataTable<ActiveCall & { id: string | number }>
                data={activeCalls as (ActiveCall & { id: string | number })[]}
                isLoading={isLoading}
                identityIcon={PhoneCall}
                identityIconBg="bg-blue-100"
                identityIconColor="text-blue-600"
                getIdentityPrimary={(call) => call.caller_id || 'Unknown Caller'}
                getIdentitySecondary={(call) => `To: ${call.destination || 'Unknown'}`}
                columns={[
                  {
                    header: 'Direction',
                    cell: (call) => (
                      <div className="flex items-center gap-2">
                        {getDirectionIcon(call.direction)}
                        <span className="capitalize text-sm">{call.direction}</span>
                      </div>
                    )
                  },
                  {
                    header: 'Status',
                    accessorKey: 'status' as any,
                    cell: (call) => (
                      <Badge
                        variant="outline"
                        className={cn('px-3 py-1', getStatusColor(call.status))}
                      >
                        {call.status}
                      </Badge>
                    )
                  },
                  {
                    header: 'Duration',
                    accessorKey: 'formatted_duration' as any,
                    cell: (call) => (
                      <span className="font-mono font-medium">{call.formatted_duration}</span>
                    )
                  },
                  {
                    header: 'Started',
                    accessorKey: 'session_created_at' as any,
                    cell: (call) => new Date(call.session_created_at).toLocaleTimeString()
                  },
                  {
                    header: 'Session ID',
                    accessorKey: 'session_id' as any,
                    cell: (call) => (
                      <span className="font-mono text-xs text-muted-foreground">{call.session_id}</span>
                    )
                  }
                ]}
                emptyState={
                  <EmptyState
                    icon={PhoneCall}
                    title="No active calls at the moment"
                    description="Calls will appear here automatically when they become active"
                  />
                }
              />
            </CardContent>
          </Card>

          {/* Manual Refresh Button */}
          <div className="flex justify-center">
            <Button
              onClick={() => refetch()}
              disabled={isLoading}
              className="px-4 py-2"
            >
              {isLoading ? 'Refreshing...' : 'Refresh Now'}
            </Button>
          </div>
        </>
      )}
    </div>
  );
}
