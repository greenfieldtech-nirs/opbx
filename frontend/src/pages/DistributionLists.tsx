import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Plus,
  Search,
  MoreVertical,
  Download,
  Upload,
  Copy,
  Archive,
  FileSpreadsheet,
  RefreshCw,
  AlertCircle,
  Trash2,
  Link2,
  Unlink,
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
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
// Pagination component - simple implementation
import { useAuth } from '@/hooks/useAuth';
import { distributionListKeys, useDistributionLists, useArchiveList, useDownloadExample, useDeleteList, useUnassignListFromCampaign } from '@/hooks/useDistributionLists';
import { useAutoDialerCampaigns } from '@/hooks/useAutoDialerCampaigns';
import { cn } from '@/lib/utils';
import { useQueryClient } from '@tanstack/react-query';
import { DistributionListsLoading } from './DistributionLists/components/DistributionListsLoading';
import { DistributionListsEmpty } from './DistributionLists/components/DistributionListsEmpty';
import { CreateListDialog } from './DistributionLists/components/CreateListDialog';
import { CopyListDialog } from './DistributionLists/components/CopyListDialog';
import { ArchiveListDialog } from './DistributionLists/components/ArchiveListDialog';
import { UnifiedUploadDialog } from './DistributionLists/components/UnifiedUploadDialog';
import { ValidationErrorsDialog } from './DistributionLists/components/ValidationErrorsDialog';
import { DeleteListDialog } from './DistributionLists/components/DeleteListDialog';
import { AssignCampaignDialog } from './DistributionLists/components/AssignCampaignDialog';
import { toast } from 'sonner';
import type { AutoDialerList, DistributionListStatus } from '@/types';

const statusColors: Record<DistributionListStatus, string> = {
  draft: 'bg-gray-100 text-gray-800',
  pending: 'bg-yellow-100 text-yellow-800',
  processing: 'bg-blue-100 text-blue-800',
  ready: 'bg-green-100 text-green-800',
  failed: 'bg-red-100 text-red-800',
  in_use: 'bg-purple-100 text-purple-800',
  used: 'bg-orange-100 text-orange-800',
  archived: 'bg-gray-100 text-gray-500',
};

