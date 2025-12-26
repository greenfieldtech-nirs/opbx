/**
 * Live Calls Page
 *
 * Real-time call presence display with WebSocket updates
 */

import { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { callLogsService } from '@/services/callLogs.service';
import { useWebSocket } from '@/hooks/useWebSocket';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Activity, PhoneCall } from 'lucide-react';
import { formatPhoneNumber, formatDuration, getStatusColor } from '@/utils/formatters';
import { cn } from '@/lib/utils';
import type { LiveCall, CallPresenceUpdate } from '@/types/api.types';

export default function LiveCalls() {
  const [liveCalls, setLiveCalls] = useState<LiveCall[]>([]);
  const [lastUpdate, setLastUpdate] = useState<Date>(new Date());

  // Fetch initial active calls
  const { data: initialCalls } = useQuery({
    queryKey: ['active-calls'],
    queryFn: () => callLogsService.getActiveCalls(),
    refetchInterval: 5000, // Fallback polling every 5 seconds
  });

  // Set initial calls
  useEffect(() => {
    if (initialCalls) {
      setLiveCalls(initialCalls);
    }
  }, [initialCalls]);

  // Subscribe to WebSocket updates
  useWebSocket(
    '*', // Listen to all call events
    (data: CallPresenceUpdate) => {
      setLastUpdate(new Date());

      if (data.event === 'call.initiated') {
        // Add new call
        setLiveCalls((prev) => [...prev, data.call]);
      } else if (data.event === 'call.answered') {
        // Update call status
        setLiveCalls((prev) =>
          prev.map((call) =>
            call.call_id === data.call.call_id ? { ...call, status: 'answered' } : call
          )
        );
      } else if (data.event === 'call.ended') {
        // Remove ended call
        setLiveCalls((prev) => prev.filter((call) => call.call_id !== data.call.call_id));
      }
    }
  );

  // Update call durations every second
  useEffect(() => {
    const interval = setInterval(() => {
      setLiveCalls((prev) =>
        prev.map((call) => ({
          ...call,
          duration: Math.floor((Date.now() - new Date(call.started_at).getTime()) / 1000),
        }))
      );
    }, 1000);

    return () => clearInterval(interval);
  }, []);

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">Live Calls</h1>
          <p className="text-muted-foreground">Real-time active call monitoring</p>
        </div>
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <Activity className="h-4 w-4 animate-pulse text-green-500" />
          Last update: {lastUpdate.toLocaleTimeString()}
        </div>
      </div>

      {/* Active Calls Count */}
      <Card>
        <CardHeader>
          <CardTitle className="flex items-center gap-2">
            <Activity className="h-5 w-5 text-green-600" />
            Active Calls: {liveCalls.length}
          </CardTitle>
          <CardDescription>Currently in progress</CardDescription>
        </CardHeader>
      </Card>

      {/* Live Calls List */}
      {liveCalls.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center">
            <PhoneCall className="h-12 w-12 text-muted-foreground mx-auto mb-4" />
            <p className="text-muted-foreground">No active calls at the moment</p>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4 md:grid-cols-2">
          {liveCalls.map((call) => (
            <Card key={call.call_id}>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-full bg-green-100 flex items-center justify-center">
                      <PhoneCall className="h-5 w-5 text-green-600" />
                    </div>
                    <div>
                      <CardTitle className="text-lg">
                        {formatPhoneNumber(call.from_number)}
                      </CardTitle>
                      <CardDescription>
                        To: {formatPhoneNumber(call.to_number)}
                      </CardDescription>
                    </div>
                  </div>
                  <span
                    className={cn(
                      'px-2 py-1 rounded-full text-xs font-medium',
                      getStatusColor(call.status)
                    )}
                  >
                    {call.status}
                  </span>
                </div>
              </CardHeader>
              <CardContent>
                <div className="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <p className="text-muted-foreground">DID</p>
                    <p className="font-medium">{call.did_number || 'N/A'}</p>
                  </div>
                  <div>
                    <p className="text-muted-foreground">Extension</p>
                    <p className="font-medium">{call.extension_number || 'N/A'}</p>
                  </div>
                  <div>
                    <p className="text-muted-foreground">Duration</p>
                    <p className="font-medium font-mono">{formatDuration(call.duration)}</p>
                  </div>
                  <div>
                    <p className="text-muted-foreground">Started</p>
                    <p className="font-medium">{new Date(call.started_at).toLocaleTimeString()}</p>
                  </div>
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
