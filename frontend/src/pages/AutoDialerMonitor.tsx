/**
 * Auto Dialer Real-Time Monitor Page
 *
 * Command-center view for monitoring active and paused auto-dialer campaigns.
 * Features bird's-eye view with global summaries and drill-down campaign details.
 */

import { useState, useEffect, useCallback, useMemo } from 'react';
import { useNavigate } from 'react-router-dom';
import { toast } from 'sonner';
import {
  Activity,
  PhoneCall,
  Cpu,
  Radio,
  ArrowLeft,
  RefreshCw,
  Pause,
  Play,
  AlertTriangle,
  CheckCircle,
  XCircle,
  Clock,
  BarChart3,
  Loader2,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  useMonitorSummary,
  useMonitorDetail,
  useRefreshMonitor,
} from '@/hooks/useAutoDialerMonitor';
import {
  usePauseCampaign,
  useResumeCampaign,
} from '@/hooks/useAutoDialerCampaigns';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import type { MonitorCampaign } from '@/services/autoDialerMonitorApi';

// Constants
const REFRESH_INTERVAL_KEY = 'monitor-refresh-interval';

const REFRESH_OPTIONS = [
  { value: '0', label: 'Manual' },
  { value: '1000', label: '1s' },
  { value: '5000', label: '5s' },
  { value: '10000', label: '10s' },
  { value: '20000', label: '20s' },
  { value: '30000', label: '30s' },
  { value: '40000', label: '40s' },
  { value: '50000', label: '50s' },
  { value: '60000', label: '60s' },
];

// Helper types
interface SnapshotData {
  total: number;
  timestamp: number;
}

// Helper functions
function formatDuration(seconds: number): string {
  if (!seconds || seconds <= 0) return '—';
  const mins = Math.floor(seconds / 60);
  const secs = seconds % 60;
  if (mins > 0) {
    return `${mins}m ${secs}s`;
  }
  return `${secs}s`;
}

function formatETA(minutes: number): string {
  if (!minutes || minutes <= 0 || !isFinite(minutes)) return 'Calculating...';
  const hours = Math.floor(minutes / 60);
  const mins = Math.ceil(minutes % 60);
  if (hours > 0) {
    return `${hours}h ${mins}m`;
  }
  return `${mins}m`;
}

function getWorkerHealthBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
  switch (status) {
    case 'healthy':
      return 'default';
    case 'degraded':
      return 'secondary';
    case 'offline':
      return 'destructive';
    default:
      return 'outline';
  }
}

function getUtilizationColor(percentage: number): string {
  if (percentage < 50) return 'text-green-600';
  if (percentage < 80) return 'text-yellow-600';
  return 'text-red-600';
}

function getStatusBadgeVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
  switch (status) {
    case 'active':
      return 'default';
    case 'paused':
      return 'secondary';
    default:
      return 'outline';
  }
}

// Disposition colors
const DISPOSITION_COLORS: Record<string, string> = {
  answered: '#22c55e',
  completed: '#3b82f6',
  busy: '#eab308',
  no_answer: '#f97316',
  failed: '#ef4444',
  cancelled: '#9ca3af',
  congestion: '#a855f7',
};

const DISPOSITION_LABELS: Record<string, string> = {
  answered: 'Answered',
  completed: 'Completed',
  busy: 'Busy',
  no_answer: 'No Answer',
  failed: 'Failed',
  cancelled: 'Cancelled',
  congestion: 'Congestion',
};

