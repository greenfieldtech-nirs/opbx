import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Plus, Search, Filter, RefreshCw, Target } from 'lucide-react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useAuth } from '@/hooks/useAuth';
import {
  StandardDataTable,
  Column,
  EmptyState,
} from '@/components/design-system';
import { ConfirmDialog } from '@/components/design-system/ConfirmDialog';
import {
  useCallTrackingCampaigns,
  useDeleteCallTrackingCampaign,
  useUpdateCallTrackingCampaign,
} from '@/hooks/useCallTrackingCampaigns';
import { cn } from '@/lib/utils';
import type { CallTrackingCampaign } from '@/types/callTracking';

export default function CallTrackingCampaigns() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const canManage = user?.role === 'owner' || user?.role === 'pbx_admin';
  const isReadOnly = ['reporter', 'pbx_user'].includes(user?.role || '');

  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<'all' | 'active' | 'inactive'>('all');
  const [sortField, setSortField] = useState<'name' | 'status' | 'created_at'>('name');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const perPage = 25;

  const [campaignToDelete, setCampaignToDelete] = useState<CallTrackingCampaign | null>(null);

  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1);
    }, 300);
    return () => clearTimeout(timer);
  }, [searchQuery]);

  const { data, isLoading, isError, error, refetch, isRefetching } = useCallTrackingCampaigns({
    search: debouncedSearch || undefined,
    status: statusFilter === 'all' ? undefined : statusFilter,
    page: currentPage,
    per_page: perPage,
    sort_by: sortField,
    sort_order: sortDirection,
  });

  const campaigns = data?.data ?? [];
  const totalCampaigns = data?.meta?.total ?? 0;
  const totalPages = data?.meta?.last_page ?? 1;

  const deleteMutation = useDeleteCallTrackingCampaign();
  const updateMutation = useUpdateCallTrackingCampaign();

  const hasActiveFilters = debouncedSearch !== '' || statusFilter !== 'all';

  const handleSort = (field: typeof sortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
    setCurrentPage(1);
  };

  const handleToggleStatus = (campaign: CallTrackingCampaign) => {
    if (updateMutation.isPending) return;
    const newStatus = campaign.status === 'active' ? 'inactive' : 'active';
    updateMutation.mutate({ id: campaign.id, data: { status: newStatus } });
  };

  const handleDelete = async () => {
    if (!campaignToDelete) return;
    try {
      await deleteMutation.mutateAsync(campaignToDelete.id);
      toast.success('Campaign deleted successfully');
      setCampaignToDelete(null);
    } catch (err) {
      toast.error((err as Error)?.message || 'Failed to delete campaign');
    }
  };

  const clearFilters = () => {
    setSearchQuery('');
    setStatusFilter('all');
    setCurrentPage(1);
  };

  const columns: Column<CallTrackingCampaign>[] = [
    {
      header: 'Source',
      sortKey: 'source',
      cell: (campaign) => (
        <span className="text-sm text-muted-foreground">
          {campaign.source || '—'}
        </span>
      ),
    },
    {
      header: 'Medium',
      sortKey: 'medium',
      cell: (campaign) => (
        <span className="text-sm text-muted-foreground">
          {campaign.medium || '—'}
        </span>
      ),
    },
    {
      header: 'Destination',
      cell: (campaign) => (
        <Badge variant="outline" className="capitalize">
          {campaign.destination_type.replace('_', ' ')}
        </Badge>
      ),
    },
    {
      header: 'Tracking Numbers',
      cell: (campaign) => (
        <span className="text-sm text-muted-foreground">
          {campaign.tracking_numbers_count ?? 0}
        </span>
      ),
    },
    {
      header: 'Status',
      sortKey: 'status',
      cell: (campaign) => (
        <Badge
          variant={campaign.status === 'active' ? 'default' : 'secondary'}
          className={cn(
            'text-xs',
            !isReadOnly && (
              updateMutation.isPending && updateMutation.variables?.id === campaign.id
                ? 'opacity-50 cursor-wait'
                : 'cursor-pointer transition-all hover:scale-105'
            ),
            campaign.status === 'active'
              ? 'bg-green-100 text-green-800 hover:bg-green-200'
              : 'bg-gray-100 text-gray-800 hover:bg-gray-200'
          )}
          onClick={(e) => {
            e.stopPropagation();
            if (!isReadOnly && !updateMutation.isPending) {
              handleToggleStatus(campaign);
            }
          }}
        >
          {updateMutation.isPending && updateMutation.variables?.id === campaign.id ? (
            <span className="flex items-center gap-1">
              <RefreshCw className="h-3 w-3 animate-spin" />
              {campaign.status === 'active' ? 'Active' : 'Inactive'}
            </span>
          ) : (
            campaign.status === 'active' ? 'Active' : 'Inactive'
          )}
        </Badge>
      ),
    },
  ];

  if (isError) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-start">
          <div>
            <div className="flex items-center gap-3">
              <h1 className="text-3xl font-bold flex items-center gap-2">
                <Target className="h-8 w-8" />
                Call Tracking Campaigns
              </h1>
            </div>
            <p className="text-muted-foreground mt-1">
              Manage marketing campaigns and their tracking numbers
            </p>
          </div>
        </div>
        <Card>
          <CardContent className="p-6 text-center">
            <p className="text-red-600 mb-4">Failed to load campaigns: {(error as Error)?.message || 'Unknown error'}</p>
            <Button onClick={() => refetch()}>Try Again</Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Target className="h-8 w-8" />
              Call Tracking Campaigns
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">
            Manage marketing campaigns and their tracking numbers
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Call Tracking Campaigns</span>
          </div>
        </div>
        {canManage && (
          <Button onClick={() => navigate('/ui/call-tracking/campaigns/new')}>
            <Plus className="h-4 w-4 mr-2" />
            New Campaign
          </Button>
        )}
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3">
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

            <Select
              value={statusFilter}
              onValueChange={(value) => {
                setStatusFilter(value as 'all' | 'active' | 'inactive');
                setCurrentPage(1);
              }}
            >
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            {hasActiveFilters && (
              <Button variant="ghost" size="sm" onClick={clearFilters}>
                Clear Filters
              </Button>
            )}
          </div>
        </CardContent>
      </Card>

      {/* Table */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<CallTrackingCampaign>
            data={campaigns}
            isLoading={isLoading}
            onRowClick={canManage ? (campaign) => navigate(`/ui/call-tracking/campaigns/${campaign.id}`) : undefined}
            identityIcon={Target}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(campaign) => campaign.name}
            getIdentitySecondary={(campaign) => 'Call Tracking Campaign'}
            onIdentityClick={canManage ? (campaign) => navigate(`/ui/call-tracking/campaigns/${campaign.id}`) : undefined}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={handleSort}
            canView={false}
            canEdit={false}
            onDelete={canManage ? (campaign) => setCampaignToDelete(campaign) : undefined}
            canDelete={canManage}
            columns={columns}
            emptyState={
              <EmptyState
                icon={Target}
                title="No campaigns found"
                description={hasActiveFilters ? 'Try adjusting your filters' : 'Get started by creating your first campaign'}
                action={canManage && !hasActiveFilters ? {
                  label: 'New Campaign',
                  onClick: () => navigate('/ui/call-tracking/campaigns/new')
                } : undefined}
              />
            }
          />

          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalCampaigns)} of {totalCampaigns} campaigns
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
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
                  onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
                  disabled={currentPage === totalPages}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      <ConfirmDialog
        open={!!campaignToDelete}
        onOpenChange={(open) => !open && setCampaignToDelete(null)}
        title="Delete Campaign"
        description={`Are you sure you want to delete "${campaignToDelete?.name}"? This action cannot be undone.`}
        confirmLabel="Delete"
        onConfirm={handleDelete}
        variant="danger"
        loading={deleteMutation.isPending}
      />
    </div>
  );
}
