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
  Edit,
  Archive,
  Trash2,
  PhoneCall,
  RefreshCw,
  X,
  Target,
  List,
  Users,
  Phone,
  Shuffle,
  ListOrdered,
  Timer,
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
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import {
  StandardDataTable,
  EmptyState,
} from '@/components/design-system';
import type { AutoDialerCampaign } from '@/services/autoDialerCampaignsApi';
import { formatDateTime } from '@/utils/formatters';

// Status configurations
const statusConfigs: Record<string, { color: string; label: string; nextStatus: string | null; nextLabel: string }> = {
  draft: { 
    color: 'bg-gray-100 text-gray-800 border-gray-200 hover:bg-gray-200', 
    label: 'Draft',
    nextStatus: 'active',
    nextLabel: 'Start'
  },
  active: { 
    color: 'bg-green-100 text-green-800 border-green-200 hover:bg-green-200', 
    label: 'Running',
    nextStatus: 'paused',
    nextLabel: 'Pause'
  },
  paused: { 
    color: 'bg-yellow-100 text-yellow-800 border-yellow-200 hover:bg-yellow-200', 
    label: 'Paused',
    nextStatus: 'active',
    nextLabel: 'Resume'
  },
  completed: { 
    color: 'bg-blue-100 text-blue-800 border-blue-200', 
    label: 'Completed',
    nextStatus: null,
    nextLabel: ''
  },
  archived: { 
    color: 'bg-red-100 text-red-800 border-red-200', 
    label: 'Archived',
    nextStatus: null,
    nextLabel: ''
  },
};

