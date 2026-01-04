/**
 * Live Calls Page
 *
 * Real-time active calls monitoring using session-updates API
 */

import { useState, useEffect } from 'react';
import { useQuery } from '@tanstack/react-query';
import { sessionUpdatesService } from '@/services/sessionUpdates.service';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Activity, PhoneCall, Clock, ArrowRightLeft, ArrowUpRight, ArrowDownLeft } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { ActiveCall, ActiveCallsResponse } from '@/types/api.types';

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
   // Fetch active calls with polling every 5 seconds (not rate limited)
   const { data: activeCallsResponse, isLoading, error, refetch } = useQuery({
     queryKey: ['active-calls'],
     queryFn: () => sessionUpdatesService.getActiveCalls(),
     refetchInterval: 5000, // Poll every 5 seconds
     staleTime: 2000, // Consider data fresh for 2 seconds
   });

  const activeCalls = activeCallsResponse?.data || [];
  const meta = activeCallsResponse?.meta;

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Activity className="h-8 w-8 text-blue-600" />
            Live Calls
          </h1>
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
        <div className="grid gap-4 md:grid-cols-1 lg:grid-cols-2">
          {activeCalls.map((call) => (
            <Card key={call.session_id} className="hover:shadow-md transition-shadow">
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                      <PhoneCall className="h-5 w-5 text-blue-600" />
                    </div>
                    <div>
                      <CardTitle className="text-lg flex items-center gap-2">
                        {call.caller_id || 'Unknown Caller'}
                        {getDirectionIcon(call.direction)}
                      </CardTitle>
                      <CardDescription className="flex items-center gap-1">
                        <ArrowRightLeft className="h-3 w-3" />
                        To: {call.destination || 'Unknown'}
                      </CardDescription>
                    </div>
                  </div>
                  <div className="text-right">
                    <span
                      className={cn(
                        'px-3 py-1 rounded-full text-xs font-medium border',
                        getStatusColor(call.status)
                      )}
                    >
                      {call.status}
                    </span>
                  </div>
                </div>
              </CardHeader>

              <CardContent>
                <div className="grid grid-cols-2 gap-4 text-sm">
                  <div>
                    <p className="text-muted-foreground flex items-center gap-1">
                      <Clock className="h-3 w-3" />
                      Duration
                    </p>
                    <p className="font-medium font-mono text-lg">
                      {call.formatted_duration}
                    </p>
                  </div>
                  <div>
                    <p className="text-muted-foreground">Session ID</p>
                    <p className="font-medium font-mono text-sm">
                      {call.session_id}
                    </p>
                  </div>
                  <div>
                    <p className="text-muted-foreground">Domain</p>
                    <p className="font-medium truncate" title={call.domain || 'N/A'}>
                      {call.domain || 'N/A'}
                    </p>
                  </div>
                  <div>
                    <p className="text-muted-foreground">Started</p>
                    <p className="font-medium">
                      {new Date(call.session_created_at).toLocaleTimeString()}
                    </p>
                  </div>
                </div>

                {/* QoS Indicator */}
                {call.has_qos_data && (
                  <div className="mt-4 p-2 bg-green-50 rounded-md border border-green-200">
                    <p className="text-xs text-green-700 flex items-center gap-1">
                      📊 Quality metrics available for this call
                    </p>
                  </div>
                )}
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {/* Manual Refresh Button */}
      <div className="flex justify-center">
        <button
          onClick={() => refetch()}
          disabled={isLoading}
          className={cn(
            "px-4 py-2 rounded-md text-sm font-medium transition-colors",
            "bg-blue-600 text-white hover:bg-blue-700 disabled:bg-gray-400"
          )}
        >
          {isLoading ? 'Refreshing...' : 'Refresh Now'}
        </button>
      </div>
    </div>
  );
}
