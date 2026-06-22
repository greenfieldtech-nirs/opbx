import { useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { Plus, Search, LayoutGrid, List } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Card, CardContent } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { useAuth } from '@/hooks/useAuth';
import {
  useCallTrackingCampaigns,
  useDeleteCallTrackingCampaign,
} from '@/hooks/useCallTrackingCampaigns';
import type { CallTrackingCampaign } from '@/types/callTracking';

export default function CallTrackingCampaigns() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<'' | 'active' | 'inactive'>('');
  const [viewMode, setViewMode] = useState<'table' | 'grid'>('table');
  const canCreate = user?.role === 'owner' || user?.role === 'pbx_admin';

  const { data, isLoading, isError, error } = useCallTrackingCampaigns({
    search: search || undefined,
    status: status || undefined,
  });

  const deleteMutation = useDeleteCallTrackingCampaign();

  const campaigns = data?.data ?? [];
  const meta = data?.meta;
  const hasActiveFilters = search !== '' || status !== '';

  const handleDelete = (campaign: CallTrackingCampaign) => {
    if (!window.confirm(`Delete campaign "${campaign.name}"?`)) return;
    deleteMutation.mutate(campaign.id);
  };

  if (isLoading) {
    return <p className="p-6 text-muted-foreground">Loading campaigns...</p>;
  }

  if (isError) {
    return (
      <div className="p-6">
        <p className="text-red-600">Failed to load campaigns: {(error as Error)?.message || 'Unknown error'}</p>
      </div>
    );
  }

  return (
    <div className="p-6 space-y-4">
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <h1 className="text-2xl font-bold">Call Tracking Campaigns</h1>
        {canCreate && (
          <Button onClick={() => navigate('/ui/call-tracking/campaigns/new')}>
            <Plus className="h-4 w-4 mr-2" />
            New Campaign
          </Button>
        )}
      </div>

      <div className="flex flex-col sm:flex-row gap-2">
        <div className="relative flex-1">
          <Search className="absolute left-2.5 top-2.5 h-4 w-4 text-muted-foreground" />
          <Input
            placeholder="Search campaigns..."
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="pl-9"
          />
        </div>
        <Select value={status} onValueChange={(value) => setStatus(value as '' | 'active' | 'inactive')}>
          <SelectTrigger className="w-[160px]">
            <SelectValue placeholder="All statuses" />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="">All statuses</SelectItem>
            <SelectItem value="active">Active</SelectItem>
            <SelectItem value="inactive">Inactive</SelectItem>
          </SelectContent>
        </Select>
        <div className="flex items-center gap-1">
          <Button variant={viewMode === 'table' ? 'default' : 'outline'} size="icon" onClick={() => setViewMode('table')}>
            <List className="h-4 w-4" />
          </Button>
          <Button variant={viewMode === 'grid' ? 'default' : 'outline'} size="icon" onClick={() => setViewMode('grid')}>
            <LayoutGrid className="h-4 w-4" />
          </Button>
        </div>
      </div>

      {campaigns.length === 0 ? (
        <Card>
          <CardContent className="text-center py-12">
            <List className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No campaigns found</h3>
            <p className="text-muted-foreground mb-4">
              {hasActiveFilters
                ? 'Try adjusting your filters'
                : 'Get started by creating your first campaign'}
            </p>
            {canCreate && !hasActiveFilters && (
              <Button onClick={() => navigate('/ui/call-tracking/campaigns/new')}>
                <Plus className="h-4 w-4 mr-2" />
                New Campaign
              </Button>
            )}
          </CardContent>
        </Card>
      ) : viewMode === 'table' ? (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Name</TableHead>
                <TableHead>Source</TableHead>
                <TableHead>Medium</TableHead>
                <TableHead>Status</TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {campaigns.map((campaign) => (
                <TableRow key={campaign.id}>
                  <TableCell className="font-medium">
                    <Link to={`/ui/call-tracking/campaigns/${campaign.id}`} className="hover:underline">
                      {campaign.name}
                    </Link>
                  </TableCell>
                  <TableCell>{campaign.source || '—'}</TableCell>
                  <TableCell>{campaign.medium || '—'}</TableCell>
                  <TableCell>
                    <Badge variant={campaign.status === 'active' ? 'default' : 'secondary'}>
                      {campaign.status}
                    </Badge>
                  </TableCell>
                  <TableCell className="text-right">
                    <div className="flex justify-end gap-2">
                      <Button variant="outline" size="sm" onClick={() => navigate(`/ui/call-tracking/campaigns/${campaign.id}`)}>
                        View
                      </Button>
                      {canCreate && (
                        <>
                          <Button variant="outline" size="sm" onClick={() => navigate(`/ui/call-tracking/campaigns/${campaign.id}/edit`)}>
                            Edit
                          </Button>
                          <Button variant="destructive" size="sm" onClick={() => handleDelete(campaign)}>
                            Delete
                          </Button>
                        </>
                      )}
                    </div>
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      ) : (
        <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
          {campaigns.map((campaign) => (
            <Card key={campaign.id}>
              <CardContent className="p-4 space-y-2">
                <div className="flex items-center justify-between">
                  <Link to={`/ui/call-tracking/campaigns/${campaign.id}`} className="font-semibold hover:underline">
                    {campaign.name}
                  </Link>
                  <Badge variant={campaign.status === 'active' ? 'default' : 'secondary'}>{campaign.status}</Badge>
                </div>
                <p className="text-sm text-muted-foreground">Source: {campaign.source || '—'} · Medium: {campaign.medium || '—'}</p>
                <div className="flex gap-2 pt-2">
                  <Button variant="outline" size="sm" onClick={() => navigate(`/ui/call-tracking/campaigns/${campaign.id}`)}>View</Button>
                  {canCreate && (
                    <>
                      <Button variant="outline" size="sm" onClick={() => navigate(`/ui/call-tracking/campaigns/${campaign.id}/edit`)}>Edit</Button>
                      <Button variant="destructive" size="sm" onClick={() => handleDelete(campaign)}>Delete</Button>
                    </>
                  )}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}

      {meta && meta.total > meta.per_page && (
        <p className="text-sm text-muted-foreground">
          Showing {campaigns.length} of {meta.total} campaigns
        </p>
      )}
    </div>
  );
}
