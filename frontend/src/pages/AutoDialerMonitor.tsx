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
  Archive,
  MoreVertical,
  AlertTriangle,
  CheckCircle,
  XCircle,
  Clock,
  TrendingUp,
  BarChart3,
  Loader2,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import {
  useMonitorSummary,
  useMonitorDetail,
  useRefreshMonitor,
  monitorKeys,
} from '@/hooks/useAutoDialerMonitor';
import {
  usePauseCampaign,
  useResumeCampaign,
  useArchiveCampaign,
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
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import type { MonitorCampaign, MonitorDetailResponse } from '@/services/autoDialerMonitorApi';

// Constants
const REFRESH_INTERVAL_KEY = 'monitor-refresh-interval';
const MAX_ACTIVITY_POINTS = 30;

const REFRESH_OPTIONS = [
  { value: '0', label: 'Manual' },
  { value: '10000', label: '10s' },
  { value: '20000', label: '20s' },
  { value: '30000', label: '30s' },
  { value: '40000', label: '40s' },
  { value: '50000', label: '50s' },
  { value: '60000', label: '60s' },
];

// Helper types
interface ActivityPoint {
  timestamp: number;
  value: number;
}

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

function getWorkerHealthColor(status: string): string {
  switch (status) {
    case 'healthy':
      return 'bg-green-500';
    case 'degraded':
      return 'bg-yellow-500';
    case 'offline':
      return 'bg-red-500';
    default:
      return 'bg-gray-400';
  }
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

function getStatusColorClass(status: string): string {
  switch (status) {
    case 'active':
      return 'bg-green-500';
    case 'paused':
      return 'bg-yellow-500';
    default:
      return 'bg-gray-400';
  }
}

// Disposition colors
const DISPOSITION_COLORS: Record<string, string> = {
  answered: 'bg-green-500',
  completed: 'bg-blue-500',
  busy: 'bg-yellow-500',
  no_answer: 'bg-orange-500',
  failed: 'bg-red-500',
  cancelled: 'bg-gray-400',
  congestion: 'bg-purple-500',
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

// Sparkline component
function Sparkline({ data, width = 300, height = 80 }: { data: ActivityPoint[]; width?: number; height?: number }) {
  if (data.length < 2) {
    return (
      <div className="flex items-center justify-center h-20 text-sm text-muted-foreground">
        Collecting data...
      </div>
    );
  }

  const padding = { top: 10, right: 10, bottom: 20, left: 40 };
  const chartWidth = width - padding.left - padding.right;
  const chartHeight = height - padding.top - padding.bottom;

  const maxValue = Math.max(...data.map((d) => d.value), 1);
  const minValue = Math.min(...data.map((d) => d.value), 0);
  const valueRange = maxValue - minValue || 1;

  const points = data.map((d, i) => {
    const x = padding.left + (i / (data.length - 1)) * chartWidth;
    const y = padding.top + chartHeight - ((d.value - minValue) / valueRange) * chartHeight;
    return `${x},${y}`;
  });

  // Y-axis labels
  const yLabels = [maxValue, (maxValue + minValue) / 2, minValue];

  return (
    <svg width={width} height={height} className="overflow-visible">
      {/* Grid lines */}
      {[0, 0.5, 1].map((ratio, i) => {
        const y = padding.top + chartHeight * ratio;
        return (
          <line
            key={i}
            x1={padding.left}
            y1={y}
            x2={width - padding.right}
            y2={y}
            stroke="currentColor"
            strokeOpacity={0.1}
            strokeDasharray="2,2"
          />
        );
      })}

      {/* Y-axis labels */}
      {yLabels.map((value, i) => {
        const y = padding.top + chartHeight * (1 - i / 2);
        return (
          <text
            key={i}
            x={padding.left - 8}
            y={y + 4}
            textAnchor="end"
            className="text-xs fill-muted-foreground"
          >
            {Math.round(value)}
          </text>
        );
      })}

      {/* X-axis labels */}
      <text
        x={padding.left}
        y={height - 5}
        textAnchor="middle"
        className="text-xs fill-muted-foreground"
      >
        -30m
      </text>
      <text
        x={width - padding.right}
        y={height - 5}
        textAnchor="middle"
        className="text-xs fill-muted-foreground"
      >
        Now
      </text>

      {/* Area under the line */}
      <polygon
        points={`${points[0]} ${points.join(' ')} ${points[points.length - 1]} ${padding.left + chartWidth},${padding.top + chartHeight} ${padding.left},${padding.top + chartHeight}`}
        fill="currentColor"
        fillOpacity={0.1}
      />

      {/* The line */}
      <polyline
        points={points.join(' ')}
        fill="none"
        stroke="currentColor"
        strokeWidth={2}
        strokeLinecap="round"
        strokeLinejoin="round"
      />

      {/* Data points */}
      {points.map((point, i) => {
        const [x, y] = point.split(',').map(Number);
        return (
          <circle
            key={i}
            cx={x}
            cy={y}
            r={3}
            fill="currentColor"
            className="text-primary"
          />
        );
      })}
    </svg>
  );
}

// Confirmation Dialog Component
interface ConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: string;
  confirmLabel: string;
  confirmVariant?: 'default' | 'destructive';
  onConfirm: () => void;
  isLoading?: boolean;
}

function ConfirmDialog({
  open,
  onOpenChange,
  title,
  description,
  confirmLabel,
  confirmVariant = 'default',
  onConfirm,
  isLoading,
}: ConfirmDialogProps) {
  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent>
        <AlertDialogHeader>
          <AlertDialogTitle className="flex items-center gap-2">
            <AlertTriangle className="h-5 w-5 text-yellow-500" />
            {title}
          </AlertDialogTitle>
          <AlertDialogDescription>{description}</AlertDialogDescription>
        </AlertDialogHeader>
        <AlertDialogFooter>
          <AlertDialogCancel disabled={isLoading}>Cancel</AlertDialogCancel>
          <AlertDialogAction
            onClick={(e) => {
              e.preventDefault();
              onConfirm();
            }}
            disabled={isLoading}
            className={cn(
              confirmVariant === 'destructive' &&
                'bg-destructive text-destructive-foreground hover:bg-destructive/90'
            )}
          >
            {isLoading && <Loader2 className="h-4 w-4 mr-2 animate-spin" />}
            {confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
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
  const [activityHistory, setActivityHistory] = useState<Map<number, ActivityPoint[]>>(new Map());
  const [previousSnapshot, setPreviousSnapshot] = useState<Map<number, SnapshotData>>(new Map());
  const [callsPerMinute, setCallsPerMinute] = useState<Map<number, number | null>>(new Map());

  // Dialog state
  const [confirmDialog, setConfirmDialog] = useState<{
    open: boolean;
    type: 'pause' | 'resume' | 'archive' | null;
    campaign: MonitorCampaign | null;
  }>({ open: false, type: null, campaign: null });

  // Queries
  const summaryQuery = useMonitorSummary(refreshInterval > 0 ? refreshInterval : false);
  const detailQuery = useMonitorDetail(
    selectedCampaignId,
    refreshInterval > 0 ? refreshInterval : false
  );

  // Mutations
  const pauseMutation = usePauseCampaign();
  const resumeMutation = useResumeCampaign();
  const archiveMutation = useArchiveCampaign();

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

    // Update activity history
    setActivityHistory((history) => {
      const newHistory = new Map(history);
      const existing = newHistory.get(selectedCampaignId) || [];
      const newPoint: ActivityPoint = { timestamp: now, value: totalCalls };

      // Add new point and trim to max
      const updated = [...existing, newPoint];
      if (updated.length > MAX_ACTIVITY_POINTS) {
        updated.shift();
      }

      newHistory.set(selectedCampaignId, updated);
      return newHistory;
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

  const openConfirmDialog = useCallback((type: 'pause' | 'resume' | 'archive', campaign: MonitorCampaign) => {
    setConfirmDialog({ open: true, type, campaign });
  }, []);

  const closeConfirmDialog = useCallback(() => {
    setConfirmDialog({ open: false, type: null, campaign: null });
  }, []);

  const handleConfirmAction = useCallback(async () => {
    if (!confirmDialog.campaign || !confirmDialog.type) return;

    const campaignId = confirmDialog.campaign.id.toString();
    const campaignName = confirmDialog.campaign.name;

    try {
      switch (confirmDialog.type) {
        case 'pause':
          await pauseMutation.mutateAsync(campaignId);
          toast.success(`Campaign "${campaignName}" paused`);
          break;
        case 'resume':
          await resumeMutation.mutateAsync(campaignId);
          toast.success(`Campaign "${campaignName}" resumed`);
          break;
        case 'archive':
          await archiveMutation.mutateAsync(campaignId);
          toast.success(`Campaign "${campaignName}" archived`);
          // Return to overview if archived from detail view
          if (selectedCampaignId === confirmDialog.campaign.id) {
            setSelectedCampaignId(null);
          }
          break;
      }
      closeConfirmDialog();
    } catch (error) {
      toast.error(`Failed to ${confirmDialog.type} campaign`);
    }
  }, [confirmDialog, pauseMutation, resumeMutation, archiveMutation, selectedCampaignId, closeConfirmDialog]);

  // Computed values
  const selectedCampaign = useMemo(() => {
    if (!selectedCampaignId || !summaryQuery.data) return null;
    return summaryQuery.data.campaigns.find((c) => c.id === selectedCampaignId) || null;
  }, [selectedCampaignId, summaryQuery.data]);

  const currentCPM = selectedCampaignId ? callsPerMinute.get(selectedCampaignId) : null;
  const currentActivity = selectedCampaignId ? activityHistory.get(selectedCampaignId) || [] : [];

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
            <div className="flex items-center gap-2">
              {refreshInterval === 0 && (
                <Button variant="outline" size="sm" onClick={handleManualRefresh}>
                  <RefreshCw className="h-4 w-4 mr-2" />
                  Refresh
                </Button>
              )}
              <Select
                value={refreshInterval.toString()}
                onValueChange={handleRefreshIntervalChange}
              >
                <SelectTrigger className="w-[140px]">
                  <SelectValue placeholder="Refresh interval" />
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
          </div>

          {/* Global Summary Cards */}
          <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            {/* Active Campaigns */}
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

            {/* Total Active Calls */}
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

            {/* CAC Utilization */}
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

            {/* Worker Health */}
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
                      {/* Name */}
                      <div className="md:col-span-3">
                        <p className="font-medium text-primary hover:underline">{campaign.name}</p>
                        <p className="text-xs text-muted-foreground">{campaign.routing_destination_label}</p>
                      </div>

                      {/* Status */}
                      <div className="md:col-span-1">
                        <Badge variant={getStatusBadgeVariant(campaign.status)} className="capitalize">
                          {campaign.status}
                        </Badge>
                      </div>

                      {/* Progress */}
                      <div className="md:col-span-2">
                        <div className="flex items-center gap-2">
                          <div className="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                            <div
                              className="h-full bg-primary transition-all"
                              style={{ width: `${campaign.progress_percentage}%` }}
                            />
                          </div>
                          <span className="text-xs font-medium w-10">{campaign.progress_percentage}%</span>
                        </div>
                      </div>

                      {/* Active Calls */}
                      <div className="md:col-span-2">
                        <div className="flex items-center gap-2">
                          <span className="text-sm">
                            {campaign.active_calls}/{campaign.concurrent_active_calls}
                          </span>
                          <div className="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden max-w-16">
                            <div
                              className={cn(
                                'h-full transition-all',
                                campaign.cac_utilization > 80
                                  ? 'bg-red-500'
                                  : campaign.cac_utilization > 50
                                  ? 'bg-yellow-500'
                                  : 'bg-green-500'
                              )}
                              style={{ width: `${campaign.cac_utilization}%` }}
                            />
                          </div>
                        </div>
                      </div>

                      {/* Stats */}
                      <div className="md:col-span-2">
                        <div className="flex items-center gap-3 text-xs">
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span className="flex items-center gap-1 text-green-600">
                                <CheckCircle className="h-3 w-3" />
                                {campaign.completed_calls}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent>Completed</TooltipContent>
                          </Tooltip>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span className="flex items-center gap-1 text-red-600">
                                <XCircle className="h-3 w-3" />
                                {campaign.failed_calls}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent>Failed</TooltipContent>
                          </Tooltip>
                          <Tooltip>
                            <TooltipTrigger asChild>
                              <span className="flex items-center gap-1 text-muted-foreground">
                                <Clock className="h-3 w-3" />
                                {campaign.pending_calls}
                              </span>
                            </TooltipTrigger>
                            <TooltipContent>Pending</TooltipContent>
                          </Tooltip>
                        </div>
                      </div>

                      {/* Rate Limit */}
                      <div className="md:col-span-1">
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <div className="flex items-center gap-1.5">
                              <div
                                className={cn(
                                  'h-2.5 w-2.5 rounded-full',
                                  campaign.rate_limit_status.is_rate_limited ? 'bg-red-500' : 'bg-green-500'
                                )}
                              />
                              <span className="text-xs">
                                {campaign.rate_limit_status.is_rate_limited ? 'Limited' : 'OK'}
                              </span>
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

                      {/* Actions */}
                      <div className="md:col-span-1">
                        {campaign.status === 'active' ? (
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-8"
                            onClick={(e) => {
                              e.stopPropagation();
                              openConfirmDialog('pause', campaign);
                            }}
                          >
                            <Pause className="h-3.5 w-3.5 mr-1" />
                            Pause
                          </Button>
                        ) : (
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-8"
                            onClick={(e) => {
                              e.stopPropagation();
                              openConfirmDialog('resume', campaign);
                            }}
                          >
                            <Play className="h-3.5 w-3.5 mr-1" />
                            Resume
                          </Button>
                        )}
                      </div>
                    </div>
                  ))}
                </div>
              )}
            </CardContent>
          </Card>

          {/* Confirmation Dialog */}
          <ConfirmDialog
            open={confirmDialog.open}
            onOpenChange={(open) => !open && closeConfirmDialog()}
            title={
              confirmDialog.type === 'pause'
                ? `Pause Campaign`
                : confirmDialog.type === 'resume'
                ? `Resume Campaign`
                : `Archive Campaign`
            }
            description={
              confirmDialog.type === 'pause'
                ? `Pause campaign "${confirmDialog.campaign?.name}"? Active calls will continue but no new calls will be initiated.`
                : confirmDialog.type === 'resume'
                ? `Resume campaign "${confirmDialog.campaign?.name}"? The dialer will begin initiating new calls.`
                : `Archive campaign "${confirmDialog.campaign?.name}"? This action cannot be undone.`
            }
            confirmLabel={
              confirmDialog.type === 'pause' ? 'Pause' : confirmDialog.type === 'resume' ? 'Resume' : 'Archive'
            }
            confirmVariant={confirmDialog.type === 'archive' ? 'destructive' : 'default'}
            onConfirm={handleConfirmAction}
            isLoading={pauseMutation.isPending || resumeMutation.isPending || archiveMutation.isPending}
          />
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
              <p className="text-sm text-muted-foreground">
                {selectedCampaign?.routing_destination_label}
              </p>
            </div>
          </div>
          <div className="flex items-center gap-2">
            {refreshInterval === 0 && (
              <Button variant="outline" size="sm" onClick={handleManualRefresh}>
                <RefreshCw className="h-4 w-4 mr-2" />
                Refresh
              </Button>
            )}
            <Select
              value={refreshInterval.toString()}
              onValueChange={handleRefreshIntervalChange}
            >
              <SelectTrigger className="w-[140px]">
                <SelectValue placeholder="Refresh interval" />
              </SelectTrigger>
              <SelectContent>
                {REFRESH_OPTIONS.map((option) => (
                  <SelectItem key={option.value} value={option.value}>
                    {option.label}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {selectedCampaign?.status === 'active' ? (
              <Button
                variant="outline"
                size="sm"
                onClick={() => openConfirmDialog('pause', selectedCampaign)}
              >
                <Pause className="h-4 w-4 mr-2" />
                Pause
              </Button>
            ) : (
              <Button
                variant="outline"
                size="sm"
                onClick={() => openConfirmDialog('resume', selectedCampaign)}
              >
                <Play className="h-4 w-4 mr-2" />
                Resume
              </Button>
            )}
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button variant="outline" size="icon">
                  <MoreVertical className="h-4 w-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem
                  className="text-destructive focus:text-destructive"
                  onClick={() => selectedCampaign && openConfirmDialog('archive', selectedCampaign)}
                >
                  <Archive className="h-4 w-4 mr-2" />
                  Archive
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
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
            {/* KPI Cards */}
            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
              {/* Active Calls */}
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

              {/* CAC Utilization */}
              <Card>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">CAC Utilization</p>
                      <p className={cn('text-2xl font-bold mt-1', getUtilizationColor(detail.campaign.cac_utilization))}>
                        {detail.campaign.cac_utilization}%
                      </p>
                      <p className="text-xs text-muted-foreground mt-1">
                        {detail.campaign.cac_utilization < 50
                          ? 'Healthy'
                          : detail.campaign.cac_utilization < 80
                          ? 'Moderate'
                          : 'High'}
                      </p>
                    </div>
                    <div className="h-12 w-12 rounded-full bg-purple-100 flex items-center justify-center">
                      <BarChart3 className="h-6 w-6 text-purple-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* Calls per Minute */}
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
                      <TrendingUp className="h-6 w-6 text-green-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* Answer Rate */}
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
                      <p className="text-xs text-muted-foreground mt-1">
                        Completed / Total attempts
                      </p>
                    </div>
                    <div className="h-12 w-12 rounded-full bg-yellow-100 flex items-center justify-center">
                      <CheckCircle className="h-6 w-6 text-yellow-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* Avg Call Duration */}
              <Card>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">Avg Call Duration</p>
                      <p className="text-2xl font-bold mt-1">
                        {formatDuration(detail.statistics.avg_duration_seconds)}
                      </p>
                      <p className="text-xs text-muted-foreground mt-1">
                        Avg billsec: {formatDuration(detail.statistics.avg_billsec_seconds)}
                      </p>
                    </div>
                    <div className="h-12 w-12 rounded-full bg-orange-100 flex items-center justify-center">
                      <Clock className="h-6 w-6 text-orange-600" />
                    </div>
                  </div>
                </CardContent>
              </Card>

              {/* Rate Limit */}
              <Card>
                <CardContent className="p-6">
                  <div className="flex items-center justify-between">
                    <div>
                      <p className="text-sm font-medium text-muted-foreground">Rate Limit</p>
                      <div className="flex items-center gap-2 mt-1">
                        <div
                          className={cn(
                            'h-3 w-3 rounded-full',
                            detail.rate_limit_status.is_rate_limited ? 'bg-red-500' : 'bg-green-500'
                          )}
                        />
                        <p className="text-2xl font-bold">
                          {detail.rate_limit_status.is_rate_limited ? 'Throttled' : 'OK'}
                        </p>
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

            {/* Disposition Breakdown */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg">Disposition Breakdown</CardTitle>
              </CardHeader>
              <CardContent className="pt-0">
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

                  return (
                    <div className="space-y-3">
                      {Object.entries(dispositions)
                        .filter(([, count]) => count > 0)
                        .sort(([, a], [, b]) => b - a)
                        .map(([key, count]) => {
                          const percentage = (count / total) * 100;
                          return (
                            <div key={key} className="flex items-center gap-3">
                              <div className="w-24 text-sm">{DISPOSITION_LABELS[key] || key}</div>
                              <div className="flex-1 h-6 bg-gray-100 rounded-full overflow-hidden">
                                <div
                                  className={cn('h-full transition-all', DISPOSITION_COLORS[key] || 'bg-gray-400')}
                                  style={{ width: `${percentage}%` }}
                                />
                              </div>
                              <div className="w-20 text-right text-sm">
                                <span className="font-medium">{count}</span>
                                <span className="text-muted-foreground ml-1">({Math.round(percentage)}%)</span>
                              </div>
                            </div>
                          );
                        })}
                    </div>
                  );
                })()}
              </CardContent>
            </Card>

            {/* Rolling Activity Chart */}
            <Card>
              <CardHeader>
                <CardTitle className="text-lg flex items-center gap-2">
                  <TrendingUp className="h-5 w-5" />
                  Rolling Activity (30 min)
                </CardTitle>
              </CardHeader>
              <CardContent className="pt-0">
                <div className="text-primary">
                  <Sparkline data={currentActivity} />
                </div>
                <p className="text-xs text-muted-foreground mt-4">
                  Chart data accumulates while this page is open
                </p>
              </CardContent>
            </Card>

            {/* Progress & ETA */}
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
                    <div
                      className="h-full bg-primary transition-all"
                      style={{ width: `${detail.statistics.progress_percentage}%` }}
                    />
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
          </>
        )}

        {/* Confirmation Dialog */}
        <ConfirmDialog
          open={confirmDialog.open}
          onOpenChange={(open) => !open && closeConfirmDialog()}
          title={
            confirmDialog.type === 'pause'
              ? `Pause Campaign`
              : confirmDialog.type === 'resume'
              ? `Resume Campaign`
              : `Archive Campaign`
          }
          description={
            confirmDialog.type === 'pause'
              ? `Pause campaign "${confirmDialog.campaign?.name}"? Active calls will continue but no new calls will be initiated.`
              : confirmDialog.type === 'resume'
              ? `Resume campaign "${confirmDialog.campaign?.name}"? The dialer will begin initiating new calls.`
              : `Archive campaign "${confirmDialog.campaign?.name}"? This action cannot be undone.`
          }
          confirmLabel={
            confirmDialog.type === 'pause' ? 'Pause' : confirmDialog.type === 'resume' ? 'Resume' : 'Archive'
          }
          confirmVariant={confirmDialog.type === 'archive' ? 'destructive' : 'default'}
          onConfirm={handleConfirmAction}
          isLoading={pauseMutation.isPending || resumeMutation.isPending || archiveMutation.isPending}
        />
      </div>
    </TooltipProvider>
  );
}
