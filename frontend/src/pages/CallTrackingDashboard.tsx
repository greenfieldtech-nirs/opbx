import { useState, useMemo } from 'react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
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

  const { data, isLoading, isError, error } = useCallTrackingAnalytics(params);

  const handleReset = () => {
    setStartDate(subDays(new Date(), 30));
    setEndDate(new Date());
    setGroupBy('day');
  };

  return (
    <div className="p-6 space-y-6">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <h1 className="text-2xl font-bold">Call Tracking Dashboard</h1>
        <div className="flex flex-wrap items-center gap-2">
          <input
            type="date"
            value={startDate ? format(startDate, 'yyyy-MM-dd') : ''}
            onChange={(e) => setStartDate(e.target.value ? parse(e.target.value, 'yyyy-MM-dd', new Date()) : undefined)}
            className="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          />
          <input
            type="date"
            value={endDate ? format(endDate, 'yyyy-MM-dd') : ''}
            onChange={(e) => setEndDate(e.target.value ? parse(e.target.value, 'yyyy-MM-dd', new Date()) : undefined)}
            className="h-9 rounded-md border border-input bg-transparent px-3 py-1 text-sm shadow-sm transition-colors placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
          />
          <Select value={groupBy} onValueChange={(value) => setGroupBy(value as 'day' | 'week' | 'month')}>
            <SelectTrigger className="w-[140px]">
              <SelectValue placeholder="Group by" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="day">Day</SelectItem>
              <SelectItem value="week">Week</SelectItem>
              <SelectItem value="month">Month</SelectItem>
            </SelectContent>
          </Select>
          <Button variant="outline" onClick={handleReset}>
            Reset
          </Button>
        </div>
      </div>

      {isError ? (
        <div className="p-6">
          <p className="text-red-600">Failed to load analytics: {error?.message || 'Unknown error'}</p>
        </div>
      ) : isLoading ? (
        <p className="text-muted-foreground">Loading analytics...</p>
      ) : data ? (
        <>
          <KpiCards kpis={data.kpis} />
          <Card>
            <CardHeader>
              <CardTitle>Calls vs Conversions</CardTitle>
            </CardHeader>
            <CardContent>
              <CallsChart data={data.time_series} />
            </CardContent>
          </Card>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <Card>
              <CardHeader>
                <CardTitle>Top Campaigns</CardTitle>
              </CardHeader>
              <CardContent>
                <TopCampaignsTable campaigns={data.top_campaigns} />
              </CardContent>
            </Card>
            <Card>
              <CardHeader>
                <CardTitle>Top Sources</CardTitle>
              </CardHeader>
              <CardContent>
                <TopSourcesTable sources={data.top_sources} />
              </CardContent>
            </Card>
          </div>
        </>
      ) : (
        <p className="text-muted-foreground">No analytics data available.</p>
      )}
    </div>
  );
}