function getStrategyIcon(strategy: string) {
  switch (strategy) {
    case 'round_robin':
      return <ListOrdered className="h-3 w-3" />;
    case 'random':
      return <Shuffle className="h-3 w-3" />;
    case 'least_recently_used':
      return <Timer className="h-3 w-3" />;
    default:
      return <Phone className="h-3 w-3" />;
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

export default function AutoDialerCampaigns() {
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // UI state
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  // Default: show all except archived
  const [statusFilter, setStatusFilter] = useState<'all' | 'draft' | 'active' | 'paused' | 'completed' | 'archived' | 'not-archived'>('not-archived');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const [selectedCampaign, setSelectedCampaign] = useState<AutoDialerCampaign | null>(null);
  const [isArchiveDialogOpen, setIsArchiveDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);

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
      status: statusFilter !== 'all' && statusFilter !== 'not-archived' ? statusFilter : undefined,
    }],
    queryFn: () => autoDialerCampaignsApi.getAll({
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      status: statusFilter !== 'all' && statusFilter !== 'not-archived' ? statusFilter : undefined,
    }),
  });

  // Status toggle mutation
  const toggleStatusMutation = useMutation({
    mutationFn: ({ id, currentStatus, newStatus }: { id: string; currentStatus: string; newStatus: string }) => {
      switch (newStatus) {
        case 'active':
          // Use resume for paused→active, start for draft→active
          return currentStatus === 'paused'
            ? autoDialerCampaignsApi.resume(id)
            : autoDialerCampaignsApi.start(id);
        case 'paused':
          return autoDialerCampaignsApi.pause(id);
        default:
          throw new Error('Invalid status transition');
      }
    },
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['auto-dialer-campaigns'] });
      toast.success('Campaign status updated');
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Failed to update campaign status');
    },
  });

  // Archive mutation
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

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: string) => autoDialerCampaignsApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['auto-dialer-campaigns'] });
      toast.success('Campaign deleted successfully');
      setIsDeleteDialogOpen(false);
    },
    onError: (error: any) => {
      toast.error(error?.response?.data?.message || 'Failed to delete campaign');
    },
  });

  const handleStatusToggle = (campaign: AutoDialerCampaign) => {
    if (!canManage) return;
    
    const config = statusConfigs[campaign.status];
    if (!config.nextStatus) return; // Cannot toggle (completed/archived)

    toggleStatusMutation.mutate({
      id: campaign.id,
      currentStatus: campaign.status,
      newStatus: config.nextStatus,
    });
  };

  const handleArchive = (campaign: AutoDialerCampaign) => {
    setSelectedCampaign(campaign);
    setIsArchiveDialogOpen(true);
  };

  const confirmArchive = async () => {
    if (!selectedCampaign) return;
    await archiveMutation.mutateAsync(selectedCampaign.id);
    setIsArchiveDialogOpen(false);
    setSelectedCampaign(null);
  };

  const handleDelete = (campaign: AutoDialerCampaign) => {
    setSelectedCampaign(campaign);
    setIsDeleteDialogOpen(true);
  };

  const confirmDelete = () => {
    if (selectedCampaign) {
      deleteMutation.mutate(selectedCampaign.id);
    }
  };

  // Filter campaigns client-side when excluding archived
  const allCampaigns = data?.data || [];
  const campaigns = statusFilter === 'not-archived'
    ? allCampaigns.filter(c => c.status !== 'archived')
    : allCampaigns;
  const totalCampaigns = statusFilter === 'not-archived' ? campaigns.length : (data?.meta?.total || 0);
  const totalPages = statusFilter === 'not-archived' ? 1 : (data?.meta?.last_page || 1);

  // Check if filters are active
  const hasActiveFilters = searchQuery || statusFilter !== 'not-archived';

  // Clear all filters
  const clearFilters = () => {
    setSearchQuery('');
    setStatusFilter('not-archived');
    setCurrentPage(1);
  };

  // Helper to check if campaign has Caller ID pool
  const hasCallerIdPool = (campaign: AutoDialerCampaign): boolean => {
    return !!(
      (campaign as any).caller_id_pool?.length > 0 ||
      (campaign as any).caller_id_strategy
    );
  };

  // Get Caller ID pool count
  const getCallerIdPoolCount = (campaign: AutoDialerCampaign): number => {
    return (campaign as any).caller_id_pool?.length || 0;
  };

  // Get Caller ID strategy
  const getCallerIdStrategy = (campaign: AutoDialerCampaign): string => {
    return (campaign as any).caller_id_strategy || 'round_robin';
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
              <Button onClick={() => refetch()} disabled={isRefetching}>
                <RefreshCw className={cn('h-4 w-4 mr-2', isRefetching && 'animate-spin')} />
                Try Again
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <TooltipProvider>
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
                  <SelectItem value="not-archived">All (except Archived)</SelectItem>
                  <SelectItem value="all">All Statuses</SelectItem>
                  <SelectItem value="draft">Draft</SelectItem>
                  <SelectItem value="active">Running</SelectItem>
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
              getIdentitySecondary={(campaign) => {
                // Show Caller ID pool info if available
                if (hasCallerIdPool(campaign)) {
                  const poolCount = getCallerIdPoolCount(campaign);
                  const strategy = getCallerIdStrategy(campaign);
                  return `${poolCount} Caller ID${poolCount !== 1 ? 's' : ''} • ${getStrategyLabel(strategy)}`;
                }
                return campaign.caller_id || 'No Caller ID';
              }}
              onIdentityClick={canManage ? ((campaign) => navigate(`/ui/auto-dialer/campaigns/${campaign.id}`)) : undefined}
              canView={false}
              canEdit={false}
              canDelete={false}
              columns={[
                {
                  header: 'Status',
                  cell: (campaign) => {
                    const config = statusConfigs[campaign.status];
                    const canToggle = canManage && config.nextStatus !== null && campaign.status !== 'archived';
                    
                    return (
                      <Badge 
                        variant="outline" 
                        className={cn(
                          'text-xs cursor-default',
                          config.color,
                          canToggle && 'cursor-pointer'
                        )}
                        onClick={(e) => {
                          if (canToggle) {
                            e.stopPropagation();
                            handleStatusToggle(campaign);
                          }
                        }}
                        title={canToggle ? `Click to ${config.nextLabel.toLowerCase()}` : undefined}
                      >
                        {config.label}
                        {canToggle && (
                          <span className="ml-1 opacity-70">({config.nextLabel})</span>
                        )}
                      </Badge>
                    );
                  }
                },
                {
                  header: 'Caller ID',
                  cell: (campaign) => {
                    if (hasCallerIdPool(campaign)) {
                      const poolCount = getCallerIdPoolCount(campaign);
                      const strategy = getCallerIdStrategy(campaign);
                      return (
                        <Tooltip>
                          <TooltipTrigger asChild>
                            <Badge variant="secondary" className="gap-1 cursor-help">
                              <Users className="h-3 w-3" />
                              {poolCount} Caller ID{poolCount !== 1 ? 's' : ''}
                            </Badge>
                          </TooltipTrigger>
                          <TooltipContent>
                            <div className="flex items-center gap-2">
                              {getStrategyIcon(strategy)}
                              <span>Using {getStrategyLabel(strategy)} strategy</span>
                            </div>
                          </TooltipContent>
                        </Tooltip>
                      );
                    }
                    return (
                      <span className="text-sm text-muted-foreground">
                        {campaign.caller_id || '-'}
                      </span>
                    );
                  }
                },
                {
                  header: 'Lists',
                  cell: (campaign) => (
                    <span className="text-sm">{campaign.lists_count > 0 ? `${campaign.lists_count} list${campaign.lists_count !== 1 ? 's' : ''}` : 'No lists'}</span>
                  )
                },
                {
                  header: 'CAC',
                  cell: (campaign) => (
                    <span className="text-sm">{campaign.concurrent_active_calls} concurrent</span>
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
                  header: 'Actions',
                  className: 'w-[120px]',
                  cell: (campaign) => {
                    if (!canManage) return null;
                    
                    // Archived campaigns cannot be edited or archived again
                    if (campaign.status === 'archived') {
                      return <span className="text-xs text-muted-foreground">-</span>;
                    }
                    
                    // Draft campaigns can be deleted or edited
                    if (campaign.status === 'draft') {
                      return (
                        <div className="flex gap-1">
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-8 text-xs px-2"
                            onClick={(e) => {
                              e.stopPropagation();
                              navigate(`/ui/auto-dialer/campaigns/${campaign.id}/edit`);
                            }}
                          >
                            <Edit className="h-3.5 w-3.5" />
                          </Button>
                          <Button
                            variant="outline"
                            size="sm"
                            className="h-8 text-xs px-2 border-red-200 text-red-600 hover:bg-red-50 hover:text-red-700"
                            onClick={(e) => {
                              e.stopPropagation();
                              handleDelete(campaign);
                            }}
                          >
                            <Trash2 className="h-3.5 w-3.5" />
                          </Button>
                        </div>
                      );
                    }
                    
                    // Completed campaigns can only be archived
                    if (campaign.status === 'completed') {
                      return (
                        <Button
                          variant="outline"
                          size="sm"
                          className="h-8 text-xs"
                          onClick={(e) => {
                            e.stopPropagation();
                            handleArchive(campaign);
                          }}
                        >
                          <Archive className="h-3.5 w-3.5 mr-1" />
                          Archive
                        </Button>
                      );
                    }
                    
                    // Active/paused campaigns: show Edit (disabled when active)
                    const canEdit = campaign.status !== 'active';
                    return (
                      <Button
                        variant="outline"
                        size="sm"
                        className="h-8 text-xs"
                        disabled={!canEdit}
                        onClick={(e) => {
                          e.stopPropagation();
                          if (canEdit) {
                            navigate(`/ui/auto-dialer/campaigns/${campaign.id}/edit`);
                          }
                        }}
                        title={!canEdit ? 'Pause the campaign to edit' : undefined}
                      >
                        <Edit className="h-3.5 w-3.5 mr-1" />
                        Edit
                      </Button>
                    );
                  }
                },
              ]}
              emptyState={
                <EmptyState
                  icon={List}
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
            {(statusFilter === 'not-archived' ? campaigns.length > perPage : totalPages > 1) && (
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

        {/* Archive Confirmation Dialog */}
        <Dialog open={isArchiveDialogOpen} onOpenChange={setIsArchiveDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <Archive className="h-5 w-5" />
                Archive Campaign
              </DialogTitle>
              <DialogDescription>
                {selectedCampaign && (
                  <>Are you sure you want to archive "{selectedCampaign.name}"? This action cannot be undone.</>
                )}
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setIsArchiveDialogOpen(false)}>
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={confirmArchive}
                disabled={archiveMutation.isPending}
              >
                {archiveMutation.isPending && <RefreshCw className="h-4 w-4 mr-2 animate-spin" />}
                Archive
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>

        {/* Delete Confirmation Dialog */}
        <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
          <DialogContent>
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2">
                <Trash2 className="h-5 w-5" />
                Delete Campaign
              </DialogTitle>
              <DialogDescription>
                {selectedCampaign && (
                  <>Are you sure you want to delete "{selectedCampaign.name}"? This action cannot be undone. Associated lists will remain in the system.</>
                )}
              </DialogDescription>
            </DialogHeader>
            <DialogFooter>
              <Button variant="outline" onClick={() => setIsDeleteDialogOpen(false)}>
                Cancel
              </Button>
              <Button
                variant="destructive"
                onClick={confirmDelete}
                disabled={deleteMutation.isPending}
              >
                {deleteMutation.isPending && <RefreshCw className="h-4 w-4 mr-2 animate-spin" />}
                Delete
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </TooltipProvider>
  );
}
