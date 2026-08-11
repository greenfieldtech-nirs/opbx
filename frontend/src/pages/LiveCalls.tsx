/**
 * Live Calls Page
 *
 * Real-time active calls monitoring with configurable refresh rate
 * Falls back to HTTP polling since WebSocket updates are unreliable
 */

import { useCallback, useEffect, useRef, useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { sessionUpdatesService, getCoachTarget } from '@/services/sessionUpdates.service';
import { startCoach } from '@/components/WebPhone/webPhoneBus';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAuth } from '@/hooks/useAuth';
import { useCallPresence, formatCallDuration } from '@/hooks/useCallPresence';
import { useRefreshTimer } from '@/context/RefreshTimerContext';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Activity,
  PhoneCall,
  ArrowRightLeft,
  ArrowUpRight,
  ArrowDownLeft,
  PhoneOff,
  Headphones,
  Mic,
  Phone,
  Wifi,
  WifiOff,
  AlertTriangle,
  Loader2,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { StandardDataTable, EmptyState } from '@/components/design-system';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Progress } from '@/components/ui/progress';
import { AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
  Tooltip,
  TooltipContent,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { CallStatus, getCallStatusColor, getCallStatusLabel, LiveCallStatuses } from '@/types/call.types';
import type { ActiveCall as ApiActiveCall } from '@/types/api.types';
import { toast } from 'sonner';

/**
 * Refresh interval options in milliseconds
 */
const REFRESH_OPTIONS = [
  { value: '0', label: 'No Refresh', ms: 0 },
  { value: '1000', label: '1 Second', ms: 1000 },
  { value: '5000', label: '5 Seconds', ms: 5000 },
  { value: '15000', label: '15 Seconds', ms: 15000 },
  { value: '30000', label: '30 Seconds', ms: 30000 },
  { value: '60000', label: '60 Seconds', ms: 60000 },
] as const;

type RefreshInterval = typeof REFRESH_OPTIONS[number]['ms'];

/**
 * Combined call type that matches both API and WebSocket formats
 * Uses call_id as primary identifier (consistent across WebSocket events)
 */
interface LiveCall {
  id: string;
  call_id: string;
  session_id?: number;
  caller_id: string;
  destination: string;
  direction: string;
  status: string;
  session_created_at: string;
  duration_seconds: number;
  formatted_duration: string;
  user_full_name: string;
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

/**
 * Format refresh interval for display
 */
const formatRefreshInterval = (ms: number): string => {
  const option = REFRESH_OPTIONS.find(o => o.ms === ms);
  return option?.label || 'Custom';
};

export default function LiveCalls() {
  const { user: currentUser } = useAuth();
  const isSupervisor = currentUser?.role === 'supervisor';
  const isReadOnly = ['reporter', 'pbx_user', 'supervisor'].includes(currentUser?.role);
  const canUseLiveCallActions = ['owner', 'supervisor'].includes(currentUser?.role);
  const queryClient = useQueryClient();

  // Refresh interval state (default: 5 seconds)
  const [refreshInterval, setRefreshInterval] = useState<RefreshInterval>(5000);

  // WebSocket real-time call presence
  const { activeCalls: wsActiveCalls, isConnected: isWsConnected, connectionState, clearActiveCalls } = useCallPresence();

  // State for merged calls (initial HTTP + WebSocket updates)
  const [liveCalls, setLiveCalls] = useState<LiveCall[]>([]);

  // Track call IDs that were recently disconnected so they don't reappear
  // while Cloudonix is still processing the disconnection
  const [recentlyDisconnected, setRecentlyDisconnected] = useState<Set<string>>(new Set());
  const recentlyDisconnectedRef = useRef<Set<string>>(new Set());

  // Keep ref in sync with state
  useEffect(() => {
    recentlyDisconnectedRef.current = recentlyDisconnected;
  }, [recentlyDisconnected]);

  // Initial data fetch via HTTP with configurable refresh
  const { data: initialData, isLoading, isFetching, error, refetch } = useQuery({
    queryKey: ['active-calls', isSupervisor ? 'supervisor' : 'all'],
    queryFn: () => sessionUpdatesService.getActiveCalls({ supervisor: isSupervisor }),
    refetchInterval: refreshInterval === 0 ? false : refreshInterval,
    refetchIntervalInBackground: true,
    staleTime: 0, // Always consider data stale to enable refresh
  });

  // Transform initial HTTP data
  // Note: HTTP API uses session_id, but WebSocket uses call_id
  // We use call_ids from the API if available, otherwise fall back to session_id
  useEffect(() => {
    if (initialData?.data) {
      const disconnected = recentlyDisconnectedRef.current;
      const calls: LiveCall[] = initialData.data
        .filter((call: ApiActiveCall) => {
          // Filter out calls that were recently disconnected — the backend
          // may still report them as active while Cloudonix processes the
          // disconnection. We give it a grace period before showing them again.
          const callId = call.call_ids?.[0] || String(call.session_id);
          return !disconnected.has(callId);
        })
        .map((call: ApiActiveCall) => {
          // Use first call_id from array, or fall back to session_id as string
          const callId = call.call_ids?.[0] || String(call.session_id);
          return {
            id: callId,
            call_id: callId,
            session_id: call.session_id,
            caller_id: call.caller_id || 'Unknown Caller',
            destination: call.destination || 'Unknown',
            direction: call.direction || 'unknown',
            status: call.status,
            session_created_at: call.session_created_at,
            duration_seconds: call.duration_seconds || 0,
            formatted_duration: call.formatted_duration || '0s',
            user_full_name: call.user_full_name || 'Unassigned',
          };
        });
      setLiveCalls(calls);
    }
  }, [initialData]);

  // Merge WebSocket updates into live calls
  useEffect(() => {
    if (wsActiveCalls.length === 0) return;

    console.log('[LiveCalls] WebSocket calls received:', wsActiveCalls);

    const disconnected = recentlyDisconnectedRef.current;

    setLiveCalls((prevCalls) => {
      // Create a map of existing calls by call_id (consistent with WebSocket)
      const callsMap = new Map(prevCalls.map((c) => [c.call_id, c]));

      // Merge or add WebSocket calls, but skip recently disconnected ones
      wsActiveCalls.forEach((wsCall) => {
        // Skip calls that were recently disconnected — WebSocket may still
        // emit events for them while Cloudonix processes the disconnection
        if (disconnected.has(wsCall.call_id)) return;

        const existing = callsMap.get(wsCall.call_id);

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
            id: wsCall.call_id,
            call_id: wsCall.call_id,
            caller_id: wsCall.from_number || 'Unknown Caller',
            destination: wsCall.to_number || 'Unknown',
            direction: 'unknown',
            status: wsCall.status,
            session_created_at: wsCall.initiated_at,
            duration_seconds: wsCall.duration,
            formatted_duration: formatCallDuration(wsCall.duration),
            user_full_name: 'Unassigned',
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

  const handleCoach = useCallback(
    async (
      sessionId: number,
      policy: 'spy' | 'whisper' | 'barge',
      whisperParty?: 'caller' | 'callee' | 'both'
    ) => {
      try {
        const { data } = await getCoachTarget(sessionId, policy, whisperParty);
        startCoach(data.destination);
      } catch (error: any) {
        toast.error(
          error?.response?.data?.message ?? 'Unable to start monitoring this call.'
        );
      }
    },
    []
  );

  // Disconnect all calls state
  const [isDisconnectingAll, setIsDisconnectingAll] = useState(false);
  const [disconnectProgress, setDisconnectProgress] = useState(0);
  const [totalToDisconnect, setTotalToDisconnect] = useState(0);
  const [showConfirmDialog, setShowConfirmDialog] = useState(false);

  // Check for stale calls (WebSocket-only calls without session_id)
  const staleCallsCount = liveCalls.filter((call) => !call.session_id).length;

  const handleDisconnect = (sessionId: number) => {
    if (confirm('Are you sure you want to disconnect this call?')) {
      // Mark the call as recently disconnected so it doesn't reappear
      // while Cloudonix processes the disconnection
      const call = liveCalls.find((c) => c.session_id === sessionId);
      if (call) {
        const newDisconnected = new Set(recentlyDisconnectedRef.current);
        newDisconnected.add(call.call_id);
        setRecentlyDisconnected(newDisconnected);
        recentlyDisconnectedRef.current = newDisconnected;

        // Also optimistically remove from local state
        setLiveCalls((prev) => prev.filter((c) => c.session_id !== sessionId));
      }

      disconnectMutation.mutate(sessionId);
    }
  };

  const handleDisconnectAll = async () => {
    const callsWithSessionId = liveCalls.filter((call) => call.session_id);
    const totalCalls = liveCalls.length;

    if (totalCalls === 0) {
      toast.info('No active calls to disconnect');
      setShowConfirmDialog(false);
      return;
    }

    setShowConfirmDialog(false);
    setIsDisconnectingAll(true);
    setTotalToDisconnect(callsWithSessionId.length);
    setDisconnectProgress(0);

    const sessionIds = callsWithSessionId.map((call) => call.session_id!);
    let completed = 0;
    let failed = 0;

    // Disconnect calls sequentially to avoid overwhelming the server
    for (const sessionId of sessionIds) {
      try {
        await sessionUpdatesService.disconnectSession(sessionId);
        completed++;
      } catch (error) {
        failed++;
        console.error(`Failed to disconnect session ${sessionId}:`, error);
      }
      setDisconnectProgress(completed);
      // Small delay between requests
      await new Promise((resolve) => setTimeout(resolve, 200));
    }

    // IMPORTANT: Mark all calls as recently disconnected so they don't
    // reappear while Cloudonix is still processing the disconnection.
    // The backend and WebSocket may continue to report them as active
    // for a few seconds — we filter them out during the grace period.
    const allCallIds = liveCalls.map((call) => call.call_id);
    const newDisconnected = new Set(recentlyDisconnectedRef.current);
    allCallIds.forEach((id) => newDisconnected.add(id));
    setRecentlyDisconnected(newDisconnected);
    recentlyDisconnectedRef.current = newDisconnected;

    // Clear local state and query cache immediately for instant feedback
    setLiveCalls([]);
    clearActiveCalls();
    queryClient.setQueryData(['active-calls', isSupervisor ? 'supervisor' : 'all'], { data: [] });

    // Remove call IDs from the recently-disconnected set after a grace
    // period so that genuinely new calls with the same ID can still appear.
    setTimeout(() => {
      setRecentlyDisconnected((prev) => {
        const next = new Set(prev);
        allCallIds.forEach((id) => next.delete(id));
        recentlyDisconnectedRef.current = next;
        return next;
      });
    }, 10000);

    // Small delay so the user sees the progress bar hit 100%
    await new Promise((resolve) => setTimeout(resolve, 500));

    setIsDisconnectingAll(false);

    const disconnectedCount = sessionIds.length;
    const skippedCount = totalCalls - disconnectedCount;

    if (failed > 0) {
      toast.warning(`Disconnected ${completed} calls, ${failed} failed`);
    } else if (skippedCount > 0) {
      toast.success(`Disconnected ${completed} calls. ${skippedCount} stale records cleared.`);
    } else {
      toast.success(`All ${completed} calls disconnected successfully`);
    }
  };

  // Calculate statistics
  const stats = {
    total: liveCalls.length,
    processing: liveCalls.filter((c) => c.status === CallStatus.PROCESSING).length,
    ringing: liveCalls.filter((c) => c.status === CallStatus.RINGING).length,
    connected: liveCalls.filter((c) => c.status === CallStatus.CONNECTED).length,
  };

  const handleRefresh = useCallback(() => refetch(), [refetch]);

  // Register refresh timer with AppLayout bar
  useRefreshTimer(refreshInterval, isFetching);

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
            Real-time active call monitoring
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Live Calls</span>
          </div>
        </div>
        <div className="flex items-center gap-4">
          {/* Disconnect All Calls Button */}
          {!isReadOnly && liveCalls.length > 0 && (
            <AlertDialog open={showConfirmDialog} onOpenChange={setShowConfirmDialog}>
              <AlertDialogTrigger asChild>
                <Button
                  variant="destructive"
                  size="sm"
                  className="gap-2 bg-red-600 hover:bg-red-700"
                  disabled={isDisconnectingAll}
                >
                  <PhoneOff className="h-4 w-4" />
                  Disconnect All Calls
                </Button>
              </AlertDialogTrigger>
              <AlertDialogContent>
                <AlertDialogHeader>
                  <AlertDialogTitle className="flex items-center gap-2 text-red-600">
                    <AlertTriangle className="h-5 w-5" />
                    Dangerous Operation
                  </AlertDialogTitle>
                  <AlertDialogDescription className="space-y-2">
                    <p>
                      Are you sure you want to disconnect all <strong>{liveCalls.length}</strong> active calls?
                    </p>
                    {staleCallsCount > 0 && (
                      <p className="text-yellow-600 text-sm">
                        Note: {staleCallsCount} call{staleCallsCount > 1 ? 's' : ''} without session ID will be cleared from display only.
                      </p>
                    )}
                    <p className="text-red-600 font-medium">
                      This action cannot be undone. All ongoing calls will be forcibly terminated.
                    </p>
                  </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                  <AlertDialogCancel>Cancel</AlertDialogCancel>
                  <AlertDialogAction
                    onClick={handleDisconnectAll}
                    className="bg-red-600 hover:bg-red-700 text-white"
                  >
                    Yes, Disconnect All
                  </AlertDialogAction>
                </AlertDialogFooter>
              </AlertDialogContent>
            </AlertDialog>
          )}

          {/* Clear Stale Records Button */}
          {!isReadOnly && staleCallsCount > 0 && (
            <Button
              variant="outline"
              size="sm"
              className="gap-2 border-yellow-500 text-yellow-600 hover:bg-yellow-50"
              onClick={() => {
                if (confirm(`Clear ${staleCallsCount} stale record(s) without session ID from display?`)) {
                  setLiveCalls((prev) => prev.filter((call) => call.session_id));
                  clearActiveCalls();
                  toast.info(`Cleared ${staleCallsCount} stale record(s)`);
                }
              }}
            >
              <AlertTriangle className="h-4 w-4" />
              Clear Stale ({staleCallsCount})
            </Button>
          )}

          {/* Refresh Rate Selector */}
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">Refresh:</span>
            <Select
              value={String(refreshInterval)}
              onValueChange={(value) => setRefreshInterval(Number(value) as RefreshInterval)}
            >
              <SelectTrigger className="w-[140px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                {REFRESH_OPTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
          </div>

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

      {/* Disconnect All Overlay */}
      {isDisconnectingAll && (
        <div className="fixed inset-0 z-50 bg-black/60 flex items-center justify-center">
          <div className="bg-background rounded-lg shadow-lg p-8 max-w-md w-full mx-4 space-y-6">
            <div className="text-center space-y-2">
              <Loader2 className="h-12 w-12 animate-spin text-red-600 mx-auto" />
              <h3 className="text-lg font-semibold">Disconnecting All Calls</h3>
              <p className="text-muted-foreground">
                Please wait while all active calls are being terminated...
              </p>
            </div>

            <div className="space-y-2">
              <div className="flex justify-between text-sm">
                <span>Progress</span>
                <span className="font-medium">
                  {disconnectProgress} of {totalToDisconnect}
                </span>
              </div>
              <Progress
                value={(disconnectProgress / totalToDisconnect) * 100}
                className="h-3"
              />
              <p className="text-xs text-muted-foreground text-center">
                {Math.round((disconnectProgress / totalToDisconnect) * 100)}% complete
              </p>
            </div>

            <div className="text-center text-sm text-muted-foreground">
              <span className="inline-flex items-center gap-2">
                <span className="h-2 w-2 rounded-full bg-red-500 animate-pulse" />
                Do not close or refresh this page
              </span>
            </div>
          </div>
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
      ) : liveCalls.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center">
            <PhoneCall className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
            <p className="text-muted-foreground">No active calls at the moment</p>
            <p className="text-sm text-muted-foreground mt-2">
              {refreshInterval > 0
                ? `Refreshing every ${formatRefreshInterval(refreshInterval).toLowerCase()}`
                : 'Auto-refresh disabled'}
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
                  header: 'User',
                  accessorKey: 'user_full_name',
                  cell: (call) => (
                    <span className={cn(
                      'text-sm',
                      call.user_full_name === 'Unassigned' && 'text-muted-foreground italic'
                    )}>
                      {call.user_full_name}
                    </span>
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
                      {call.session_id || 'N/A'}
                    </span>
                  ),
                },
                ...(canUseLiveCallActions
                  ? [
                      {
                        header: 'Actions',
                        cell: (call: LiveCall) =>
                          call.session_id ? (
                            <div className="flex items-center gap-1">
                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-8 w-8"
                                    aria-label="Spy"
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleCoach(call.session_id!, 'spy');
                                    }}
                                  >
                                    <Headphones className="h-4 w-4" />
                                  </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                  <p>Spy</p>
                                </TooltipContent>
                              </Tooltip>

                              <DropdownMenu>
                                <Tooltip>
                                  <TooltipTrigger asChild>
                                    <DropdownMenuTrigger asChild>
                                      <Button
                                        variant="ghost"
                                        size="icon"
                                        className="h-8 w-8"
                                        aria-label="Whisper"
                                        onClick={(e) => e.stopPropagation()}
                                      >
                                        <Mic className="h-4 w-4" />
                                      </Button>
                                    </DropdownMenuTrigger>
                                  </TooltipTrigger>
                                  <TooltipContent>
                                    <p>Whisper</p>
                                  </TooltipContent>
                                </Tooltip>
                                <DropdownMenuContent align="end">
                                  <DropdownMenuItem
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleCoach(call.session_id!, 'whisper', 'caller');
                                    }}
                                  >
                                    Whisper to caller
                                  </DropdownMenuItem>
                                  <DropdownMenuItem
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleCoach(call.session_id!, 'whisper', 'callee');
                                    }}
                                  >
                                     Whisper to callee
                                   </DropdownMenuItem>
                                 </DropdownMenuContent>
                              </DropdownMenu>

                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-8 w-8"
                                    aria-label="Barge"
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleCoach(call.session_id!, 'barge');
                                    }}
                                  >
                                    <Phone className="h-4 w-4" />
                                  </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                  <p>Barge</p>
                                </TooltipContent>
                              </Tooltip>

                              <Tooltip>
                                <TooltipTrigger asChild>
                                  <Button
                                    variant="ghost"
                                    size="icon"
                                    className="h-8 w-8 text-destructive hover:text-destructive hover:bg-destructive/10"
                                    aria-label="Disconnect"
                                    disabled={disconnectMutation.isPending}
                                    onClick={(e) => {
                                      e.stopPropagation();
                                      handleDisconnect(call.session_id!);
                                    }}
                                  >
                                    <PhoneOff className="h-4 w-4" />
                                  </Button>
                                </TooltipTrigger>
                                <TooltipContent>
                                  <p>Disconnect</p>
                                </TooltipContent>
                              </Tooltip>
                            </div>
                          ) : (
                            <span className="text-xs text-muted-foreground">-</span>
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
