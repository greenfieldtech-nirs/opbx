import { useState } from 'react';
import { useParams, useNavigate } from 'react-router-dom';
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
  Globe,
  Radio,
  FileSpreadsheet,
  MoreVertical,
  Upload,
  Trash2,
  RefreshCw,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { toast } from 'sonner';
import { useAuth } from '@/hooks/useAuth';
import {
  useAutoDialerCampaign,
  useCampaignDestinations,
  useCampaignList,
  useStartCampaign,
  usePauseCampaign,
  useResumeCampaign,
  useArchiveCampaign,
  useDeleteCampaignList,
} from '@/hooks/useAutoDialerCampaigns';
import type { AutoDialerCampaign, CampaignDestination } from '@/services/autoDialerCampaignsApi';
import { cn } from '@/lib/utils';

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

function getDestinationStatusBadge(status: string) {
  const variants: Record<string, { variant: 'default' | 'secondary' | 'destructive' | 'outline'; label: string }> = {
    pending: { variant: 'outline', label: 'Pending' },
    dialing: { variant: 'default', label: 'Dialing' },
    connected: { variant: 'default', label: 'Connected' },
    completed: { variant: 'secondary', label: 'Completed' },
    failed: { variant: 'destructive', label: 'Failed' },
    invalid: { variant: 'destructive', label: 'Invalid' },
  };

  const config = variants[status] || { variant: 'secondary', label: status };
  return <Badge variant={config.variant}>{config.label}</Badge>;
}