export default function DistributionLists() {
  const navigate = useNavigate();
  const { user } = useAuth();
  const canManage = user?.role === 'owner' || user?.role === 'pbx_admin';

  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<string>('active');
  const [page, setPage] = useState(1);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [copyList, setCopyList] = useState<AutoDialerList | null>(null);
  const [archiveList, setArchiveList] = useState<AutoDialerList | null>(null);
  const [deleteList, setDeleteList] = useState<AutoDialerList | null>(null);
  const [uploadList, setUploadList] = useState<AutoDialerList | null>(null);
  const [errorsList, setErrorsList] = useState<AutoDialerList | null>(null);
  const [assignList, setAssignList] = useState<AutoDialerList | null>(null);

  const { data, isLoading, isFetching, error } = useDistributionLists({
    page,
    per_page: 25,
    search: search || undefined,
    status: status && status !== 'all' && status !== 'active' ? status as DistributionListStatus : undefined,
  });

  const archiveMutation = useArchiveList();
  const deleteMutation = useDeleteList();
  const unassignMutation = useUnassignListFromCampaign();
  const downloadExampleMutation = useDownloadExample();
  const queryClient = useQueryClient();

  // Fetch campaigns for assignment dialog
  const { data: campaignsData } = useAutoDialerCampaigns({ per_page: 100 });

  const handleDownloadExample = async () => {
    try {
      const blob = await downloadExampleMutation.mutateAsync();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = 'distribution_list_example.csv';
      a.click();
      window.URL.revokeObjectURL(url);
    } catch {
      toast.error('Failed to download example');
    }
  };

  const handleArchive = async () => {
    if (!archiveList) return;

    try {
      await archiveMutation.mutateAsync(archiveList.id);
      toast.success('List archived successfully');
      setArchiveList(null);
    } catch {
      toast.error('Failed to archive list');
    }
  };

  const handleUnassign = async (listId: number) => {
    try {
      await unassignMutation.mutateAsync(listId);
      toast.success('List unassigned from campaign successfully');
    } catch {
      toast.error('Failed to unassign list from campaign');
    }
  };

  const handleDelete = async () => {
    if (!deleteList) return;

    try {
      await deleteMutation.mutateAsync(deleteList.id);
      toast.success('List deleted successfully');
      setDeleteList(null);
    } catch {
      toast.error('Failed to delete list');
    }
  };

  const handleUploadSuccess = () => {
    // Refresh the list to show updated status
    queryClient.invalidateQueries({ queryKey: distributionListKeys.all });
  };

  if (isLoading) {
    return <DistributionListsLoading />;
  }

  if (error) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-start">
          <div>
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <FileSpreadsheet className="h-8 w-8" />
              Distribution Lists
            </h1>
            <p className="text-muted-foreground mt-1">
              Manage phone number lists for auto-dialer campaigns
            </p>
          </div>
        </div>
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <AlertCircle className="h-12 w-12 text-red-500 mb-4" />
            <p className="text-red-600">Failed to load distribution lists</p>
          </CardContent>
        </Card>
      </div>
    );
  }

  const lists = data?.data.filter(list => status !== 'active' || list.status !== 'archived') || [];
  const meta = data?.meta;

  return (
    <div className="space-y-6">
      {/* Page Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <FileSpreadsheet className="h-8 w-8" />
            Distribution Lists
          </h1>
          <p className="text-muted-foreground mt-1">
            Manage phone number lists for auto-dialer campaigns
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Distribution Lists</span>
          </div>
        </div>
        <div className="flex gap-2">
          <Button variant="outline" onClick={handleDownloadExample}>
            <Download className="h-4 w-4 mr-2" />
            Example CSV
          </Button>
          {canManage && (
            <Button onClick={() => setIsCreateOpen(true)}>
              <Plus className="h-4 w-4 mr-2" />
              Create List
            </Button>
          )}
        </div>
      </div>

      {/* Filters Section */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3">
            {/* Search */}
            <div className="relative flex-1 min-w-[250px]">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search lists..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-9"
                autoComplete="off"
              />
            </div>

            <Button
              variant="outline"
              size="icon"
              onClick={() => queryClient.invalidateQueries({ queryKey: distributionListKeys.all })}
              disabled={isFetching}
              title="Refresh"
            >
              <RefreshCw className={cn('h-4 w-4', isFetching && 'animate-spin')} />
            </Button>

            {/* Status Filter */}
            <Select
              value={status || 'active'}
              onValueChange={(v) => setStatus(v)}
            >
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Filter by status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="active">All (except Archived)</SelectItem>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="draft">Draft</SelectItem>
                <SelectItem value="ready">Ready</SelectItem>
                <SelectItem value="processing">Processing</SelectItem>
                <SelectItem value="failed">Failed</SelectItem>
                <SelectItem value="in_use">In Use</SelectItem>
                <SelectItem value="used">Used</SelectItem>
                <SelectItem value="archived">Archived</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Lists Table */}
      <Card>
        <CardContent>
          {lists.length === 0 ? (
            <DistributionListsEmpty />
          ) : (
            <>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Name</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Version</TableHead>
                    <TableHead>Destinations</TableHead>
                    <TableHead>Campaign</TableHead>
                    <TableHead>Created</TableHead>
                    <TableHead className="w-[50px]" />
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {lists.map((list) => (
                    <TableRow
                      key={list.id}
                      className="cursor-pointer hover:bg-muted/50"
                      onClick={() => navigate(`/ui/auto-dialer/distribution-lists/${list.id}`)}
                    >
                      <TableCell className="font-medium">
                        {list.name}
                        {!list.is_latest_version && (
                          <span className="ml-2 text-xs text-muted-foreground">(old version)</span>
                        )}
                      </TableCell>
                      <TableCell>
                        <Badge className={statusColors[list.status]}>
                          {list.status_label}
                        </Badge>
                      </TableCell>
                      <TableCell>v{list.version_number}</TableCell>
                      <TableCell>
                        {list.statistics.valid_rows.toLocaleString()}
                        {list.statistics.invalid_rows > 0 && (
                          <span className="text-red-500 ml-1">
                            ({list.statistics.invalid_rows} invalid)
                          </span>
                        )}
                      </TableCell>
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        {(list.campaign?.name || list.campaign_id || list.status === 'in_use') ? (
                          <div className="flex items-center gap-2">
                            {canManage && (
                              <Button
                                variant="ghost"
                                size="icon"
                                className="h-6 w-6 text-red-600 hover:text-red-800"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  handleUnassign(list.id);
                                }}
                                disabled={unassignMutation.isPending}
                                title="Unassign from campaign"
                              >
                                <Unlink className="h-4 w-4" />
                              </Button>
                            )}
                            <span className="text-sm">{list.campaign?.name || 'Assigned'}</span>
                          </div>
                        ) : list.can_assign && list.status === 'ready' && canManage ? (
                          <Button
                            variant="outline"
                            size="sm"
                            onClick={(e) => {
                              e.stopPropagation();
                              setAssignList(list);
                            }}
                          >
                            Assign Campaign
                          </Button>
                        ) : (
                          <span className="text-muted-foreground">-</span>
                        )}
                      </TableCell>
                      <TableCell>
                        {new Date(list.created_at).toLocaleDateString()}
                      </TableCell>
                      <TableCell>
                        <DropdownMenu>
                          <DropdownMenuTrigger asChild onClick={(e) => e.stopPropagation()}>
                            <Button variant="ghost" size="icon">
                              <MoreVertical className="h-4 w-4" />
                            </Button>
                          </DropdownMenuTrigger>
                          <DropdownMenuContent align="end">
                            <DropdownMenuItem onClick={(e) => {
                              e.stopPropagation();
                              navigate(`/ui/auto-dialer/distribution-lists/${list.id}`);
                            }}>
                              View Details
                            </DropdownMenuItem>
                            {list.can_upload && canManage && (
                              <DropdownMenuItem onClick={(e) => {
                                e.stopPropagation();
                                setUploadList(list);
                              }}>
                                <Upload className="h-4 w-4 mr-2" />
                                Upload Destinations
                              </DropdownMenuItem>
                            )}
                            {list.can_copy && canManage && (
                              <DropdownMenuItem onClick={(e) => {
                                e.stopPropagation();
                                setCopyList(list);
                              }}>
                                <Copy className="h-4 w-4 mr-2" />
                                Copy List
                              </DropdownMenuItem>
                            )}
                            {list.status === 'failed' && (
                              <DropdownMenuItem onClick={(e) => {
                                e.stopPropagation();
                                setErrorsList(list);
                              }}>
                                <AlertCircle className="h-4 w-4 mr-2" />
                                View Errors
                              </DropdownMenuItem>
                            )}
                            {list.can_archive && canManage && list.status !== 'failed' && (
                              <DropdownMenuItem
                                className="text-red-600"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  setArchiveList(list);
                                }}
                              >
                                <Archive className="h-4 w-4 mr-2" />
                                Archive
                              </DropdownMenuItem>
                            )}
                            {list.status === 'failed' && canManage && (
                              <DropdownMenuItem
                                className="text-red-600"
                                onClick={(e) => {
                                  e.stopPropagation();
                                  setDeleteList(list);
                                }}
                              >
                                <Trash2 className="h-4 w-4 mr-2" />
                                Delete
                              </DropdownMenuItem>
                            )}
                          </DropdownMenuContent>
                        </DropdownMenu>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>

              {/* Pagination */}
              {meta && meta.last_page > 1 && (
                <div className="mt-4 flex justify-center gap-2">
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setPage(page - 1)}
                    disabled={page === 1}
                  >
                    Previous
                  </Button>
                  <span className="px-4 py-2 text-sm">
                    Page {page} of {meta.last_page}
                  </span>
                  <Button
                    variant="outline"
                    size="sm"
                    onClick={() => setPage(page + 1)}
                    disabled={page === meta.last_page}
                  >
                    Next
                  </Button>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      {/* Dialogs */}
      <CreateListDialog open={isCreateOpen} onOpenChange={setIsCreateOpen} />

      {copyList && (
        <CopyListDialog
          list={copyList}
          open={!!copyList}
          onOpenChange={() => setCopyList(null)}
        />
      )}

      {archiveList && (
        <ArchiveListDialog
          list={archiveList}
          open={!!archiveList}
          onOpenChange={() => setArchiveList(null)}
          onConfirm={handleArchive}
          isArchiving={archiveMutation.isPending}
        />
      )}

      {uploadList && (
        <UnifiedUploadDialog
          list={uploadList}
          open={!!uploadList}
          onOpenChange={() => setUploadList(null)}
          onSuccess={() => {
            handleUploadSuccess();
            // Same list is updated, no navigation needed
          }}
        />
      )}

      {errorsList && (
        <ValidationErrorsDialog
          list={errorsList}
          open={!!errorsList}
          onOpenChange={() => setErrorsList(null)}
        />
      )}

      {deleteList && (
        <DeleteListDialog
          list={deleteList}
          open={!!deleteList}
          onOpenChange={() => setDeleteList(null)}
          onConfirm={handleDelete}
          isDeleting={deleteMutation.isPending}
        />
      )}

      {assignList && (
        <AssignCampaignDialog
          list={assignList}
          open={!!assignList}
          onOpenChange={() => setAssignList(null)}
          campaigns={campaignsData?.data || []}
        />
      )}
    </div>
  );
}
