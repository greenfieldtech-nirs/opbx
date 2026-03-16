import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Plus,
  Search,
  MoreVertical,
  Play,
  Pause,
  RotateCcw,
  Archive,
  PhoneCall,
  RefreshCw,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
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
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { toast } from 'sonner';
import { useAuth } from '@/hooks/useAuth';
import {
  useAutoDialerCampaigns,
  useStartCampaign,
  usePauseCampaign,
  useResumeCampaign,
  useArchiveCampaign,
} from '@/hooks/useAutoDialerCampaigns';
import type { AutoDialerCampaign } from '@/services/autoDialerCampaignsApi';
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

export default function AutoDialerCampaigns() {
  const navigate = useNavigate();
  const { user: currentUser } = useAuth();

  // UI state
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'draft' | 'active' | 'paused' | 'completed' | 'archived'>('all');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const [selectedCampaign, setSelectedCampaign] = useState<AutoDialerCampaign | null>(null);
  const [isActionDialogOpen, setIsActionDialogOpen] = useState(false);
  const [actionType, setActionType] = useState<'start' | 'pause' | 'resume' | 'archive' | null>(null);

  const canManageCampaigns = currentUser && ['owner', 'pbx_admin'].includes(currentUser.role);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Fetch campaigns
  const { data, isLoading, error, refetch, isRefetching } = useAutoDialerCampaigns({
    page: currentPage,
    per_page: perPage,
    search: debouncedSearch || undefined,
    status: statusFilter !== 'all' ? statusFilter : undefined,
  });

  // Mutations
  const startMutation = useStartCampaign();
  const pauseMutation = usePauseCampaign();
  const resumeMutation = useResumeCampaign();
  const archiveMutation = useArchiveCampaign();

  const handleAction = (campaign: AutoDialerCampaign, action: 'start' | 'pause' | 'resume' | 'archive') => {
    setSelectedCampaign(campaign);
    setActionType(action);
    setIsActionDialogOpen(true);
  };

  const confirmAction = async () => {
    if (!selectedCampaign || !actionType) return;

    try {
      switch (actionType) {
        case 'start':
          await startMutation.mutateAsync(selectedCampaign.id);
          toast.success('Campaign started successfully');
          break;
        case 'pause':
          await pauseMutation.mutateAsync(selectedCampaign.id);
          toast.success('Campaign paused successfully');
          break;
        case 'resume':
          await resumeMutation.mutateAsync(selectedCampaign.id);
          toast.success('Campaign resumed successfully');
          break;
        case 'archive':
          await archiveMutation.mutateAsync(selectedCampaign.id);
          toast.success('Campaign archived successfully');
          break;
      }
    } catch (error: any) {
      toast.error(error?.response?.data?.message || `Failed to ${actionType} campaign`);
    } finally {
      setIsActionDialogOpen(false);
      setSelectedCampaign(null);
      setActionType(null);
    }
  };

  const getActionIcon = () => {
    switch (actionType) {
      case 'start': return <Play className="h-5 w-5" />;
      case 'pause': return <Pause className="h-5 w-5" />;
      case 'resume': return <RotateCcw className="h-5 w-5" />;
      case 'archive': return <Archive className="h-5 w-5" />;
      default: return null;
    }
  };

  const campaigns = data?.data || [];
  const totalPages = data?.meta?.last_page || 1;

  return (
    <div className="container mx-auto p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Auto Dialer Campaigns</h1>
          <p className="text-muted-foreground">
            Manage and monitor your outbound calling campaigns
          </p>
        </div>
        {canManageCampaigns && (
          <Button onClick={() => navigate('/ui/auto-dialer/new')}>
            <Plus className="h-4 w-4 mr-2" />
            Create Campaign
          </Button>
        )}
      </div>

      {/* Filters */}
      <Card>
        <CardHeader className="pb-3">
          <CardTitle>Filters</CardTitle>
          <CardDescription>Filter campaigns by status and search terms</CardDescription>
        </CardHeader>
        <CardContent>
          <div className="flex flex-col sm:flex-row gap-4">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search campaigns..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
              />
            </div>
            <Select
              value={statusFilter}
              onValueChange={(value) => {
                setStatusFilter(value as typeof statusFilter);
                setCurrentPage(1);
              }}
            >
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Filter by status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="draft">Draft</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="paused">Paused</SelectItem>
                <SelectItem value="completed">Completed</SelectItem>
                <SelectItem value="archived">Archived</SelectItem>
              </SelectContent>
            </Select>
            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              disabled={isRefetching}
            >
              <RefreshCw className={cn("h-4 w-4", isRefetching && "animate-spin")} />
            </Button>
          </div>
        </CardContent>
      </Card>

      {/* Campaigns List */}
      <Card>
        <CardHeader>
          <CardTitle>Campaigns</CardTitle>
          <CardDescription>
            {data?.meta?.total || 0} total campaigns
          </CardDescription>
        </CardHeader>
        <CardContent>
          {isLoading ? (
            <div className="flex items-center justify-center py-8">
              <RefreshCw className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : error ? (
            <div className="text-center py-8 text-red-500">
              Failed to load campaigns. Please try again.
            </div>
          ) : campaigns.length === 0 ? (
            <div className="text-center py-8 text-muted-foreground">
              <PhoneCall className="h-12 w-12 mx-auto mb-4 opacity-50" />
              <p className="text-lg font-medium">No campaigns found</p>
              <p className="text-sm">
                {searchQuery || statusFilter !== 'all'
                  ? 'Try adjusting your filters'
                  : 'Create your first campaign to get started'}
              </p>
            </div>
          ) : (
            <div className="space-y-4">
              {campaigns.map((campaign) => (
                <div
                  key={campaign.id}
                  className="flex items-center justify-between p-4 border rounded-lg hover:bg-muted/50 cursor-pointer transition-colors"
                  onClick={() => navigate(`/ui/auto-dialer/${campaign.id}`)}
                >
                  <div className="flex-1 min-w-0">
                    <div className="flex items-center gap-3 mb-2">
                      <h3 className="font-semibold truncate">{campaign.name}</h3>
                      {getStatusBadge(campaign.status)}
                      {campaign.auto_start && (
                        <Badge variant="outline" className="text-xs">Auto-start</Badge>
                      )}
                    </div>
                    <p className="text-sm text-muted-foreground truncate mb-2">
                      {campaign.description || 'No description'}
                    </p>
                    <div className="flex items-center gap-4 text-sm text-muted-foreground">
                      <span>{campaign.statistics.total_destinations} destinations</span>
                      <span>•</span>
                      <span>{campaign.calls_per_second} CPS</span>
                      <span>•</span>
                      <span>{campaign.routing_destination_label}</span>
                    </div>
                    {campaign.statistics.total_destinations > 0 && (
                      <div className="mt-3">
                        <div className="flex justify-between text-xs mb-1">
                          <span>Progress</span>
                          <span>{campaign.statistics.progress_percentage}%</span>
                        </div>
                        <div className="w-full bg-gray-200 rounded-full h-2">
                          <div
                            className="bg-blue-600 h-2 rounded-full transition-all"
                            style={{ width: `${campaign.statistics.progress_percentage}%` }}
                          />
                        </div>
                        <div className="flex justify-between text-xs mt-1 text-muted-foreground">
                          <span>{campaign.statistics.completed_calls} completed</span>
                          <span>{campaign.statistics.failed_calls} failed</span>
                          <span>{campaign.statistics.pending_calls} pending</span>
                        </div>
                      </div>
                    )}
                  </div>

                  {canManageCampaigns && (
                    <DropdownMenu>
                      <DropdownMenuTrigger asChild>
                        <Button
                          variant="ghost"
                          size="icon"
                          onClick={(e) => e.stopPropagation()}
                        >
                          <MoreVertical className="h-4 w-4" />
                        </Button>
                      </DropdownMenuTrigger>
                      <DropdownMenuContent align="end">
                        {campaign.status === 'draft' && (
                          <DropdownMenuItem onClick={(e) => {
                            e.stopPropagation();
                            handleAction(campaign, 'start');
                          }}>
                            <Play className="h-4 w-4 mr-2" />
                            Start
                          </DropdownMenuItem>
                        )}
                        {campaign.status === 'active' && (
                          <DropdownMenuItem onClick={(e) => {
                            e.stopPropagation();
                            handleAction(campaign, 'pause');
                          }}>
                            <Pause className="h-4 w-4 mr-2" />
                            Pause
                          </DropdownMenuItem>
                        )}
                        {campaign.status === 'paused' && (
                          <DropdownMenuItem onClick={(e) => {
                            e.stopPropagation();
                            handleAction(campaign, 'resume');
                          }}>
                            <RotateCcw className="h-4 w-4 mr-2" />
                            Resume
                          </DropdownMenuItem>
                        )}
                        <DropdownMenuItem 
                          onClick={(e) => {
                            e.stopPropagation();
                            handleAction(campaign, 'archive');
                          }}
                          className="text-destructive"
                        >
                          <Archive className="h-4 w-4 mr-2" />
                          Archive
                        </DropdownMenuItem>
                      </DropdownMenuContent>
                    </DropdownMenu>
                  )}
                </div>
              ))}

              {/* Pagination */}
              {totalPages > 1 && (
                <div className="flex justify-center gap-2 pt-4">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                    disabled={currentPage === 1}
                  >
                    Previous
                  </Button>
                  <span className="flex items-center px-4 text-sm">
                    Page {currentPage} of {totalPages}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                    disabled={currentPage === totalPages}
                  >
                    Next
                  </Button>
                </div>
              )}
            </div>
          )}
        </CardContent>
      </Card>

      {/* Action Confirmation Dialog */}
      <Dialog open={isActionDialogOpen} onOpenChange={setIsActionDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              {getActionIcon()}
              {actionType?.charAt(0).toUpperCase()}{actionType?.slice(1)} Campaign
            </DialogTitle>
            <DialogDescription>
              Are you sure you want to {actionType} the campaign "{selectedCampaign?.name}"?
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsActionDialogOpen(false)}>
              Cancel
            </Button>
            <Button 
              onClick={confirmAction}
              disabled={startMutation.isPending || pauseMutation.isPending || resumeMutation.isPending || archiveMutation.isPending}
            >
              {(startMutation.isPending || pauseMutation.isPending || resumeMutation.isPending || archiveMutation.isPending) ? (
                <RefreshCw className="h-4 w-4 animate-spin mr-2" />
              ) : null}
              Confirm
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
