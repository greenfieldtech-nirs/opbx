import { useState } from 'react';
import { useParams, useNavigate, useLocation } from 'react-router-dom';
import {
  ArrowLeft,
  Play,
  Pause,
  RotateCcw,
  Archive,
  PhoneCall,
  Users,
  CheckCircle,
  XCircle,
  Clock,
  Calendar,
  Radio,
  Settings,
  Mic,
  Bot,
  RefreshCw,
  List,
  FileSpreadsheet,
  Pencil,
  TrendingUp,
  Phone,
  Shuffle,
  ListOrdered,
  Timer,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { toast } from 'sonner';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from '@/hooks/useAuth';
import {
  useAutoDialerCampaign,
  useStartCampaign,
  usePauseCampaign,
  useResumeCampaign,
  useArchiveCampaign,
  autoDialerKeys,
} from '@/hooks/useAutoDialerCampaigns';
import { useDistributionLists } from '@/hooks/useDistributionLists';
import type { AutoDialerCampaign } from '@/services/autoDialerCampaignsApi';

function getStatusBadge(status: string) {
  const variants: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }> = {
    draft: { variant: 'secondary', label: 'Draft' },
    active: { variant: 'default', label: 'Active' },
    paused: { variant: 'outline', label: 'Paused' },
    completed: { variant: 'secondary', label: 'Completed' },
    archived: { variant: 'destructive', label: 'Archived' },
  };

  const config = variants[status] || { variant: 'secondary', label: status };
  return <Badge variant={config.variant}>{config.label}</Badge>;
}

function getStrategyIcon(strategy: string) {
  switch (strategy) {
    case 'round_robin':
      return <ListOrdered className="h-4 w-4" />;
    case 'random':
      return <Shuffle className="h-4 w-4" />;
    case 'least_recently_used':
      return <Timer className="h-4 w-4" />;
    default:
      return <Phone className="h-4 w-4" />;
  }
}

function getStrategyLabel(strategy: string): string {
  const labels: Record<string, string> = {
    round_robin: 'Round Robin',
    random: 'Random',
    least_recently_used: 'Least Recently Used',
  };
  return labels[strategy] || strategy;
}

