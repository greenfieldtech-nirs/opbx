/**
 * Platform Dashboard Page
 *
 * Main dashboard for platform managers showing key metrics.
 */

import { useEffect, useRef } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from '@/hooks/useAuth';
import {
  Building2,
  Users,
  Phone,
  Globe,
  TrendingUp,
  Activity,
  ScrollText,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { PlatformLayout } from '@/components/platform';
import { usePlatformDashboard } from '@/hooks/platform';
import { OrganizationStatusBadge } from '@/components/platform';
import { AuditLogEntry } from '@/components/platform';
import type { PlatformOrganization } from '@/types/platform';

function StatCard({
  title,
  value,
  description,
  icon: Icon,
  trend,
  onClick,
}: {
  title: string;
  value: string | number;
  description?: string;
  icon: React.ElementType;
  trend?: string;
  onClick?: () => void;
}) {
  return (
    <Card className={onClick ? 'cursor-pointer hover:border-primary' : ''} onClick={onClick}>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium">{title}</CardTitle>
        <Icon className="h-4 w-4 text-muted-foreground" />
      </CardHeader>
      <CardContent>
        <div className="text-2xl font-bold">{value}</div>
        {(description || trend) && (
          <p className="text-xs text-muted-foreground">
            {trend && <span className="text-green-600">{trend} </span>}
            {description}
          </p>
        )}
      </CardContent>
    </Card>
  );
}

export default function PlatformDashboard() {
  const navigate = useNavigate();
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

  const { data, isLoading, error } = usePlatformDashboard();

  if (isLoading) {
    return (
      <PlatformLayout>
        <div className="flex items-center justify-center h-64">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
        </div>
      </PlatformLayout>
    );
  }

  if (error || !data) {
    return (
      <PlatformLayout>
        <div className="text-center py-12">
          <Activity className="h-12 w-12 mx-auto text-red-500 mb-4" />
          <h2 className="text-lg font-semibold mb-2">Failed to load dashboard</h2>
          <p className="text-muted-foreground">Please try again later.</p>
        </div>
      </PlatformLayout>
    );
  }

  return (
    <PlatformLayout>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex items-center justify-between">
          <div>
            <h1 className="text-2xl font-bold">Platform Dashboard</h1>
            <p className="text-muted-foreground">Overview of all organizations and system metrics</p>
          </div>
        </div>

        {/* Stats Grid */}
        <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
          <StatCard
            title="Organizations"
            value={data.organizations.total}
            description={`${data.organizations.active} active`}
            icon={Building2}
            onClick={() => navigate('/ui/platform/organizations')}
          />
          <StatCard
            title="Users"
            value={data.users.total}
            description={`${data.users.platform_managers} platform managers`}
            icon={Users}
            onClick={() => navigate('/ui/platform/users')}
          />
          <StatCard
            title="Extensions"
            value={data.extensions.total}
            icon={Phone}
          />
          <StatCard
            title="DIDs"
            value={data.dids.total}
            icon={Globe}
          />
        </div>

        <div className="grid gap-6 lg:grid-cols-2">
          {/* Recent Organizations */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <div>
                <CardTitle>Recent Organizations</CardTitle>
                <CardDescription>Latest organizations added to the platform</CardDescription>
              </div>
              <Button variant="outline" size="sm" onClick={() => navigate('/ui/platform/organizations')}>
                View All
              </Button>
            </CardHeader>
            <CardContent>
              {data.recent_organizations.length === 0 ? (
                <p className="text-sm text-muted-foreground">No organizations yet.</p>
              ) : (
                <div className="space-y-3">
                  {data.recent_organizations.map((org: PlatformOrganization) => (
                    <div
                      key={org.id}
                      className="flex items-center justify-between p-3 rounded-lg border hover:bg-muted/50 cursor-pointer"
                      onClick={() => navigate(`/ui/platform/organizations/${org.id}`)}
                    >
                      <div className="flex items-center gap-3">
                        <Building2 className="h-5 w-5 text-muted-foreground" />
                        <div>
                          <p className="font-medium">{org.name}</p>
                          <p className="text-xs text-muted-foreground">{org.slug}</p>
                        </div>
                      </div>
                      <div className="flex items-center gap-3">
                        <div className="text-xs text-right">
                          <p className="text-muted-foreground">{org.users_count} users</p>
                          <p className="text-muted-foreground">{org.extensions_count} extensions</p>
                        </div>
                        <OrganizationStatusBadge status={org.status} />
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Recent Audit Log */}
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <div>
                <CardTitle className="flex items-center gap-2">
                  <ScrollText className="h-5 w-5" />
                  Recent Activity
                </CardTitle>
                <CardDescription>Latest platform management actions</CardDescription>
              </div>
              <Button variant="outline" size="sm" onClick={() => navigate('/ui/platform/audit-log')}>
                View All
              </Button>
            </CardHeader>
            <CardContent>
              {data.recent_audit_logs.length === 0 ? (
                <p className="text-sm text-muted-foreground">No recent activity.</p>
              ) : (
                <div className="space-y-3">
                  {data.recent_audit_logs.slice(0, 5).map((entry) => (
                    <AuditLogEntry key={entry.id} entry={entry} />
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </div>
    </PlatformLayout>
  );
}
