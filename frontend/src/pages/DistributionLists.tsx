import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import {
  Plus,
  Search,
  Filter,
  MoreVertical,
  Download,
  Upload,
  Copy,
  Archive,
  FileSpreadsheet,
  RefreshCw,
  AlertCircle,
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
import { useDistributionLists, useArchiveList, useDownloadExample } from '@/hooks/useDistributionLists';
import { DistributionListsLoading } from './components/DistributionListsLoading';
import { DistributionListsEmpty } from './components/DistributionListsEmpty';
import { CreateListDialog } from './components/CreateListDialog';
import { CopyListDialog } from './components/CopyListDialog';
import { ArchiveListDialog } from './components/ArchiveListDialog';
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
  const [status, setStatus] = useState<DistributionListStatus | ''>('');
  const [page, setPage] = useState(1);
  const [isCreateOpen, setIsCreateOpen] = useState(false);
  const [copyList, setCopyList] = useState<AutoDialerList | null>(null);
  const [archiveList, setArchiveList] = useState<AutoDialerList | null>(null);

  const { data, isLoading, error } = useDistributionLists({
    page,
    per_page: 25,
    search: search || undefined,
    status: status || undefined,
  });

  const archiveMutation = useArchiveList();
  const downloadExampleMutation = useDownloadExample();

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

  if (isLoading) {
    return <DistributionListsLoading />;
  }

  if (error) {
    return (
      <div className="container mx-auto p-6">
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <AlertCircle className="h-12 w-12 text-red-500 mb-4" />
            <p className="text-red-600">Failed to load distribution lists</p>
          </CardContent>
        </Card>
      </div>
    );
  }

  const lists = data?.data || [];
  const meta = data?.meta;

  return (
    <div className="container mx-auto p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
          <h1 className="text-3xl font-bold tracking-tight">Distribution Lists</h1>
          <p className="text-muted-foreground">Manage phone number lists for auto-dialer campaigns</p>
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

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-col sm:flex-row gap-4">
            <div className="relative flex-1">
              <Search className="absolute left-3 top-3 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search lists..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="pl-9"
              />
            </div>
            <Select value={status} onValueChange={(v) => setStatus(v as DistributionListStatus)}>
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Filter by status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="">All Statuses</SelectItem>
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
        <CardHeader>
          <CardTitle>Lists</CardTitle>
          <CardDescription>
            {meta?.total || 0} total list{meta?.total !== 1 ? 's' : ''}
          </CardDescription>
        </CardHeader>
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
                      className="cursor-pointer"
                      onClick={() => navigate(`/ui/auto-dialer/lists/${list.id}`)}
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
                      <TableCell>
                        {list.used_by_campaign?.name || list.campaign?.name || '-'}
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
                              navigate(`/ui/auto-dialer/lists/${list.id}`);
                            }}>
                              View Details
                            </DropdownMenuItem>
                            {list.can_upload && canManage && (
                              <DropdownMenuItem onClick={(e) => {
                                e.stopPropagation();
                                navigate(`/ui/auto-dialer/lists/${list.id}/upload`);
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
                            {list.can_archive && canManage && (
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
    </div>
  );
}
