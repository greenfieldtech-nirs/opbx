/**
 * Live Calls Page
 *
 * Real-time active calls monitoring using WebSocket (Laravel Echo)
 * Initial data loaded via HTTP, updates received via WebSocket
 */

import { useEffect, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { sessionUpdatesService } from '@/services/sessionUpdates.service';
import { useAuth } from '@/hooks/useAuth';
import { useCallPresence, formatCallDuration } from '@/hooks/useCallPresence';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Activity,
  PhoneCall,
  ArrowRightLeft,
  ArrowUpRight,
  ArrowDownLeft,
  PhoneOff,
  Wifi,
  WifiOff,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { StandardDataTable, EmptyState } from '@/components/design-system';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CallStatus, getCallStatusColor, getCallStatusLabel, LiveCallStatuses } from '@/types/call.types';
import type { ActiveCall as ApiActiveCall } from '@/types/api.types';
import { toast } from 'sonner';

/**
 * Combined call type that matches both API and WebSocket formats
 */
interface LiveCall {
  id: string | number;
  session_id: number;
  caller_id: string;
  destination: string;
  direction: string;
  status: string;
  session_created_at: string;
  duration_seconds: number;
  formatted_duration: string;
}

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
  const queryClient = useQueryClient();

  // WebSocket real-time call presence
  const { activeCalls: wsActiveCalls, isConnected: isWsConnected, connectionState } = useCallPresence();

  // State for merged calls (initial HTTP + WebSocket updates)
  const [liveCalls, setLiveCalls] = useState<LiveCall[]>([]);

  // Initial data fetch via HTTP (for immediate display on page load)
  const { data: initialData, isLoading, error } = useQuery({
    queryKey: ['active-calls'],
    queryFn: () => sessionUpdatesService.getActiveCalls(),
    staleTime: Infinity, // Don't refetch, we use WebSocket for updates
  });

  // Transform initial HTTP data
  useEffect(() => {
    if (initialData?.data) {
      const calls: LiveCall[] = initialData.data.map((call: ApiActiveCall) => ({
        id: call.session_id,
        session_id: call.session_id,
        caller_id: call.caller_id || 'Unknown Caller',
        destination: call.destination || 'Unknown',
        direction: call.direction || 'unknown',
        status: call.status,
        session_created_at: call.session_created_at,
        duration_seconds: call.duration_seconds || 0,
        formatted_duration: call.formatted_duration || '0s',
      }));
      setLiveCalls(calls);
    }
  }, [initialData]);

  // Merge WebSocket updates into live calls
  useEffect(() => {
    if (wsActiveCalls.length === 0) return;

    setLiveCalls((prevCalls) => {
      // Create a map of existing calls by ID
      const callsMap = new Map(prevCalls.map((c) => [String(c.session_id), c]));

      // Merge or add WebSocket calls
      wsActiveCalls.forEach((wsCall) => {
        const existing = callsMap.get(wsCall.call_id);
        const sessionId = parseInt(wsCall.call_id, 10);

        if (existing) {
          // Update existing call
          callsMap.set(wsCall.call_id, {
            ...existing,
            status: wsCall.status,
            duration_seconds: wsCall.duration,
            formatted_duration: formatCallDuration(wsCall.duration),
          });
        } else {
          // Add new call from WebSocket
          callsMap.set(wsCall.call_id, {
            id: sessionId,
            session_id: sessionId,
            caller_id: wsCall.from_number || 'Unknown Caller',
            destination: wsCall.to_number || 'Unknown',
            direction: 'unknown',
            status: wsCall.status,
            session_created_at: wsCall.initiated_at,
            duration_seconds: wsCall.duration,
            formatted_duration: formatCallDuration(wsCall.duration),
          });
        }
      });

      // Filter out terminal state calls (WebSocket sends ended events but they might linger briefly)
      return Array.from(callsMap.values()).filter((call) =>
        LiveCallStatuses.includes(call.status as CallStatus)
      );
    });
  }, [wsActiveCalls]);

  // Disconnect session mutation
  const disconnectMutation = useMutation({
    mutationFn: (sessionId: number) => sessionUpdatesService.disconnectSession(sessionId),
    onSuccess: () => {
      toast.success('Session disconnected successfully');
      queryClient.invalidateQueries({ queryKey: ['active-calls'] });
    },
    onError: (error: any) => {
      const errorMessage =
        error?.response?.data?.message || error?.message || 'Failed to disconnect session';
      toast.error(errorMessage);
    },
  });

  const handleDisconnect = (sessionId: number) => {
    if (confirm('Are you sure you want to disconnect this call?')) {
      disconnectMutation.mutate(sessionId);
    }
  };

  // Calculate statistics
  const stats = {
    total: liveCalls.length,
    processing: liveCalls.filter((c) => c.status === CallStatus.PROCESSING).length,
    ringing: liveCalls.filter((c) => c.status === CallStatus.RINGING).length,
    connected: liveCalls.filter((c) => c.status === CallStatus.CONNECTED).length,
  };

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
            Real-time active call monitoring with WebSocket updates
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Live Calls</span>
          </div>
        </div>
        <div className="flex items-center gap-4">
          {/* WebSocket Connection Status */}
          <div
            className={cn(
              'flex items-center gap-2 text-sm px-3 py-1.5 rounded-full border',
              isWsConnected
                ? 'bg-green-50 text-green-700 border-green-200'
                : 'bg-yellow-50 text-yellow-700 border-yellow-200'
            )}
          >
            {isWsConnected ? (
              <>
                <Wifi className="h-4 w-4" />
                <span className="flex items-center gap-1.5">
                  <span className="h-1.5 w-1.5 rounded-full bg-green-500 animate-pulse" />
                  Live
                </span>
              </>
            ) : (
              <>
                <WifiOff className="h-4 w-4" />
                <span className="capitalize">{connectionState}</span>
              </>
            )}
          </div>
        </div>
      </div>

      {/* Statistics Cards */}
      <div className="grid gap-4 md:grid-cols-4">
        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-medium text-muted-foreground">Total Active</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{stats.total}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-medium text-muted-foreground">Processing</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-blue-600">{stats.processing}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-medium text-muted-foreground">Ringing</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-yellow-600">{stats.ringing}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="pb-3">
            <CardTitle className="text-sm font-medium text-muted-foreground">Connected</CardTitle>
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-green-600">{stats.connected}</div>
          </CardContent>
        </Card>
      </div>

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
      ) : liveCalls.length === 0 ? (
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
        <Card>
          <CardContent className="pt-6">
            <StandardDataTable<LiveCall>
              data={liveCalls}
              isLoading={false}
              identityIcon={PhoneCall}
              identityIconBg="bg-blue-100"
              identityIconColor="text-blue-600"
              getIdentityPrimary={(call) => call.caller_id}
              getIdentitySecondary={(call) => `To: ${call.destination}`}
              columns={[
                {
                  header: 'Direction',
                  cell: (call) => (
                    <div className="flex items-center gap-2">
                      {getDirectionIcon(call.direction)}
                      <span className="capitalize text-sm">{call.direction}</span>
                    </div>
                  ),
                },
                {
                  header: 'Status',
                  accessorKey: 'status',
                  cell: (call) => (
                    <Badge
                      variant="outline"
                      className={cn('px-3 py-1', getCallStatusColor(call.status))}
                    >
                      {getCallStatusLabel(call.status)}
                    </Badge>
                  ),
                },
                {
                  header: 'Duration',
                  accessorKey: 'duration_seconds',
                  cell: (call) => (
                    <span className="font-mono font-medium">{call.formatted_duration}</span>
                  ),
                },
                {
                  header: 'Started',
                  accessorKey: 'session_created_at',
                  cell: (call) => new Date(call.session_created_at).toLocaleTimeString(),
                },
                {
                  header: 'Session ID',
                  accessorKey: 'session_id',
                  cell: (call) => (
                    <span className="font-mono text-xs text-muted-foreground">
                      {call.session_id}
                    </span>
                  ),
                },
                ...(!isReadOnly
                  ? [
                      {
                        header: 'Actions',
                        cell: (call: LiveCall) => (
                          <Button
                            variant="destructive"
                            size="sm"
                            onClick={(e) => {
                              e.stopPropagation();
                              handleDisconnect(call.session_id);
                            }}
                            disabled={disconnectMutation.isPending}
                            className="gap-2"
                          >
                            <PhoneOff className="h-4 w-4" />
                            Disconnect
                          </Button>
                        ),
                      },
                    ]
                  : []),
              ]}
              canView={false}
              canEdit={false}
              canDelete={false}
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
      )}
    </div>
  );
}
