/**
 * Dashboard Page
 *
 * Main dashboard with statistics and recent activity
 */

import { useQuery } from '@tanstack/react-query';
import { callLogsService } from '@/services/callLogs.service';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Phone, Users, PhoneCall, Activity, LayoutDashboard } from 'lucide-react';
import { formatPhoneNumber, formatTimeAgo, getStatusColor } from '@/utils/formatters';
import { cn } from '@/lib/utils';

export default function Dashboard() {
  // Fetch dashboard statistics
  const { data: stats, isLoading } = useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: () => callLogsService.getDashboardStats(),
    refetchInterval: 30000, // Refresh every 30 seconds
  });

  const statCards = [
    {
      title: 'Active Calls',
      value: stats?.active_calls || 0,
      icon: Activity,
      color: 'text-green-600',
      bgColor: 'bg-green-100',
    },
    {
      title: 'Total Extensions',
      value: stats?.total_extensions || 0,
      icon: Users,
      color: 'text-blue-600',
      bgColor: 'bg-blue-100',
    },
    {
      title: 'Phone Numbers',
      value: stats?.total_dids || 0,
      icon: Phone,
      color: 'text-purple-600',
      bgColor: 'bg-purple-100',
    },
    {
      title: 'Calls Today',
      value: stats?.calls_today || 0,
      icon: PhoneCall,
      color: 'text-orange-600',
      bgColor: 'bg-orange-100',
    },
  ];

  if (isLoading) {
    return (
      <div className="space-y-6">
        <h1 className="text-2xl font-bold flex items-center gap-2">
          <LayoutDashboard className="h-7 w-7" />
          Dashboard
        </h1>
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          {[1, 2, 3, 4].map((i) => (
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
        <h1 className="text-2xl font-bold flex items-center gap-2">
          <LayoutDashboard className="h-7 w-7" />
          Dashboard
        </h1>
        <p className="text-muted-foreground">
          Overview of your PBX system activity and statistics
        </p>
      </div>

      {/* Statistics Cards */}
      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        {statCards.map((stat) => (
          <Card key={stat.title}>
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

      {/* Recent Calls */}
      <Card>
        <CardHeader>
          <CardTitle>Recent Calls</CardTitle>
          <CardDescription>Latest call activity in your system</CardDescription>
        </CardHeader>
        <CardContent>
          {!stats?.recent_calls || stats.recent_calls.length === 0 ? (
            <p className="text-sm text-muted-foreground">No recent calls</p>
          ) : (
            <div className="space-y-4">
              {stats.recent_calls.map((call) => (
                <div
                  key={call.id}
                  className="flex items-center justify-between border-b pb-4 last:border-0 last:pb-0"
                >
                  <div className="flex items-center gap-4">
                    <div className="flex h-10 w-10 items-center justify-center rounded-full bg-blue-100">
                      <PhoneCall className="h-5 w-5 text-blue-600" />
                    </div>
                    <div>
                      <p className="font-medium">{formatPhoneNumber(call.from_number)}</p>
                      <p className="text-sm text-muted-foreground">
                        To: {formatPhoneNumber(call.to_number)}
                      </p>
                    </div>
                  </div>

                  <div className="flex items-center gap-4">
                    <span
                      className={cn(
                        'px-2 py-1 rounded-full text-xs font-medium',
                        getStatusColor(call.status)
                      )}
                    >
                      {call.status}
                    </span>
                    <p className="text-sm text-muted-foreground">
                      {formatTimeAgo(call.created_at)}
                    </p>
                  </div>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
