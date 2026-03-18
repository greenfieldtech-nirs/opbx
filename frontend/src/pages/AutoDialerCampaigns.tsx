/**
 * Campaign Manager Page
 *
 * Manage auto-dialer campaigns with Extensions-style UI
 */

import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
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
  X,
  Target,
  Calendar,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAuth } from '@/hooks/useAuth';
import { autoDialerCampaignsApi } from '@/services/autoDialerCampaignsApi';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
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
  StandardDataTable,
  EmptyState,
} from '@/components/design-system';
import type { AutoDialerCampaign } from '@/services/autoDialerCampaignsApi';
import { formatDateTime } from '@/utils/formatters';

// Status badge helper
const getStatusBadge = (status: string) => {
  const configs: Record<string, { color: string; label: string }> = {
    draft: { color: 'bg-gray-100 text-gray-800 border-gray-200', label: 'Draft' },
    active: { color: 'bg-green-100 text-green-800 border-green-200', label: 'Active' },
    paused: { color: 'bg-yellow-100 text-yellow-800 border-yellow-200', label: 'Paused' },
    completed: { color: 'bg-blue-100 text-blue-800 border-blue-200', label: 'Completed' },
    archived: { color: 'bg-red-100 text-red-800 border-red-200', label: 'Archived' },
  };

  const config = configs[status] || { color: 'bg-gray-100 text-gray-800 border-gray-200', label: status };

  return (
    <Badge variant="outline" className={cn('text-xs', config.color)}>
      {config.label}
    </Badge>
  );
};