export default function AutoDialerCampaignDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const location = useLocation();
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  const handleBackToCampaigns = () => {
    queryClient.invalidateQueries({ queryKey: autoDialerKeys.all });
    navigate('/ui/auto-dialer');
  };

  const [isActionDialogOpen, setIsActionDialogOpen] = useState(false);
  const [actionType, setActionType] = useState<'start' | 'pause' | 'resume' | 'archive' | null>(null);
  const [listsPage, setListsPage] = useState(1);
  const LISTS_PER_PAGE = 5;

  const canManageCampaigns = currentUser && ['owner', 'pbx_admin'].includes(currentUser.role);

  // Fetch campaign data — poll every 10s when campaign is active
  const { data: campaign, isLoading: isCampaignLoading, error: campaignError } = useAutoDialerCampaign(id || '', 10000);

  // Fetch distribution lists for this campaign
  const { data: distributionListsData, isLoading: isListsLoading } = useDistributionLists(
    { campaign_id: id ? parseInt(id, 10) : undefined, per_page: 100 }
  );
  const distributionLists = distributionListsData?.data || [];

  // Get Caller ID pool from campaign data
  const callerIdPool = (campaign as any)?.caller_id_pool || [];
  const callerIdStrategy = (campaign as any)?.caller_id_strategy || 'round_robin';

  const getActionIcon = () => {
    switch (actionType) {
      case 'start':
        return <Play className="h-5 w-5" />;
      case 'pause':
        return <Pause className="h-5 w-5" />;
      case 'resume':
        return <RotateCcw className="h-5 w-5" />;
      case 'archive':
        return <Archive className="h-5 w-5" />;
      default:
        return null;
    }
  };

  const handleAction = (action: 'start' | 'pause' | 'resume' | 'archive') => {
    setActionType(action);
    setIsActionDialogOpen(true);
  };

  const confirmAction = async () => {
    if (!id || !actionType) return;

    try {
      switch (actionType) {
        case 'start':
          await startMutation.mutateAsync(id);
          toast.success('Campaign started successfully');
          break;
        case 'pause':
          await pauseMutation.mutateAsync(id);
          toast.success('Campaign paused successfully');
          break;
        case 'resume':
          await resumeMutation.mutateAsync(id);
          toast.success('Campaign resumed successfully');
          break;
        case 'archive':
          await archiveMutation.mutateAsync(id);
          toast.success('Campaign archived successfully');
          handleBackToCampaigns();
          return;
      }
    } catch (error: any) {
      toast.error(error?.response?.data?.message || `Failed to ${actionType} campaign`);
    } finally {
      setIsActionDialogOpen(false);
      setActionType(null);
    }
  };

  if (isCampaignLoading) {
    return (
      <div className="container mx-auto p-6">
        <div className="flex items-center justify-center py-12">
          <RefreshCw className="h-8 w-8 animate-spin text-muted-foreground" />
        </div>
      </div>
    );
  }

  if (campaignError || !campaign) {
    return (
      <div className="container mx-auto p-6">
        <div className="text-center py-12">
          <PhoneCall className="h-12 w-12 mx-auto mb-4 text-muted-foreground" />
          <h2 className="text-xl font-semibold mb-2">Campaign not found</h2>
          <p className="text-muted-foreground mb-4">The campaign you're looking for doesn't exist or you don't have access.</p>
          <Button onClick={handleBackToCampaigns}>
            <ArrowLeft className="h-4 w-4 mr-2" />
            Back to Campaigns
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={handleBackToCampaigns} className="mb-2">
            <ArrowLeft className="h-4 w-4 mr-2" />
            Back to Campaigns
          </Button>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold tracking-tight">{campaign.name}</h1>
            {getStatusBadge(campaign.status)}
            {campaign.auto_start && <Badge variant="outline">Auto-start</Badge>}
          </div>
          <p className="text-muted-foreground mt-1">{campaign.description || 'No description'}</p>
        </div>

        {canManageCampaigns && (
          <div className="flex gap-2">
            <Button
              variant="outline"
              disabled={campaign.status === 'active'}
              onClick={() => navigate(`/ui/auto-dialer/campaigns/${id}/edit`)}
              title={campaign.status === 'active' ? 'Pause the campaign to edit' : undefined}
            >
              <Pencil className="h-4 w-4 mr-2" />
              Edit
            </Button>
            {campaign.status === 'draft' && (
              <Button onClick={() => handleAction('start')}>
                <Play className="h-4 w-4 mr-2" />
                Start
              </Button>
            )}
            {campaign.status === 'active' && (
              <Button onClick={() => handleAction('pause')} variant="outline">
                <Pause className="h-4 w-4 mr-2" />
                Pause
              </Button>
            )}
            {campaign.status === 'paused' && (
              <Button onClick={() => handleAction('resume')}>
                <RotateCcw className="h-4 w-4 mr-2" />
                Resume
              </Button>
            )}
            <Button onClick={() => handleAction('archive')} variant="outline" className="text-destructive">
              <Archive className="h-4 w-4 mr-2" />
              Archive
            </Button>
          </div>
        )}
      </div>

      {/* Statistics Cards */}
      <div className="grid grid-cols-2 md:grid-cols-5 gap-4">
        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Total Destinations</CardTitle>
            <Users className="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold">{campaign.statistics.total_destinations}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Completed</CardTitle>
            <CheckCircle className="h-4 w-4 text-green-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-green-600">{campaign.statistics.completed_calls}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Failed</CardTitle>
            <XCircle className="h-4 w-4 text-red-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-red-600">{campaign.statistics.failed_calls}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Pending</CardTitle>
            <Clock className="h-4 w-4 text-yellow-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-yellow-600">{campaign.statistics.pending_calls}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle className="text-sm font-medium">Progress</CardTitle>
            <TrendingUp className="h-4 w-4 text-blue-600" />
          </CardHeader>
          <CardContent>
            <div className="text-2xl font-bold text-blue-600">{campaign.statistics.progress_percentage}%</div>
          </CardContent>
        </Card>
      </div>

      {/* Settings Tab - Full Campaign Details */}
      <Card>
        <CardHeader className="flex flex-row items-center justify-between">
          <div>
            <CardTitle className="flex items-center gap-2">
              <Settings className="h-5 w-5" />
              Campaign Settings
            </CardTitle>
            <CardDescription>Full campaign configuration and details</CardDescription>
          </div>
          {/* Edit button is in the page header */}
        </CardHeader>
        <CardContent>
          <div className="grid grid-cols-1 lg:grid-cols-2 gap-8">
            {/* Basic Information */}
            <div className="space-y-6">
              <div>
                <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
                  <PhoneCall className="h-4 w-4 text-muted-foreground" />
                  Basic Information
                </h3>
                <div className="space-y-3">
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Campaign Name</span>
                    <span className="font-medium">{campaign.name}</span>
                  </div>
                  <div className="flex justify-between py-2 border-b items-center">
                    <span className="text-muted-foreground">Status</span>
                    {getStatusBadge(campaign.status)}
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Auto-start</span>
                    <span className="font-medium">{campaign.auto_start ? 'Enabled' : 'Disabled'}</span>
                  </div>
                </div>
              </div>

              {/* Routing Configuration */}
              <div>
                <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
                  <Bot className="h-4 w-4 text-muted-foreground" />
                  Routing Configuration
                </h3>
                <div className="space-y-3">
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Destination Type</span>
                    <span className="font-medium">{campaign.routing_destination_label}</span>
                  </div>
                  {/* Show Caller ID Pool info if available */}
                  {(callerIdPool.length > 0 || (campaign as any).caller_id_strategy) && (
                    <>
                      <div className="flex justify-between py-2 border-b">
                        <span className="text-muted-foreground">Caller ID Strategy</span>
                        <div className="flex items-center gap-2">
                          {getStrategyIcon(callerIdStrategy)}
                          <span className="font-medium">{getStrategyLabel(callerIdStrategy)}</span>
                        </div>
                      </div>
                      <div className="py-2 border-b">
                        <div className="flex justify-between items-start mb-2">
                          <span className="text-muted-foreground">Caller ID Pool</span>
                          <span className="text-xs text-muted-foreground">{callerIdPool.length} numbers</span>
                        </div>
                        <div className="flex flex-wrap gap-2">
                          {callerIdPool.map((item: any) => (
                            <Badge key={item.did_id} variant="outline" className="font-mono">
                              <Phone className="h-3 w-3 mr-1" />
                              {item.phone_number}
                            </Badge>
                          ))}
                        </div>
                      </div>
                    </>
                  )}
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Dial Timeout</span>
                    <span className="font-medium">{campaign.dial_timeout} seconds</span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Destination Connect</span>
                    <span className="font-medium capitalize">{campaign.destination_connect}</span>
                  </div>
                </div>
              </div>

              {/* Dialing Guidelines */}
              <div>
                <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
                  <Radio className="h-4 w-4 text-muted-foreground" />
                  Dialing Guidelines
                </h3>
                <div className="space-y-3">
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Max Dial Attempts</span>
                    <span className="font-medium">{campaign.max_dial_attempts}</span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Concurrent Active Calls</span>
                    <span className="font-medium">{campaign.concurrent_active_calls}</span>
                  </div>
                </div>
              </div>
            </div>

            {/* Schedule & Advanced Settings */}
            <div className="space-y-6">
              {/* Distribution Lists */}
              <div>
                <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
                  <List className="h-4 w-4 text-muted-foreground" />
                  Distribution Lists
                </h3>
                <div className="border rounded-md">
                  {isListsLoading ? (
                    <div className="py-8 text-center text-muted-foreground">
                      <RefreshCw className="h-4 w-4 animate-spin mx-auto mb-2" />
                      Loading lists...
                    </div>
                  ) : distributionLists.length === 0 ? (
                    <div className="py-8 text-center text-muted-foreground">
                      <FileSpreadsheet className="h-8 w-8 mx-auto mb-2 opacity-50" />
                      <p>No distribution lists assigned</p>
                    </div>
                  ) : (
                    <>
                      <Table>
                        <TableHeader>
                          <TableRow>
                            <TableHead className="text-left">Name</TableHead>
                            <TableHead className="text-center">Version</TableHead>
                            <TableHead className="text-right">Records</TableHead>
                            <TableHead className="text-right">Invalid</TableHead>
                          </TableRow>
                        </TableHeader>
                        <TableBody>
                          {distributionLists
                            .slice((listsPage - 1) * LISTS_PER_PAGE, listsPage * LISTS_PER_PAGE)
                            .map((list) => (
                              <TableRow
                                key={list.id}
                                className="cursor-pointer hover:bg-muted/50"
                                onClick={() =>
                                  navigate(`/ui/auto-dialer/distribution-lists/${list.id}`, {
                                    state: { from: location.pathname },
                                  })
                                }
                              >
                                <TableCell className="font-medium">{list.name}</TableCell>
                                <TableCell className="text-center">
                                  <Badge variant="outline" className="text-xs">
                                    v{list.version_number}
                                  </Badge>
                                </TableCell>
                                <TableCell className="text-right">
                                  {list.statistics.valid_rows.toLocaleString()}
                                </TableCell>
                                <TableCell className="text-right">
                                  {list.statistics.invalid_rows > 0 ? (
                                    <span className="text-red-500">
                                      {list.statistics.invalid_rows}
                                    </span>
                                  ) : (
                                    <span className="text-muted-foreground">-</span>
                                  )}
                                </TableCell>
                              </TableRow>
                            ))}
                        </TableBody>
                      </Table>
                      {distributionLists.length > LISTS_PER_PAGE && (
                        <div className="flex items-center justify-between px-4 py-3 border-t bg-muted/30">
                          <div className="text-sm text-muted-foreground">
                            Showing {(listsPage - 1) * LISTS_PER_PAGE + 1} to{' '}
                            {Math.min(listsPage * LISTS_PER_PAGE, distributionLists.length)} of{' '}
                            {distributionLists.length} lists
                          </div>
                          <div className="flex gap-2">
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() => setListsPage((p) => Math.max(1, p - 1))}
                              disabled={listsPage === 1}
                            >
                              Previous
                            </Button>
                            <Button
                              variant="outline"
                              size="sm"
                              onClick={() =>
                                setListsPage((p) =>
                                  Math.min(Math.ceil(distributionLists.length / LISTS_PER_PAGE), p + 1)
                                )
                              }
                              disabled={listsPage >= Math.ceil(distributionLists.length / LISTS_PER_PAGE)}
                            >
                              Next
                            </Button>
                          </div>
                        </div>
                      )}
                    </>
                  )}
                </div>
              </div>

              {/* Schedule */}
              <div>
                <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
                  <Calendar className="h-4 w-4 text-muted-foreground" />
                  Schedule
                </h3>
                <div className="space-y-3">
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Timezone</span>
                    <span className="font-medium">{campaign.timezone}</span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Date Range</span>
                    <span className="font-medium">
                      {campaign.start_date} to {campaign.end_date}
                    </span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Active Days</span>
                    <span className="font-medium capitalize">
                      {campaign.days_active?.join(', ') || '-'}
                    </span>
                  </div>
                </div>
              </div>

              {/* Call Settings */}
              <div>
                <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
                  <Clock className="h-4 w-4 text-muted-foreground" />
                  Call Settings
                </h3>
                <div className="space-y-3">
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Time Limit</span>
                    <span className="font-medium">{campaign.time_limit || 3600} seconds</span>
                  </div>
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">Record Calls</span>
                    <span className="font-medium">{campaign.record_calls ? 'Yes' : 'No'}</span>
                  </div>
                </div>
              </div>

              {/* Answering Machine Detection */}
              <div>
                <h3 className="text-lg font-semibold mb-4 flex items-center gap-2">
                  <Mic className="h-4 w-4 text-muted-foreground" />
                  Answering Machine Detection
                </h3>
                <div className="space-y-3">
                  <div className="flex justify-between py-2 border-b">
                    <span className="text-muted-foreground">AMD Enabled</span>
                    <span className="font-medium">{campaign.amd_enabled ? 'Yes' : 'No'}</span>
                  </div>
                  {campaign.amd_enabled && (
                    <>
                      <div className="flex justify-between py-2 border-b">
                        <span className="text-muted-foreground">AMD Mode</span>
                        <span className="font-medium">{campaign.amd_mode || '-'}</span>
                      </div>
                      <div className="flex justify-between py-2 border-b">
                        <span className="text-muted-foreground">AMD Timeout</span>
                        <span className="font-medium">{campaign.amd_timeout || 30}s</span>
                      </div>
                    </>
                  )}
                </div>
              </div>

            </div>
          </div>
        </CardContent>
      </Card>

      {/* Action Confirmation Dialog */}
      <Dialog open={isActionDialogOpen} onOpenChange={setIsActionDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              {getActionIcon()}
              {actionType?.charAt(0).toUpperCase()}
              {actionType?.slice(1)} Campaign
            </DialogTitle>
            <DialogDescription>
              Are you sure you want to {actionType} this campaign?
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsActionDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={confirmAction}
              disabled={
                startMutation.isPending ||
                pauseMutation.isPending ||
                resumeMutation.isPending ||
                archiveMutation.isPending
              }
            >
              {(startMutation.isPending ||
                pauseMutation.isPending ||
                resumeMutation.isPending ||
                archiveMutation.isPending) && (
                <RefreshCw className="h-4 w-4 animate-spin mr-2" />
              )}
              Confirm
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
