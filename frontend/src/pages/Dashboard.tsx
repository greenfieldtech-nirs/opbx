/**
 * Dashboard Page
 *
 * Main dashboard with statistics and recent activity
 */

import { useCallback } from 'react';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { extensionsService } from '@/services/extensions.service';
import { conferenceRoomsService, phoneNumbersService } from '@/services/createResourceService';
import { cdrService } from '@/services/cdr.service';
import { sessionUpdatesService } from '@/services/sessionUpdates.service';
import { getSupervisorDashboard } from '@/services/dashboard.service';
import { useAuth } from '@/hooks/useAuth';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Phone, Users, PhoneCall, Activity, LayoutDashboard } from 'lucide-react';
import { formatPhoneNumber, formatTimeAgo, getDispositionColor } from '@/utils/formatters';
import { cn } from '@/lib/utils';
import { useRefreshTimer } from '@/context/RefreshTimerContext';
import type { CallDetailRecord } from '@/types/api.types';

export default function Dashboard() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const isSupervisor = user?.role === 'supervisor';

  // Fetch supervisor dashboard data
  const {
    data: supervisorData,
    isLoading: supervisorLoading,
    isFetching: supervisorFetching,
  } = useQuery({
    queryKey: ['dashboard', 'supervisor'],
    queryFn: getSupervisorDashboard,
    refetchInterval: 30000,
    enabled: isSupervisor,
  });

  // Fetch active calls count
  const { data: activeCallsResponse, isLoading: activeCallsLoading, isFetching: activeCallsFetching, refetch: refetchActiveCalls } = useQuery({
    queryKey: ['active-calls-dashboard'],
    queryFn: async () => {
      return await sessionUpdatesService.getActiveCalls();
    },
    refetchInterval: 15000, // Refresh every 15 seconds for dashboard
    staleTime: 10000, // Consider data fresh for 10 seconds
    enabled: !isSupervisor,
  });

  const activeCalls = activeCallsResponse?.meta.total_active_calls || 0;

  // Fetch extensions count
  const { data: extensionsCount, isLoading: extensionsLoading, isFetching: extensionsFetching, refetch: refetchExtensions } = useQuery({
    queryKey: ['extensions-count'],
    queryFn: async () => {
      const response = await extensionsService.getAll({ per_page: 1 });
      return response.meta.total;
    },
    refetchInterval: 30000,
    staleTime: 25000,
    enabled: !isSupervisor,
  });

  // Fetch conference rooms count
  const { data: conferenceRoomsCount, isLoading: conferenceRoomsLoading, isFetching: conferenceRoomsFetching, refetch: refetchConferenceRooms } = useQuery({
    queryKey: ['conference-rooms-count'],
    queryFn: async () => {
      const response = await conferenceRoomsService.getAll({ per_page: 1 });
      return response.meta.total;
    },
    refetchInterval: 30000,
    staleTime: 25000,
    enabled: !isSupervisor,
  });

  // Fetch phone numbers count
  const { data: phoneNumbersCount, isLoading: phoneNumbersLoading, isFetching: phoneNumbersFetching, refetch: refetchPhoneNumbers } = useQuery({
    queryKey: ['phone-numbers-count'],
    queryFn: async () => {
      const response = await phoneNumbersService.getAll({ per_page: 1 });
      return response.meta.total;
    },
    refetchInterval: 30000,
    staleTime: 25000,
    enabled: !isSupervisor,
  });

  // Fetch last 10 CDR records
  const { data: lastCalls, isLoading: lastCallsLoading, isFetching: lastCallsFetching, refetch: refetchLastCalls } = useQuery({
    queryKey: ['last-calls'],
    queryFn: async () => {
      const response = await cdrService.getAll({ per_page: 10 });
      return response.data;
    },
    refetchInterval: 30000,
    staleTime: 25000,
    enabled: !isSupervisor,
  });

  const isLoading = isSupervisor
    ? supervisorLoading
    : activeCallsLoading || extensionsLoading || conferenceRoomsLoading || phoneNumbersLoading || lastCallsLoading;
  const isRefreshing = isSupervisor
    ? supervisorFetching
    : activeCallsFetching || extensionsFetching || conferenceRoomsFetching || phoneNumbersFetching || lastCallsFetching;

  const handleRefresh = useCallback(() => {
    if (isSupervisor) {
      return;
    }
    refetchActiveCalls();
    refetchExtensions();
    refetchConferenceRooms();
    refetchPhoneNumbers();
    refetchLastCalls();
  }, [isSupervisor, refetchActiveCalls, refetchExtensions, refetchConferenceRooms, refetchPhoneNumbers, refetchLastCalls]);

  // Register refresh timer with AppLayout bar
  useRefreshTimer(isSupervisor ? 30000 : 15000, isRefreshing);

  const statCards = [
    {
      title: 'Active Calls',
      value: activeCalls || 0,
      icon: Activity,
      color: 'text-green-600',
      bgColor: 'bg-green-100',
      link: '/ui/live-calls',
    },
    {
      title: 'Extensions',
      value: extensionsCount || 0,
      icon: Users,
      color: 'text-blue-600',
      bgColor: 'bg-blue-100',
      link: '/ui/extensions',
    },
    {
      title: 'Phone Numbers',
      value: phoneNumbersCount || 0,
      icon: Phone,
      color: 'text-purple-600',
      bgColor: 'bg-purple-100',
      link: '/ui/phone-numbers',
    },
    {
      title: 'Conference Rooms',
      value: conferenceRoomsCount || 0,
      icon: PhoneCall,
      color: 'text-orange-600',
      bgColor: 'bg-orange-100',
      link: '/ui/conference-rooms',
    },
  ];

  const supervisorStatCards = [
    {
      title: 'Assigned Users',
      value: supervisorData?.assigned_users_count || 0,
      icon: Users,
      color: 'text-blue-600',
      bgColor: 'bg-blue-100',
      link: '/ui/users',
    },
    {
      title: 'Assigned Ring Groups',
      value: supervisorData?.assigned_ring_groups_count || 0,
      icon: PhoneCall,
      color: 'text-orange-600',
      bgColor: 'bg-orange-100',
      link: '/ui/ring-groups',
    },
    {
      title: 'Active Calls',
      value: supervisorData?.active_calls_count || 0,
      icon: Activity,
      color: 'text-green-600',
      bgColor: 'bg-green-100',
      link: '/ui/live-calls',
    },
  ];

  if (isLoading) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <LayoutDashboard className="h-8 w-8" />
            Dashboard
          </h1>
          <p className="text-muted-foreground mt-1">
            Overview of your PBX system activity and statistics
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span className="text-foreground">Dashboard</span>
          </div>
        </div>
        <div className={cn('grid gap-4 md:grid-cols-2', isSupervisor ? 'lg:grid-cols-3' : 'lg:grid-cols-4')}>
          {(isSupervisor ? [1, 2, 3] : [1, 2, 3, 4]).map((i) => (
            <Card key={i} className="animate-pulse">
              <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <div className="h-4 w-24 bg-gray-200 rounded" />
                <div className="h-10 w-10 bg-gray-200 rounded-lg" />
              </CardHeader>
              <CardContent>
                <div className="h-8 w-16 bg-gray-200 rounded" />
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div>
        <h1 className="text-3xl font-bold flex items-center gap-2">
          <LayoutDashboard className="h-8 w-8" />
          Dashboard
        </h1>
        <p className="text-muted-foreground mt-1">
          Overview of your PBX system activity and statistics
        </p>
        <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
          <span className="text-foreground">Dashboard</span>
        </div>
      </div>

      {/* Statistics Cards */}
      {isSupervisor ? (
        <>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            {supervisorStatCards.map((stat) => (
              <Card
                key={stat.title}
                className="cursor-pointer hover:shadow-md transition-shadow"
                onClick={() => stat.link && navigate(stat.link)}
              >
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium">{stat.title}</CardTitle>
                  <div className={cn('p-2 rounded-lg', stat.bgColor)}>
                    <stat.icon className={cn('h-5 w-5', stat.color)} />
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{stat.value}</div>
                </CardContent>
              </Card>
            ))}
          </div>

          {/* Recent Calls Section */}
          <Card>
            <CardHeader>
              <CardTitle>Recent Calls</CardTitle>
              <CardDescription>Recent calls for your assigned resources</CardDescription>
            </CardHeader>
            <CardContent>
              {!supervisorData?.recent_calls || supervisorData.recent_calls.length === 0 ? (
                <div className="text-center py-12">
                  <PhoneCall className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                  <h3 className="text-lg font-semibold mb-2">No recent calls</h3>
                  <p className="text-muted-foreground">Call records will appear here once calls are completed</p>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b">
                        <th className="text-left p-4 font-medium whitespace-nowrap">Time</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">Source Number</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">Source User</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">Destination</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">Status</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">Duration</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">Call Direction</th>
                      </tr>
                    </thead>
                    <tbody>
                      {supervisorData.recent_calls.map((call: CallDetailRecord) => (
                        <tr key={call.id} className="border-b hover:bg-gray-50">
                          <td className="p-4 text-sm whitespace-nowrap">
                            {formatTimeAgo(call.created_at)}
                          </td>
                          <td className="p-4 whitespace-nowrap">{formatPhoneNumber(call.from)}</td>
                          <td className="p-4 whitespace-nowrap">
                            <span className={cn(
                              call.user_full_name === 'Unassigned' && 'text-muted-foreground italic'
                            )}>
                              {call.user_full_name}
                            </span>
                          </td>
                          <td className="p-4 whitespace-nowrap">{formatPhoneNumber(call.to)}</td>
                          <td className="p-4">
                            <span
                              className={cn(
                                'px-2 py-1 rounded-full text-xs font-medium whitespace-nowrap',
                                getDispositionColor(call.disposition)
                              )}
                            >
                              {call.disposition}
                            </span>
                          </td>
                          <td className="p-4 text-muted-foreground whitespace-nowrap">
                            {call.duration_formatted}
                          </td>
                          <td className="p-4 text-muted-foreground whitespace-nowrap capitalize">
                            {call.direction || '-'}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Assigned Resources */}
          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardHeader>
                <CardTitle>Assigned Users</CardTitle>
                <CardDescription>Users assigned to your supervision</CardDescription>
              </CardHeader>
              <CardContent>
                {!supervisorData?.assigned_users || supervisorData.assigned_users.length === 0 ? (
                  <div className="text-center py-8">
                    <Users className="h-10 w-10 mx-auto text-muted-foreground mb-3" />
                    <p className="text-muted-foreground">No users assigned</p>
                  </div>
                ) : (
                  <ul className="divide-y">
                    {supervisorData.assigned_users.map((assignedUser) => (
                      <li
                        key={assignedUser.id}
                        className="py-3 flex items-center justify-between hover:bg-gray-50 px-2 -mx-2 rounded"
                      >
                        <span className="font-medium">{assignedUser.name}</span>
                        {assignedUser.extension_number && (
                          <span className="text-sm text-muted-foreground">Ext. {assignedUser.extension_number}</span>
                        )}
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Assigned Ring Groups</CardTitle>
                <CardDescription>Ring groups assigned to your supervision</CardDescription>
              </CardHeader>
              <CardContent>
                {!supervisorData?.assigned_ring_groups || supervisorData.assigned_ring_groups.length === 0 ? (
                  <div className="text-center py-8">
                    <PhoneCall className="h-10 w-10 mx-auto text-muted-foreground mb-3" />
                    <p className="text-muted-foreground">No ring groups assigned</p>
                  </div>
                ) : (
                  <ul className="divide-y">
                    {supervisorData.assigned_ring_groups.map((ringGroup) => (
                      <li
                        key={ringGroup.id}
                        className="py-3 px-2 -mx-2 rounded hover:bg-gray-50 font-medium"
                      >
                        {ringGroup.name}
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          </div>
        </>
      ) : (
        <>
          <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            {statCards.map((stat) => (
              <Card
                key={stat.title}
                className="cursor-pointer hover:shadow-md transition-shadow"
                onClick={() => stat.link && navigate(stat.link)}
              >
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                  <CardTitle className="text-sm font-medium">{stat.title}</CardTitle>
                  <div className={cn('p-2 rounded-lg', stat.bgColor)}>
                    <stat.icon className={cn('h-5 w-5', stat.color)} />
                  </div>
                </CardHeader>
                <CardContent>
                  <div className="text-2xl font-bold">{stat.value}</div>
                </CardContent>
              </Card>
            ))}
          </div>

          {/* Last Calls Section */}
          <Card>
            <CardHeader>
              <CardTitle>Last Calls</CardTitle>
              <CardDescription>Recent call activity from your CDR records</CardDescription>
            </CardHeader>
            <CardContent>
              {!lastCalls || lastCalls.length === 0 ? (
                <div className="text-center py-12">
                  <PhoneCall className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                  <h3 className="text-lg font-semibold mb-2">No recent calls</h3>
                  <p className="text-muted-foreground">Call records will appear here once calls are completed</p>
                </div>
              ) : (
                <div className="overflow-x-auto">
                  <table className="w-full">
                    <thead>
                      <tr className="border-b">
                        <th className="text-left p-4 font-medium whitespace-nowrap">Time</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">From</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">To</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">Status</th>
                        <th className="text-left p-4 font-medium whitespace-nowrap">Duration</th>
                      </tr>
                    </thead>
                    <tbody>
                      {lastCalls.map((call: CallDetailRecord) => (
                        <tr key={call.id} className="border-b hover:bg-gray-50">
                          <td className="p-4 text-sm whitespace-nowrap">
                            {formatTimeAgo(call.created_at)}
                          </td>
                          <td className="p-4 whitespace-nowrap">{formatPhoneNumber(call.from)}</td>
                          <td className="p-4 whitespace-nowrap">{formatPhoneNumber(call.to)}</td>
                          <td className="p-4">
                            <span
                              className={cn(
                                'px-2 py-1 rounded-full text-xs font-medium whitespace-nowrap',
                                getDispositionColor(call.disposition)
                              )}
                            >
                              {call.disposition}
                            </span>
                          </td>
                          <td className="p-4 text-muted-foreground whitespace-nowrap">
                            {call.duration_formatted}
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              )}
            </CardContent>
          </Card>
        </>
      )}


    </div>
  );
}