export default function AutoDialerCampaignDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const { user: currentUser } = useAuth();

  const [activeTab, setActiveTab] = useState('overview');
  const [isActionDialogOpen, setIsActionDialogOpen] = useState(false);
  const [actionType, setActionType] = useState<'start' | 'pause' | 'resume' | 'archive' | null>(null);
  const [isDeleteListDialogOpen, setIsDeleteListDialogOpen] = useState(false);

  const canManageCampaigns = currentUser && ['owner', 'pbx_admin'].includes(currentUser.role);

  // Fetch campaign data
  const { data: campaign, isLoading: isCampaignLoading, error: campaignError } = useAutoDialerCampaign(id || '');

  // Fetch destinations
  const { data: destinationsData, isLoading: isDestinationsLoading } = useCampaignDestinations(id || '', {
    per_page: 50,
  });

  // Fetch list
  const { data: list, isLoading: isListLoading } = useCampaignList(id || '');

  // Mutations
  const startMutation = useStartCampaign();
  const pauseMutation = usePauseCampaign();
  const resumeMutation = useResumeCampaign();
  const archiveMutation = useArchiveCampaign();
  const deleteListMutation = useDeleteCampaignList();

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
          navigate('/ui/auto-dialer');
          return;
      }
    } catch (error: any) {
      toast.error(error?.response?.data?.message || `Failed to ${actionType} campaign`);
    } finally {
      setIsActionDialogOpen(false);
      setActionType(null);
    }
  };

  const handleDeleteList = async () => {
    if (!id) return;

    try {
      await deleteListMutation.mutateAsync(id);
      toast.success('List deleted successfully');
      setIsDeleteListDialogOpen(false);
    } catch (error: any) {
      toast.error(error?.response?.data?.message || 'Failed to delete list');
    }
  };

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
          <Button onClick={() => navigate('/ui/auto-dialer')}>
            <ArrowLeft className="h-4 w-4 mr-2" />
            Back to Campaigns
          </Button>
        </div>
      </div>
    );
  }

  // Handle both paginated response structures
  // Backend returns { data: { data: [...], current_page, ... } } for paginated responses
  // @ts-expect-error - API returns nested data structure that's not fully typed
  const destinations = (destinationsData?.data?.data as CampaignDestination[]) || 
                       (destinationsData?.data as CampaignDestination[]) || [];

  return (
    <div className="container mx-auto p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div>
          <Button variant="ghost" size="sm" onClick={() => navigate('/ui/auto-dialer')} className="mb-2">
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
      <div className="grid grid-cols-1 md:grid-cols-4 gap-4">
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
      </div>

      {/* Progress Bar */}
      {campaign.statistics.total_destinations > 0 && (
        <Card>
          <CardHeader>
            <CardTitle>Progress</CardTitle>
            <CardDescription>
              {campaign.statistics.progress_percentage}% complete
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div className="w-full bg-gray-200 rounded-full h-4">
              <div
                className="bg-blue-600 h-4 rounded-full transition-all"
                style={{ width: `${campaign.statistics.progress_percentage}%` }}
              />
            </div>
            <div className="flex justify-between text-sm mt-2 text-muted-foreground">
              <span>{campaign.statistics.completed_calls} completed</span>
              <span>{campaign.statistics.failed_calls} failed</span>
              <span>{campaign.statistics.pending_calls} pending</span>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="overview">Overview</TabsTrigger>
          <TabsTrigger value="destinations">Destinations</TabsTrigger>
          <TabsTrigger value="list">List</TabsTrigger>
          <TabsTrigger value="settings">Settings</TabsTrigger>
        </TabsList>

        <TabsContent value="overview" className="space-y-4">
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Card>
              <CardHeader>
                <CardTitle>Configuration</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Routing Destination</span>
                  <span className="font-medium">{campaign.routing_destination_label}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Caller ID</span>
                  <span className="font-medium">{campaign.caller_id}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Dial Timeout</span>
                  <span className="font-medium">{campaign.dial_timeout} seconds</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Calls Per Second</span>
                  <span className="font-medium">{campaign.calls_per_second}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Max Dial Attempts</span>
                  <span className="font-medium">{campaign.max_dial_attempts}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Record Calls</span>
                  <span className="font-medium">{campaign.record_calls ? 'Yes' : 'No'}</span>
                </div>
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>Schedule</CardTitle>
              </CardHeader>
              <CardContent className="space-y-4">
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Timezone</span>
                  <span className="font-medium">{campaign.timezone}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Active Days</span>
                  <span className="font-medium">{campaign.days_active.join(', ')}</span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Hours</span>
                  <span className="font-medium">
                    {campaign.start_time}:00 - {campaign.end_time}:00
                  </span>
                </div>
                <div className="flex justify-between">
                  <span className="text-muted-foreground">Date Range</span>
                  <span className="font-medium">
                    {campaign.start_date} to {campaign.end_date}
                  </span>
                </div>
              </CardContent>
            </Card>
          </div>
        </TabsContent>

        <TabsContent value="destinations">
          <Card>
            <CardHeader>
              <CardTitle>Destinations</CardTitle>
              <CardDescription>
                {destinations.length} destinations shown
              </CardDescription>
            </CardHeader>
            <CardContent>
              {isDestinationsLoading ? (
                <div className="flex items-center justify-center py-8">
                  <RefreshCw className="h-6 w-6 animate-spin text-muted-foreground" />
                </div>
              ) : destinations.length === 0 ? (
                <div className="text-center py-8 text-muted-foreground">
                  No destinations found. Upload a list to add destinations.
                </div>
              ) : (
                <Table>
                  <TableHeader>
                    <TableRow>
                      <TableHead>Phone Number</TableHead>
                      <TableHead>Status</TableHead>
                      <TableHead>Attempts</TableHead>
                      <TableHead>Duration</TableHead>
                      <TableHead>Last Disposition</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {destinations.map((destination) => (
                      <TableRow key={destination.id}>
                        <TableCell className="font-medium">{destination.phone_number}</TableCell>
                        <TableCell>{getDestinationStatusBadge(destination.status)}</TableCell>
                        <TableCell>{destination.dial_attempts}</TableCell>
                        <TableCell>{destination.duration}s</TableCell>
                        <TableCell>{destination.last_disposition || '-'}</TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="list">
          <Card>
            <CardHeader className="flex flex-row items-center justify-between">
              <div>
                <CardTitle>Destination List</CardTitle>
                <CardDescription>
                  {list ? `${list.valid_rows} valid destinations` : 'No list uploaded'}
                </CardDescription>
              </div>
              {canManageCampaigns && (
                <div className="flex gap-2">
                  {list ? (
                    <>
                      <Button variant="outline" onClick={() => setIsDeleteListDialogOpen(true)}>
                        <Trash2 className="h-4 w-4 mr-2" />
                        Delete List
                      </Button>
                      <Button onClick={() => navigate(`/ui/auto-dialer/campaigns/${id}/upload`)}>
                        <Upload className="h-4 w-4 mr-2" />
                        Replace List
                      </Button>
                    </>
                  ) : (
                    <Button onClick={() => navigate(`/ui/auto-dialer/campaigns/${id}/upload`)}>
                      <Upload className="h-4 w-4 mr-2" />
                      Upload List
                    </Button>
                  )}
                </div>
              )}
            </CardHeader>
            <CardContent>
              {isListLoading ? (
                <div className="flex items-center justify-center py-8">
                  <RefreshCw className="h-6 w-6 animate-spin text-muted-foreground" />
                </div>
              ) : list ? (
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                  <Card>
                    <CardHeader className="pb-2">
                      <CardTitle className="text-sm font-medium">Total Rows</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <div className="text-2xl font-bold">{list.total_rows}</div>
                    </CardContent>
                  </Card>
                  <Card>
                    <CardHeader className="pb-2">
                      <CardTitle className="text-sm font-medium text-green-600">Valid</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <div className="text-2xl font-bold text-green-600">{list.valid_rows}</div>
                    </CardContent>
                  </Card>
                  <Card>
                    <CardHeader className="pb-2">
                      <CardTitle className="text-sm font-medium text-red-600">Invalid</CardTitle>
                    </CardHeader>
                    <CardContent>
                      <div className="text-2xl font-bold text-red-600">{list.invalid_rows}</div>
                    </CardContent>
                  </Card>
                </div>
              ) : (
                <div className="text-center py-8 text-muted-foreground">
                  <FileSpreadsheet className="h-12 w-12 mx-auto mb-4 opacity-50" />
                  <p>No list uploaded yet.</p>
                  <p className="text-sm">Upload a CSV file with phone numbers to start dialing.</p>
                </div>
              )}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="settings">
          <Card>
            <CardHeader>
              <CardTitle>Campaign Settings</CardTitle>
              <CardDescription>View and edit campaign configuration</CardDescription>
            </CardHeader>
            <CardContent>
              <p className="text-muted-foreground">
                Campaign editing is available for draft campaigns only.{' '}
                {campaign.status !== 'draft' && (
                  <>
                    This campaign is currently <strong>{campaign.status}</strong>. Pause the campaign to
                    make changes.
                  </>
                )}
              </p>
              {campaign.status === 'draft' && canManageCampaigns && (
                  <Button className="mt-4" onClick={() => navigate(`/ui/auto-dialer/campaigns/${id}/edit`)}>
                  Edit Campaign
                </Button>
              )}
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

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

      {/* Delete List Dialog */}
      <Dialog open={isDeleteListDialogOpen} onOpenChange={setIsDeleteListDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete List</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete the destination list? This will remove all destinations
              from the campaign.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDeleteListDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleDeleteList}
              disabled={deleteListMutation.isPending}
            >
              {deleteListMutation.isPending && <RefreshCw className="h-4 w-4 animate-spin mr-2" />}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