// Pie Chart component
function PieChart({
  data,
  size = 200,
}: {
  data: { key: string; value: number; color: string; label: string }[];
  size?: number;
}) {
  const total = data.reduce((sum, d) => sum + d.value, 0);
  if (total === 0) {
    return (
      <div className="flex items-center justify-center" style={{ width: size, height: size }}>
        <span className="text-sm text-muted-foreground">No data</span>
      </div>
    );
  }

  const radius = size / 2 - 10;
  const center = size / 2;
  let currentAngle = -Math.PI / 2; // Start at top

  const slices = data
    .filter((d) => d.value > 0)
    .map((d) => {
      const angle = (d.value / total) * 2 * Math.PI;
      const startAngle = currentAngle;
      const endAngle = currentAngle + angle;
      currentAngle = endAngle;

      const x1 = center + radius * Math.cos(startAngle);
      const y1 = center + radius * Math.sin(startAngle);
      const x2 = center + radius * Math.cos(endAngle);
      const y2 = center + radius * Math.sin(endAngle);
      const largeArc = angle > Math.PI ? 1 : 0;

      const path = [
        `M ${center} ${center}`,
        `L ${x1} ${y1}`,
        `A ${radius} ${radius} 0 ${largeArc} 1 ${x2} ${y2}`,
        'Z',
      ].join(' ');

      // Label position at midpoint of arc
      const midAngle = startAngle + angle / 2;
      const labelRadius = radius * 0.65;
      const labelX = center + labelRadius * Math.cos(midAngle);
      const labelY = center + labelRadius * Math.sin(midAngle);
      const percentage = Math.round((d.value / total) * 100);

      return { ...d, path, labelX, labelY, percentage };
    });

  return (
    <div className="flex flex-col items-center gap-3">
      <svg width={size} height={size}>
        {slices.map((slice) => (
          <g key={slice.key}>
            <path
              d={slice.path}
              fill={slice.color}
              stroke="white"
              strokeWidth={2}
              className="transition-opacity hover:opacity-80"
            />
            {slice.percentage >= 8 && (
              <text
                x={slice.labelX}
                y={slice.labelY}
                textAnchor="middle"
                dominantBaseline="middle"
                className="text-xs font-medium fill-white pointer-events-none"
              >
                {slice.percentage}%
              </text>
            )}
          </g>
        ))}
      </svg>
      <div className="flex flex-wrap justify-center gap-x-4 gap-y-1">
        {slices.map((slice) => (
          <div key={slice.key} className="flex items-center gap-1.5 text-xs">
            <div
              className="h-2.5 w-2.5 rounded-full shrink-0"
              style={{ backgroundColor: slice.color }}
            />
            <span>{slice.label}</span>
            <span className="text-muted-foreground font-medium">{slice.value}</span>
          </div>
        ))}
      </div>
    </div>
  );
}

// Refresh interval selector with label (shared between views)
function RefreshSelector({
  refreshInterval,
  onIntervalChange,
  onManualRefresh,
}: {
  refreshInterval: number;
  onIntervalChange: (value: string) => void;
  onManualRefresh: () => void;
}) {
  return (
    <div className="flex items-center gap-2">
      <span className="text-sm text-muted-foreground whitespace-nowrap">Refresh every:</span>
      <Select value={refreshInterval.toString()} onValueChange={onIntervalChange}>
        <SelectTrigger className="w-[100px] h-9">
          <SelectValue placeholder="Interval" />
        </SelectTrigger>
        <SelectContent>
          {REFRESH_OPTIONS.map((option) => (
            <SelectItem key={option.value} value={option.value}>
              {option.label}
            </SelectItem>
          ))}
        </SelectContent>
      </Select>
      <Button variant="outline" className="h-9" onClick={onManualRefresh}>
        <RefreshCw className="h-4 w-4 mr-2" />
        Refresh Now
      </Button>
    </div>
  );
}

