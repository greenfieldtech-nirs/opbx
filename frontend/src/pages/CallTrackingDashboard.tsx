import { useState, useMemo } from 'react';
import { BarChart3, Filter, RefreshCw, X } from 'lucide-react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useCallTrackingAnalytics } from '@/hooks/useCallTrackingAnalytics';
import { KpiCards } from '@/components/call-tracking/KpiCards';
import { CallsChart } from '@/components/call-tracking/CallsChart';
import { TopCampaignsTable } from '@/components/call-tracking/TopCampaignsTable';
import { TopSourcesTable } from '@/components/call-tracking/TopSourcesTable';
import { LoadingSpinner } from '@/components/design-system';
import { cn } from '@/lib/utils';
import { format, parse, subDays } from 'date-fns';

export default function CallTrackingDashboard() {
  const [startDate, setStartDate] = useState<Date | undefined>(subDays(new Date(), 30));
  const [endDate, setEndDate] = useState<Date | undefined>(new Date());
  const [groupBy, setGroupBy] = useState<'day' | 'week' | 'month'>('day');

  const params = useMemo(
    () => ({
      start_date: startDate ? format(startDate, 'yyyy-MM-dd') : format(subDays(new Date(), 30), 'yyyy-MM-dd'),
      end_date: endDate ? format(endDate, 'yyyy-MM-dd') : format(new Date(), 'yyyy-MM-dd'),
      group_by: groupBy,
    }),
    [startDate, endDate, groupBy]
  );

  const { data, isLoading, isError, error, refetch, isRefetching } = useCallTrackingAnalytics(params);

  const hasActiveFilters =
    (startDate && format(startDate, 'yyyy-MM-dd') !== format(subDays(new Date(), 30), 'yyyy-MM-dd')) ||
    (endDate && format(endDate, 'yyyy-MM-dd') !== format(new Date(), 'yyyy-MM-dd')) ||
    groupBy !== 'day';

  const handleReset = () => {
    setStartDate(subDays(new Date(), 30));
    setEndDate(new Date());
    setGroupBy('day');
  };

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div>
        <h1 className="text-3xl font-bold flex items-center gap-2">
          <BarChart3 className="h-8 w-8" />
          Call Tracking Dashboard
        </h1>
        <p className="text-muted-foreground mt-1">
          Analytics, KPIs, and attribution for marketing campaigns
        </p>
        <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
          <span className="text-foreground">Call Tracking Dashboard</span>
        </div>
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3 items-center">
            <Input
              type="date"
              value={startDate ? format(startDate, 'yyyy-MM-dd') : ''}
              onChange={(e) => setStartDate(e.target.value ? parse(e.target.value, 'yyyy-MM-dd', new Date()) : undefined)}
              className="w-[160px]"
            />
            <Input
              type="date"
              value={endDate ? format(endDate, 'yyyy-MM-dd') : ''}
              onChange={(e) => setEndDate(e.target.value ? parse(e.target.value, 'yyyy-MM-dd', new Date()) : undefined)}
              className="w-[160px]"
            />

            <Select value={groupBy} onValueChange={(value) => setGroupBy(value as 'day' | 'week' | 'month')}>
              <SelectTrigger className="w-[160px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Group by" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="day">Day</SelectItem>
                <SelectItem value="week">Week</SelectItem>
                <SelectItem value="month">Month</SelectItem>
              </SelectContent>
            </Select>

            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              disabled={isRefetching}
              title="Refresh"
            >
              <RefreshCw className={cn('h-4 w-4', isRefetching && 'animate-spin')} />
            </Button>

            {hasActiveFilters && (
              <Button variant="ghost" size="sm" onClick={handleReset}>
                <X className="h-4 w-4 mr-2" />
                Reset
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      {isError ? (
        <Card>
          <CardContent className="p-6 text-center">
            <p className="text-red-600 mb-4">Failed to load analytics: {error?.message || 'Unknown error'}</p>
            <Button onClick={() => refetch()}>Try Again</Button>
          </CardContent>
        </Card>
      ) : isLoading ? (
        <div className="flex justify-center py-20">
          <LoadingSpinner size="lg" />
        </div>
      ) : data ? (
        <>
          <KpiCards kpis={data.kpis} />
          <Card>
            <CardHeader>
              <CardTitle>Calls vs Conversions</CardTitle>
              <CardDescription>Tracked calls and conversions over the selected period</CardDescription>
            </CardHeader>
            <CardContent>
              <CallsChart data={data.time_series} />
            </CardContent>
          </Card>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card>
              <CardHeader>
                <CardTitle>Top Campaigns</CardTitle>
                <CardDescription>Campaigns with the most tracked calls</CardDescription>
              </CardHeader>
              <CardContent>
                <TopCampaignsTable campaigns={data.top_campaigns} />
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>Top Sources</CardTitle>
                <CardDescription>Traffic sources driving the most conversions</CardDescription>
              </CardHeader>
              <CardContent>
                <TopSourcesTable sources={data.top_sources} />
              </CardContent>
            </Card>
          </div>
        </>
      ) : (
        <Card>
          <CardContent className="p-6 text-center text-muted-foreground">
            No analytics data available.
          </CardContent>
        </Card>
      )}
    </div>
  );
}