export default function AutoDialerCampaigns() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
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

  // Permission checks
  const canManage = currentUser && ['owner', 'pbx_admin'].includes(currentUser.role);
  const isReadOnly = ['reporter', 'pbx_user'].includes(currentUser?.role);

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 300);

    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Fetch campaigns
  const { data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['auto-dialer-campaigns', {
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
    }],
    queryFn: () => autoDialerCampaignsApi.getAll({
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
    }),
  });

  // Mutations
  const startMutation = useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.start(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['auto-dialer-campaigns'] });
      toast.success('Campaign started successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Failed to start campaign');
    },
  });

  const pauseMutation = useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.pause(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['auto-dialer-campaigns'] });
      toast.success('Campaign paused successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Failed to pause campaign');
    },
  });

  const resumeMutation = useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.resume(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['auto-dialer-campaigns'] });
      toast.success('Campaign resumed successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Failed to resume campaign');
    },
  });

  const archiveMutation = useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.archive(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['auto-dialer-campaigns'] });
      toast.success('Campaign archived successfully');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Failed to archive campaign');
    },
  });

  const handleAction = (campaign: AutoDialerCampaign, action: 'start' | 'pause' | 'resume' | 'archive') => {
    setSelectedCampaign(campaign);
    setActionType(action);
    setIsActionDialogOpen(true);
  };

  const confirmAction = async () => {
    if (!selectedCampaign || !actionType) return;

    switch (actionType) {
      case 'start':
        await startMutation.mutateAsync(selectedCampaign.id);
        break;
      case 'pause':
        await pauseMutation.mutateAsync(selectedCampaign.id);
        break;
      case 'resume':
        await resumeMutation.mutateAsync(selectedCampaign.id);
        break;
      case 'archive':
        await archiveMutation.mutateAsync(selectedCampaign.id);
        break;
    }

    setIsActionDialogOpen(false);
    setSelectedCampaign(null);
    setActionType(null);
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

  const getActionTitle = () => {
    switch (actionType) {
      case 'start': return 'Start Campaign';
      case 'pause': return 'Pause Campaign';
      case 'resume': return 'Resume Campaign';
      case 'archive': return 'Archive Campaign';
      default: return '';
    }
  };

  const getActionDescription = () => {
    if (!selectedCampaign) return '';
    switch (actionType) {
      case 'start':
        return `Are you sure you want to start "${selectedCampaign.name}"? This will begin dialing calls.`;
      case 'pause':
        return `Are you sure you want to pause "${selectedCampaign.name}"? Active calls will complete but no new calls will be made.`;
      case 'resume':
        return `Are you sure you want to resume "${selectedCampaign.name}"?`;
      case 'archive':
        return `Are you sure you want to archive "${selectedCampaign.name}"? This action cannot be undone.`;
      default:
        return '';
    }
  };

  const campaigns = data?.data || [];
  const totalCampaigns = data?.meta?.total || 0;
  const totalPages = data?.meta?.last_page || 1;

  // Check if filters are active
  const hasActiveFilters = searchQuery || statusFilter !== 'all';

  // Clear all filters
  const clearFilters = () => {
    setSearchQuery('');
    setStatusFilter('all');
    setCurrentPage(1);
  };

  // Loading state
  if (isLoading) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <div className="h-8 w-32 bg-gray-200 animate-pulse rounded" />
          <div className="h-10 w-32 bg-gray-200 animate-pulse rounded" />
        </div>
        <Card>
          <CardContent className="p-6">
            <div className="h-64 w-full bg-gray-200 animate-pulse rounded" />
          </CardContent>
        </Card>
      </div>
    );
  }

  // Error state
  if (error) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-start">
          <div>
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Target className="h-8 w-8" />
              Campaign Manager
            </h1>
            <p className="text-muted-foreground mt-1">
              Manage and monitor your outbound calling campaigns
            </p>
            <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
              <span>Dashboard</span>
              <span>/</span>
              <span>Campaign Manager</span>
            </div>
          </div>
        </div>
        <Card>
          <CardContent className="p-6">
            <div className="text-center py-12">
              <PhoneCall className="h-12 w-12 mx-auto text-destructive mb-4" />
              <h3 className="text-lg font-semibold mb-2">Failed to load campaigns</h3>
              <p className="text-muted-foreground mb-4">
                {error instanceof Error ? error.message : 'An error occurred while loading campaigns'}
              </p>
              <Button onClick={() => refetch()}>
                Try Again
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Target className="h-8 w-8" />
              Campaign Manager
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">
            Manage and monitor your outbound calling campaigns
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Campaign Manager</span>
          </div>
        </div>
        {canManage && (
          <Button onClick={() => navigate('/ui/auto-dialer/campaigns/new')}>
            <Plus className="h-4 w-4 mr-2" />
            Create Campaign
          </Button>
        )}
      </div>

      {/* Filters Section */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3">
            {/* Search */}
            <div className="relative flex-1 min-w-[250px]">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search campaigns..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
                autoComplete="off"
              />
            </div>

            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              disabled={isRefetching}
              title="Refresh"
            >
              <RefreshCw className={cn('h-4 w-4', isRefetching && 'animate-spin')} />
            </Button>

            {/* Status Filter */}
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

            {/* Clear Filters */}
            {hasActiveFilters && (
              <Button variant="ghost" size="sm" onClick={clearFilters}>
                <X className="h-4 w-4 mr-2" />
                Clear Filters
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Campaigns Table */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<AutoDialerCampaign>
            data={campaigns}
            isLoading={isLoading}
            onRowClick={canManage ? ((campaign) => navigate(`/ui/auto-dialer/campaigns/${campaign.id}`)) : undefined}
            identityIcon={PhoneCall}
            identityIconBg="bg-purple-100"
            identityIconColor="text-purple-600"
            getIdentityPrimary={(campaign) => campaign.name}
            getIdentitySecondary={(campaign) => campaign.description || 'No description'}
            onIdentityClick={canManage ? ((campaign) => navigate(`/ui/auto-dialer/campaigns/${campaign.id}`)) : undefined}
            canView={false}
            canEdit={false}
            onDelete={canManage ? ((campaign) => handleAction(campaign, 'archive')) : undefined}
            canDelete={canManage}
            columns={[
              {
                header: 'Status',
                cell: (campaign) => (
                  <div className="flex items-center gap-2">
                    {getStatusBadge(campaign.status)}
                    {campaign.auto_start && (
                      <Badge variant="outline" className="text-xs">Auto-start</Badge>
                    )}
                  </div>
                )
              },
              {
                header: 'Destinations',
                cell: (campaign) => (
                  <span className="text-sm">{campaign.statistics.total_destinations} destinations</span>
                )
              },
              {
                header: 'CPS',
                cell: (campaign) => (
                  <span className="text-sm">{campaign.calls_per_second} CPS</span>
                )
              },
              {
                header: 'Routing',
                cell: (campaign) => (
                  <span className="text-sm text-muted-foreground">{campaign.routing_destination_label}</span>
                )
              },
              {
                header: 'Created',
                cell: (campaign) => (
                  <span className="text-sm text-muted-foreground">{formatDateTime(campaign.created_at)}</span>
                )
              },
              {
                header: 'Progress',
                cell: (campaign) => campaign.statistics.total_destinations > 0 ? (
                  <div className="w-32">
                    <div className="flex justify-between text-xs mb-1">
                      <span>{campaign.statistics.progress_percentage}%</span>
                    </div>
                    <div className="w-full bg-gray-200 rounded-full h-2">
                      <div
                        className="bg-blue-600 h-2 rounded-full transition-all"
                        style={{ width: `${campaign.statistics.progress_percentage}%` }}
                      />
                    </div>
                    <div className="flex justify-between text-xs mt-1 text-muted-foreground">
                      <span>{campaign.statistics.completed_calls} done</span>
                      <span>{campaign.statistics.pending_calls} left</span>
                    </div>
                  </div>
                ) : (
                  <span className="text-sm text-muted-foreground">-</span>
                )
              },
            ]}
            emptyState={
              <EmptyState
                icon={PhoneCall}
                title="No campaigns found"
                description={hasActiveFilters ? 'Try adjusting your filters' : 'Create your first campaign to get started'}
                action={canManage && !hasActiveFilters ? {
                  label: "Create Campaign",
                  onClick: () => navigate('/ui/auto-dialer/campaigns/new')
                } : undefined}
              />
            }
          />

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {((currentPage - 1) * perPage) + 1} to {Math.min(currentPage * perPage, totalCampaigns)} of {totalCampaigns} campaigns
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                  disabled={currentPage === 1}
                >
                  Previous
                </Button>
                <div className="text-sm">
                  Page {currentPage} of {totalPages}
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                  disabled={currentPage === totalPages}
                >
                  Next
                </Button>
              </div>
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
              {getActionTitle()}
            </DialogTitle>
            <DialogDescription>
              {getActionDescription()}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsActionDialogOpen(false)}>
              Cancel
            </Button>
            <Button
              variant={actionType === 'archive' ? 'destructive' : 'default'}
              onClick={confirmAction}
              disabled={startMutation.isPending || pauseMutation.isPending || resumeMutation.isPending || archiveMutation.isPending}
            >
              {actionType === 'start' && startMutation.isPending && <RefreshCw className="h-4 w-4 mr-2 animate-spin" />}
              {actionType === 'pause' && pauseMutation.isPending && <RefreshCw className="h-4 w-4 mr-2 animate-spin" />}
              {actionType === 'resume' && resumeMutation.isPending && <RefreshCw className="h-4 w-4 mr-2 animate-spin" />}
              {actionType === 'archive' && archiveMutation.isPending && <RefreshCw className="h-4 w-4 mr-2 animate-spin" />}
              Confirm
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