// Pause/Resume button (shared between views)
function CampaignActionButton({
  campaign,
  onPause,
  onResume,
  isLoading,
}: {
  campaign: MonitorCampaign;
  onPause: (c: MonitorCampaign) => void;
  onResume: (c: MonitorCampaign) => void;
  isLoading: boolean;
}) {
  if (campaign.status === 'active') {
    return (
      <Button
        size="default"
        className="bg-red-600 hover:bg-red-700 text-white h-9"
        disabled={isLoading}
        onClick={(e) => {
          e.stopPropagation();
          onPause(campaign);
        }}
      >
        {isLoading ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Pause className="h-4 w-4 mr-2" />}
        Pause
      </Button>
    );
  }
  return (
    <Button
      size="default"
      className="bg-green-600 hover:bg-green-700 text-white h-9"
      disabled={isLoading}
      onClick={(e) => {
        e.stopPropagation();
        onResume(campaign);
      }}
    >
      {isLoading ? <Loader2 className="h-4 w-4 mr-2 animate-spin" /> : <Play className="h-4 w-4 mr-2" />}
      Resume
    </Button>
  );
}

// Main component
export default function AutoDialerMonitor() {
  const navigate = useNavigate();
  const refreshMonitor = useRefreshMonitor();

  // State
  const [selectedCampaignId, setSelectedCampaignId] = useState<number | null>(null);
  const [refreshInterval, setRefreshInterval] = useState<number>(() => {
    const saved = localStorage.getItem(REFRESH_INTERVAL_KEY);
    return saved ? parseInt(saved, 10) : 10000;
  });
  const [previousSnapshot, setPreviousSnapshot] = useState<Map<number, SnapshotData>>(new Map());
  const [callsPerMinute, setCallsPerMinute] = useState<Map<number, number | null>>(new Map());

  // Queries
  const summaryQuery = useMonitorSummary(refreshInterval > 0 ? refreshInterval : false);
  const detailQuery = useMonitorDetail(
    selectedCampaignId,
    refreshInterval > 0 ? refreshInterval : false
  );

  // Mutations
  const pauseMutation = usePauseCampaign();
  const resumeMutation = useResumeCampaign();

  // Persist refresh interval
  useEffect(() => {
    localStorage.setItem(REFRESH_INTERVAL_KEY, refreshInterval.toString());
  }, [refreshInterval]);

  // Track activity and compute CPM when detail data updates
  useEffect(() => {
    if (!detailQuery.data || !selectedCampaignId) return;

    const { statistics } = detailQuery.data;
    const totalCalls = statistics.completed_calls + statistics.failed_calls;
    const now = Date.now();

    setPreviousSnapshot((prev) => {
      const newMap = new Map(prev);
      const previous = newMap.get(selectedCampaignId);

      if (previous) {
        const callsDelta = totalCalls - previous.total;
        const timeDeltaMinutes = (now - previous.timestamp) / 60000;

        if (timeDeltaMinutes > 0) {
          const cpm = callsDelta / timeDeltaMinutes;
          setCallsPerMinute((cpmMap) => {
            const newCpmMap = new Map(cpmMap);
            newCpmMap.set(selectedCampaignId, cpm);
            return newCpmMap;
          });
        }
      }

      newMap.set(selectedCampaignId, { total: totalCalls, timestamp: now });
      return newMap;
    });

  }, [detailQuery.data, selectedCampaignId]);

  // Handlers
  const handleRefreshIntervalChange = useCallback((value: string) => {
    setRefreshInterval(parseInt(value, 10));
  }, []);

  const handleManualRefresh = useCallback(() => {
    refreshMonitor();
    if (selectedCampaignId) {
      detailQuery.refetch();
    } else {
      summaryQuery.refetch();
    }
  }, [refreshMonitor, selectedCampaignId, detailQuery, summaryQuery]);

  const handleCampaignClick = useCallback((campaign: MonitorCampaign) => {
    setSelectedCampaignId(campaign.id);
  }, []);

  const handleBackToOverview = useCallback(() => {
    setSelectedCampaignId(null);
  }, []);

  // Direct action handlers — invalidate monitor queries so the button updates
  const handlePause = useCallback(async (campaign: MonitorCampaign) => {
    try {
      await pauseMutation.mutateAsync(campaign.id.toString());
      toast.success(`Campaign "${campaign.name}" paused`);
      refreshMonitor();
    } catch {
      toast.error('Failed to pause campaign');
    }
  }, [pauseMutation, refreshMonitor]);

  const handleResume = useCallback(async (campaign: MonitorCampaign) => {
    try {
      await resumeMutation.mutateAsync(campaign.id.toString());
      toast.success(`Campaign "${campaign.name}" resumed`);
      refreshMonitor();
    } catch {
      toast.error('Failed to resume campaign');
    }
  }, [resumeMutation, refreshMonitor]);

  const isActionLoading = pauseMutation.isPending || resumeMutation.isPending;

  // Computed values
  const selectedCampaign = useMemo(() => {
    if (!selectedCampaignId || !summaryQuery.data) return null;
    return summaryQuery.data.campaigns.find((c) => c.id === selectedCampaignId) || null;
  }, [selectedCampaignId, summaryQuery.data]);

  const currentCPM = selectedCampaignId ? callsPerMinute.get(selectedCampaignId) : null;

  // Loading state
  if (summaryQuery.isLoading) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <div className="space-y-2">
            <div className="h-8 w-64 bg-gray-200 animate-pulse rounded" />
            <div className="h-4 w-48 bg-gray-200 animate-pulse rounded" />
          </div>
          <div className="h-10 w-32 bg-gray-200 animate-pulse rounded" />
        </div>
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          {[...Array(4)].map((_, i) => (
            <Card key={i}>
              <CardContent className="p-6">
                <div className="h-20 bg-gray-200 animate-pulse rounded" />
              </CardContent>
            </Card>
          ))}
        </div>
      </div>
    );
  }

  // Error state
  if (summaryQuery.error) {
    return (
      <div className="space-y-6">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Activity className="h-8 w-8" />
            Real-Time Monitor
          </h1>
          <p className="text-muted-foreground mt-1">Auto Dialer Campaign Command Center</p>
        </div>
        <Card>
          <CardContent className="p-6">
            <div className="text-center py-12">
              <XCircle className="h-12 w-12 mx-auto text-destructive mb-4" />
              <h3 className="text-lg font-semibold mb-2">Failed to load monitor data</h3>
              <p className="text-muted-foreground mb-4">
                {summaryQuery.error instanceof Error
                  ? summaryQuery.error.message
                  : 'An error occurred while loading monitor data'}
              </p>
              <Button onClick={() => summaryQuery.refetch()}>
                <RefreshCw className="h-4 w-4 mr-2" />
                Try Again
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  const { campaigns, totals, worker_health } = summaryQuery.data || {
    campaigns: [],
    totals: { active_campaigns: 0, paused_campaigns: 0, total_active_calls: 0, total_cac_capacity: 0, overall_utilization: 0 },
    worker_health: { status: 'unknown', active_campaigns: 0, active_calls: 0, queue_depth: 0 },
  };

  // Bird's-Eye View
  if (!selectedCampaignId) {
    return (
      <TooltipProvider>
        <div className="space-y-6">
          {/* Header */}
          <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
            <div>
              <h1 className="text-3xl font-bold flex items-center gap-2">
                <Activity className="h-8 w-8" />
                Real-Time Monitor
              </h1>
              <p className="text-muted-foreground mt-1">Auto Dialer Campaign Command Center</p>
            </div>
            <RefreshSelector
              refreshInterval={refreshInterval}
              onIntervalChange={handleRefreshIntervalChange}
              onManualRefresh={handleManualRefresh}
            />
          </div>

          {/* Global Summary Cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <Card>
              <CardContent className="p-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-muted-foreground">Active Campaigns</p>
                    <p className="text-3xl font-bold mt-1">{totals.active_campaigns}</p>
                  </div>
                  <div className="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                    <Activity className="h-6 w-6 text-blue-600" />
                  </div>
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-muted-foreground">Total Active Calls</p>
                    <p className="text-3xl font-bold mt-1">{totals.total_active_calls}</p>
                  </div>
                  <div className="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center">
                    <PhoneCall className="h-6 w-6 text-green-600" />
                  </div>
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-muted-foreground">CAC Utilization</p>
                    <p className={cn('text-3xl font-bold mt-1', getUtilizationColor(totals.overall_utilization))}>
                      {totals.overall_utilization}%
                    </p>
                  </div>
                  <div className="h-12 w-12 rounded-full bg-purple-100 flex items-center justify-center">
                    <BarChart3 className="h-6 w-6 text-purple-600" />
                  </div>
                </div>
              </CardContent>
            </Card>
            <Card>
              <CardContent className="p-6">
                <div className="flex items-center justify-between">
                  <div>
                    <p className="text-sm font-medium text-muted-foreground">Worker Health</p>
                    <Badge variant={getWorkerHealthBadgeVariant(worker_health.status)} className="mt-1 capitalize">
                      {worker_health.status}
                    </Badge>
                  </div>
                  <div className="h-12 w-12 rounded-full bg-orange-100 flex items-center justify-center">
                    <Cpu className="h-6 w-6 text-orange-600" />
                  </div>
                </div>
              </CardContent>
            </Card>
          </div>

          {/* Campaign Grid */}
          <Card>
            <CardHeader>
              <CardTitle className="text-lg">Active Campaigns</CardTitle>
            </CardHeader>
            <CardContent className="pt-0">
              {campaigns.length === 0 ? (
                <div className="text-center py-12">
                  <Radio className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                  <h3 className="text-lg font-semibold mb-2">No active campaigns</h3>
                  <p className="text-muted-foreground mb-4">
                    There are no active or paused campaigns to monitor at this time.
                  </p>
                  <Button variant="outline" onClick={() => navigate('/ui/auto-dialer/campaigns')}>
                    Go to Campaigns
                  </Button>
                </div>
              ) : (
                <div className="space-y-4">
                  {/* Table Header */}
                  <div className="hidden md:grid md:grid-cols-12 gap-4 text-sm font-medium text-muted-foreground pb-2 border-b">
                    <div className="col-span-3">Campaign</div>
                    <div className="col-span-1">Status</div>
                    <div className="col-span-2">Progress</div>
                    <div className="col-span-2">Active Calls</div>
                    <div className="col-span-2">Stats</div>
                    <div className="col-span-1">Rate Limit</div>
                    <div className="col-span-1">Actions</div>
                  </div>

                  {/* Campaign Rows */}
                  {campaigns.map((campaign) => (
                    <div
                      key={campaign.id}
                      className="grid grid-cols-1 md:grid-cols-12 gap-4 py-4 border-b last:border-0 items-center hover:bg-muted/50 rounded-lg px-2 -mx-2 cursor-pointer transition-colors"
                      onClick={() => handleCampaignClick(campaign)}
                    >
                      <div className="md:col-span-3">
                        <p className="font-medium text-primary hover:underline">{campaign.name}</p>
                        <p className="text-xs text-muted-foreground">{campaign.routing_destination_label}</p>
                      </div>
                      <div className="md:col-span-1">
                        <Badge variant={getStatusBadgeVariant(campaign.status)} className="capitalize">
                          {campaign.status}
                        </Badge>
                      </div>
                      <div className="md:col-span-2">
                        <div className="flex items-center gap-2">
                          <div className="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div className="h-full bg-primary transition-all" style={{ width: `${campaign.progress_percentage}%` }} />
                          </div>
                          <span className="text-xs font-medium w-10">{campaign.progress_percentage}%</span>
                        </div>
                      </div>
                      <div className="md:col-span-2">
                        <div className="flex items-center gap-2">
                          <span className="text-sm">{campaign.active_calls}/{campaign.concurrent_active_calls}</span>
                          <div className="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden max-w-16">
                            <div
                              className={cn(
                                'h-full transition-all',
                                campaign.cac_utilization > 80 ? 'bg-red-500' : campaign.cac_utilization > 50 ? 'bg-yellow-500' : 'bg-green-500'
                              )}
                              style={{ width: `${campaign.cac_utilization}%` }}
                            />
                          </div>
                        </div>
                      </div>
                      <div className="md:col-span-2">
                        <div className="flex items-center gap-3 text-xs">
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span className="flex items-center gap-1 text-green-600">
                                <CheckCircle className="h-3 w-3" />{campaign.completed_calls}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent>Completed</TooltipContent>
                          </Tooltip>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span className="flex items-center gap-1 text-red-600">
                                <XCircle className="h-3 w-3" />{campaign.failed_calls}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent>Failed</TooltipContent>
                          </Tooltip>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span className="flex items-center gap-1 text-muted-foreground">
                                <Clock className="h-3 w-3" />{campaign.pending_calls}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent>Pending</TooltipContent>
                          </Tooltip>
                        </div>
                      </div>
                      <div className="md:col-span-1">
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <div className="flex items-center gap-1.5">
                              <div className={cn('h-2.5 w-2.5 rounded-full', campaign.rate_limit_status.is_rate_limited ? 'bg-red-500' : 'bg-green-500')} />
                              <span className="text-xs">{campaign.rate_limit_status.is_rate_limited ? 'Limited' : 'OK'}</span>
                            </div>
                          </TooltipTrigger>
                          <TooltipContent>
                            {campaign.rate_limit_status.is_rate_limited
                              ? campaign.rate_limit_status.resumes_at
                                ? `Resumes at ${new Date(campaign.rate_limit_status.resumes_at).toLocaleTimeString()}`
                                : 'Currently rate limited'
                              : 'No rate limiting'}
                          </TooltipContent>
                        </Tooltip>
                      </div>
                      <div className="md:col-span-1">
                        <CampaignActionButton
                          campaign={campaign}
                          onPause={handlePause}
                          onResume={handleResume}
                          isLoading={isActionLoading}
                        />
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>
        </div>
      </TooltipProvider>
    );
  }

  // Campaign Drill-Down View
  const detail = detailQuery.data;
  const isLoadingDetail = detailQuery.isLoading;

  return (
    <TooltipProvider>
      <div className="space-y-6">
        {/* Header */}
        <div className="flex flex-col sm:flex-row sm:justify-between sm:items-center gap-4">
          <div className="flex items-center gap-3">
            <Button variant="outline" size="icon" onClick={handleBackToOverview}>
              <ArrowLeft className="h-4 w-4" />
            </Button>
            <div>
              <div className="flex items-center gap-2">
                <h1 className="text-2xl font-bold">{selectedCampaign?.name}</h1>
                {selectedCampaign && (
                  <Badge variant={getStatusBadgeVariant(selectedCampaign.status)} className="capitalize">
                    {selectedCampaign.status}
                  </Badge>
                )}
              </div>
              <p className="text-sm text-muted-foreground">{selectedCampaign?.routing_destination_label}</p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            <RefreshSelector
              refreshInterval={refreshInterval}
              onIntervalChange={handleRefreshIntervalChange}
              onManualRefresh={handleManualRefresh}
            />
            {selectedCampaign && (
              <CampaignActionButton
                campaign={selectedCampaign}
                onPause={handlePause}
                onResume={handleResume}
                isLoading={isActionLoading}
              />
            )}
          </div>
        </div>

        {isLoadingDetail || !detail ? (
          <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            {[...Array(6)].map((_, i) => (
              <Card key={i}>
                <CardContent className="p-6">
                  <div className="h-24 bg-gray-200 animate-pulse rounded" />
                </CardContent>
              </Card>
            ))}
          </div>
        ) : (
          <>
            {/* Campaign Progress — at the top */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg">Campaign Progress</CardTitle>
              </CardHeader>
              <CardContent className="pt-0">
                <div className="space-y-4">
                  <div className="flex items-center justify-between text-sm">
                    <span className="text-muted-foreground">Overall Completion</span>
                    <span className="font-medium">{detail.statistics.progress_percentage}%</span>
                  </div>
                  <div className="h-3 bg-gray-200 rounded-full overflow-hidden">
                    <div className="h-full bg-primary transition-all" style={{ width: `${detail.statistics.progress_percentage}%` }} />
                  </div>
                  <div className="flex items-center justify-between text-sm">
                    <div className="flex items-center gap-4">
                      <span>
                        <span className="text-muted-foreground">Completed:</span>{' '}
                        <span className="font-medium text-green-600">{detail.statistics.completed_calls}</span>
                      </span>
                      <span>
                        <span className="text-muted-foreground">Failed:</span>{' '}
                        <span className="font-medium text-red-600">{detail.statistics.failed_calls}</span>
                      </span>
                      <span>
                        <span className="text-muted-foreground">Pending:</span>{' '}
                        <span className="font-medium">{detail.statistics.pending_calls}</span>
                      </span>
                    </div>
                    <span className="text-muted-foreground">
                      ETA:{' '}
                      {currentCPM && currentCPM > 0
                        ? formatETA(detail.statistics.pending_calls / currentCPM)
                        : 'Calculating...'}
                    </span>
                  </div>
                </div>
              </CardContent>
            </Card>

            {/* KPI Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              <Card>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">Active Calls</p>
                      <p className="text-2xl font-bold mt-1">
                        {detail.campaign.active_calls} / {detail.campaign.concurrent_active_calls}
                      </p>
                      <p className="text-xs text-muted-foreground mt-1">
                        {Math.round((detail.campaign.active_calls / Math.max(detail.campaign.concurrent_active_calls, 1)) * 100)}% of capacity
                      </p>
                    </div>
                    <div className="h-12 w-12 rounded-full bg-blue-100 flex items-center justify-center">
                      <PhoneCall className="h-6 w-6 text-blue-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">CAC Utilization</p>
                      <p className={cn('text-2xl font-bold mt-1', getUtilizationColor(detail.campaign.cac_utilization))}>
                        {detail.campaign.cac_utilization}%
                      </p>
                      <p className="text-xs text-muted-foreground mt-1">
                        {detail.campaign.cac_utilization < 50 ? 'Healthy' : detail.campaign.cac_utilization < 80 ? 'Moderate' : 'High'}
                      </p>
                    </div>
                    <div className="h-12 w-12 rounded-full bg-purple-100 flex items-center justify-center">
                      <BarChart3 className="h-6 w-6 text-purple-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">Calls per Minute</p>
                      <p className="text-2xl font-bold mt-1">
                        {currentCPM !== null && currentCPM !== undefined ? currentCPM.toFixed(1) : '—'}
                      </p>
                      <p className="text-xs text-muted-foreground mt-1">
                        {currentCPM !== null && currentCPM !== undefined ? 'Current rate' : 'Collecting data...'}
                      </p>
                    </div>
                    <div className="h-12 w-12 rounded-full bg-green-100 flex items-center justify-center">
                      <Activity className="h-6 w-6 text-green-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">Answer Rate</p>
                      <p className="text-2xl font-bold mt-1">
                        {detail.statistics.completed_calls + detail.statistics.failed_calls > 0
                          ? `${Math.round(
                              (detail.statistics.completed_calls /
                                Math.max(detail.statistics.completed_calls + detail.statistics.failed_calls, 1)) *
                                100
                            )}%`
                          : '—'}
                      </p>
                      <p className="text-xs text-muted-foreground mt-1">Completed / Total attempts</p>
                    </div>
                    <div className="h-12 w-12 rounded-full bg-yellow-100 flex items-center justify-center">
                      <CheckCircle className="h-6 w-6 text-yellow-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">Avg Call Duration</p>
                      <p className="text-2xl font-bold mt-1">{formatDuration(detail.statistics.avg_duration_seconds)}</p>
                      <p className="text-xs text-muted-foreground mt-1">Avg billsec: {formatDuration(detail.statistics.avg_billsec_seconds)}</p>
                    </div>
                    <div className="h-12 w-12 rounded-full bg-orange-100 flex items-center justify-center">
                      <Clock className="h-6 w-6 text-orange-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>
              <Card>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">Rate Limit</p>
                      <div className="flex items-center gap-2 mt-1">
                        <div className={cn('h-3 w-3 rounded-full', detail.rate_limit_status.is_rate_limited ? 'bg-red-500' : 'bg-green-500')} />
                        <p className="text-2xl font-bold">{detail.rate_limit_status.is_rate_limited ? 'Throttled' : 'OK'}</p>
                      </div>
                      {detail.rate_limit_status.resumes_at && (
                        <p className="text-xs text-muted-foreground mt-1">
                          Resumes at {new Date(detail.rate_limit_status.resumes_at).toLocaleTimeString()}
                        </p>
                      )}
                    </div>
                    <div className="h-12 w-12 rounded-full bg-red-100 flex items-center justify-center">
                      <Activity className="h-6 w-6 text-red-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>
            </div>

            {/* Active Calls + Disposition Pie Chart side by side */}
            <div className="grid grid-cols-1 lg:grid-cols-2 gap-6">
              {/* Active Calls Table */}
              <Card>
                <CardHeader>
                  <CardTitle className="text-lg flex items-center gap-2">
                    <PhoneCall className="h-5 w-5" />
                    Active Calls ({detail.active_sessions?.length || 0})
                  </CardTitle>
                </CardHeader>
                <CardContent className="pt-0">
                  {!detail.active_sessions || detail.active_sessions.length === 0 ? (
                    <div className="text-center py-8 text-muted-foreground">
                      No active calls at this time
                    </div>
                  ) : (
                    <div className="overflow-auto max-h-[300px]">
                      <table className="w-full text-sm">
                        <thead className="sticky top-0 bg-background">
                          <tr className="border-b text-left text-muted-foreground">
                            <th className="py-2 pr-3 font-medium">Phone Number</th>
                            <th className="py-2 pr-3 font-medium">Status</th>
                            <th className="py-2 font-medium text-right">Duration</th>
                          </tr>
                        </thead>
                        <tbody>
                          {detail.active_sessions.map((session) => (
                            <tr key={session.id} className="border-b last:border-0">
                              <td className="py-2 pr-3 font-mono">{session.phone_number}</td>
                              <td className="py-2 pr-3">
                                <Badge variant="outline" className="capitalize text-xs">
                                  {session.status}
                                </Badge>
                              </td>
                              <td className="py-2 text-right text-muted-foreground">
                                {formatDuration(session.duration_seconds)}
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Disposition Pie Chart */}
              <Card>
                <CardHeader>
                  <CardTitle className="text-lg">Disposition Breakdown</CardTitle>
                </CardHeader>
                <CardContent className="pt-0 flex items-center justify-center">
                  {(() => {
                    const dispositions = detail.dispositions;
                    const total = Object.values(dispositions).reduce((a, b) => a + b, 0);

                    if (total === 0) {
                      return (
                        <div className="text-center py-8 text-muted-foreground">
                          No disposition data available yet
                        </div>
                      );
                    }

                    const chartData = Object.entries(dispositions)
                      .filter(([, count]) => count > 0)
                      .sort(([, a], [, b]) => b - a)
                      .map(([key, count]) => ({
                        key,
                        value: count,
                        color: DISPOSITION_COLORS[key] || '#9ca3af',
                        label: DISPOSITION_LABELS[key] || key,
                      }));

                    return <PieChart data={chartData} size={220} />;
                  })()}
                </CardContent>
              </Card>
            </div>
          </>
        )}
      </div>
    </TooltipProvider>
  );
}
