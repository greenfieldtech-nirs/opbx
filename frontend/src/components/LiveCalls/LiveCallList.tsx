/**
 * Live Call List Component
 *
 * Display all active calls with real-time updates
 */

import { useEffect, useState } from 'react';
import { LiveCallCard } from './LiveCallCard';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Activity, PhoneOff } from 'lucide-react';
import { useWebSocket } from '@/hooks/useWebSocket';
import type { LiveCall, CallPresenceUpdate } from '@/types/api.types';

interface LiveCallListProps {
  initialCalls?: LiveCall[];
}

export function LiveCallList({ initialCalls = [] }: LiveCallListProps) {
  const [activeCalls, setActiveCalls] = useState<LiveCall[]>(initialCalls);
  const { subscribe, isConnected } = useWebSocket();

  useEffect(() => {
    // Subscribe to call presence updates
    const unsubscribe = subscribe<CallPresenceUpdate>('call.presence', (update) => {
      if (update.event === 'call.initiated' || update.event === 'call.answered') {
        // Add or update call
        setActiveCalls((prev) => {
          const existingIndex = prev.findIndex((c) => c.call_id === update.call.call_id);
          if (existingIndex >= 0) {
            // Update existing call
            const updated = [...prev];
            updated[existingIndex] = update.call;
            return updated;
          } else {
            // Add new call
            return [...prev, update.call];
          }
        });
      } else if (update.event === 'call.ended') {
        // Remove call
        setActiveCalls((prev) => prev.filter((c) => c.call_id !== update.call.call_id));
      }
    });

    return () => {
      if (unsubscribe) unsubscribe();
    };
  }, [subscribe]);

  if (activeCalls.length === 0) {
    return (
      <Card>
        <CardContent className="flex flex-col items-center justify-center py-16">
          <div className="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100">
            <PhoneOff className="h-8 w-8 text-gray-400" />
          </div>
          <h3 className="mt-4 text-lg font-medium">No Active Calls</h3>
          <p className="mt-2 text-sm text-muted-foreground text-center max-w-md">
            All lines are currently available. Active calls will appear here in real-time.
          </p>
          {!isConnected && (
            <div className="mt-4 flex items-center gap-2 text-sm text-yellow-600">
              <div className="h-2 w-2 rounded-full bg-yellow-600 animate-pulse" />
              <span>Connecting to real-time updates...</span>
            </div>
          )}
        </CardContent>
      </Card>
    );
  }

  return (
    <div className="space-y-4">
      {/* Header */}
      <Card>
        <CardHeader className="pb-3">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <div className="flex h-8 w-8 items-center justify-center rounded-full bg-green-100">
                <Activity className="h-4 w-4 text-green-600" />
              </div>
              <div>
                <CardTitle className="text-base">
                  {activeCalls.length} Active {activeCalls.length === 1 ? 'Call' : 'Calls'}
                </CardTitle>
                <CardDescription className="text-xs">
                  {isConnected ? (
                    <span className="flex items-center gap-1.5">
                      <span className="h-1.5 w-1.5 rounded-full bg-green-500" />
                      Live updates enabled
                    </span>
                  ) : (
                    <span className="flex items-center gap-1.5">
                      <span className="h-1.5 w-1.5 rounded-full bg-yellow-500 animate-pulse" />
                      Connecting...
                    </span>
                  )}
                </CardDescription>
              </div>
            </div>
          </div>
        </CardHeader>
      </Card>

      {/* Call Cards */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {activeCalls.map((call) => (
          <LiveCallCard key={call.call_id} call={call} />
        ))}
      </div>
    </div>
  );
}
