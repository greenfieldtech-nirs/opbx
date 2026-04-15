import { useState } from 'react';
import { useParams, useNavigate, useLocation } from 'react-router-dom';
import {
  ArrowLeft,
  Upload,
  Copy,
  Download,
  Archive,
  Trash2,
  FileSpreadsheet,
  Phone,
  Clock,
  BarChart3,
  AlertCircle,
  CheckCircle,
  XCircle,
  RotateCcw,
  ExternalLink,
  Filter,
  Search,
  History,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
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
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useAuth } from '@/hooks/useAuth';
import { distributionListKeys, useDistributionList, useListDestinations, useDownloadList, useResetDialAttempts, useBulkResetDialAttempts, useResetPendingDestinations } from '@/hooks/useDistributionLists';
import { useQueryClient } from '@tanstack/react-query';
import { DistributionListsLoading } from './DistributionLists/components/DistributionListsLoading';
import { UnifiedUploadDialog } from './DistributionLists/components/UnifiedUploadDialog';
import { CopyListDialog } from './DistributionLists/components/CopyListDialog';
import { ArchiveListDialog } from './DistributionLists/components/ArchiveListDialog';
import { DeleteListDialog } from './DistributionLists/components/DeleteListDialog';
import { ValidationErrorsDialog } from './DistributionLists/components/ValidationErrorsDialog';
import { toast } from 'sonner';
import type { AutoDialerList, ListDestination } from '@/types';

const statusColors: Record<string, string> = {
  draft: 'bg-gray-100 text-gray-800',
  pending: 'bg-yellow-100 text-yellow-800',
  processing: 'bg-blue-100 text-blue-800',
  ready: 'bg-green-100 text-green-800',
  failed: 'bg-red-100 text-red-800',
  in_use: 'bg-purple-100 text-purple-800',
  used: 'bg-orange-100 text-orange-800',
  archived: 'bg-gray-100 text-gray-500',
  completed: 'bg-green-100 text-green-800',
  connected: 'bg-blue-100 text-blue-800',
  invalid: 'bg-red-100 text-red-800',
};

interface MetricCardProps {
  title: string;
  value: string | number;
  subtitle?: string;
  icon: React.ReactNode;
}

function MetricCard({ title, value, subtitle, icon }: MetricCardProps) {
  return (
    <Card>
      <CardContent className="p-6">
        <div className="flex items-center justify-between">
          <div>
            <p className="text-sm font-medium text-muted-foreground">{title}</p>
            <p className="text-3xl font-bold mt-2">{value}</p>
            {subtitle && <p className="text-sm text-muted-foreground mt-1">{subtitle}</p>}
          </div>
          <div className="h-12 w-12 bg-primary/10 rounded-full flex items-center justify-center">
            {icon}
          </div>
        </div>
      </CardContent>
    </Card>
  );
}

export default function DistributionListDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const location = useLocation();
  const { user } = useAuth();
  const canManage = user?.role === 'owner' || user?.role === 'pbx_admin';

  // Get return URL from location state, default to distribution lists
  const returnUrl = (location.state as { from?: string })?.from || '/ui/auto-dialer/distribution-lists';

  const [activeTab, setActiveTab] = useState('destinations');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<string>('all');
  const [page, setPage] = useState(1);
  const [selectedDestinations, setSelectedDestinations] = useState<number[]>([]);
  const [cdrPhoneNumber, setCdrPhoneNumber] = useState<string | null>(null);

  // Dialog states
  const [isUploadOpen, setIsUploadOpen] = useState(false);
  const [isCopyOpen, setIsCopyOpen] = useState(false);
  const [isArchiveOpen, setIsArchiveOpen] = useState(false);
  const [isDeleteOpen, setIsDeleteOpen] = useState(false);
  const [isErrorsOpen, setIsErrorsOpen] = useState(false);

  const { data: listData, isLoading: isListLoading } = useDistributionList(id!);
  const { data: destinationsData, isLoading: isDestinationsLoading } = useListDestinations(
    id!,
    { page, per_page: 25, status: statusFilter !== 'all' ? statusFilter : undefined, search: search || undefined }
  );
  const downloadMutation = useDownloadList();
  const resetDialAttemptsMutation = useResetDialAttempts();
  const bulkResetDialAttemptsMutation = useBulkResetDialAttempts();
  const resetPendingDestinationsMutation = useResetPendingDestinations();
  const queryClient = useQueryClient();

  const list = listData?.data;
  const destinations = destinationsData?.data || [];
  const meta = destinationsData?.meta;

  // Calculate metrics
  const totalDestinations = list?.statistics.total_rows || 0;
  const validDestinations = list?.statistics.valid_rows || 0;
  const invalidDestinations = list?.statistics.invalid_rows || 0;
  const connectionRate = totalDestinations > 0 
    ? Math.round((destinations.filter(d => d.status === 'completed').length / totalDestinations) * 100)
    : 0;
  const avgDuration = destinations.length > 0
    ? Math.round(destinations.reduce((acc, d) => acc + d.duration, 0) / destinations.length)
    : 0;

  if (isListLoading) {
    return <DistributionListsLoading />;
  }

  if (!list) {
    return (
      <div className="container mx-auto p-6">
        <Card>
          <CardContent className="flex flex-col items-center justify-center py-12">
            <AlertCircle className="h-12 w-12 text-red-500 mb-4" />
            <p className="text-red-600">List not found</p>
            <Button variant="outline" className="mt-4" onClick={() => navigate(returnUrl)}>
              <ArrowLeft className="h-4 w-4 mr-2" />
              Back
            </Button>
          </CardContent>
        </Card>
      </div>
    );
  }

  const handleDownload = async () => {
    try {
      const blob = await downloadMutation.mutateAsync(list.id);
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `${list.name}.csv`;
      a.click();
      window.URL.revokeObjectURL(url);
      toast.success('List downloaded successfully');
    } catch {
      toast.error('Failed to download list');
    }
  };

  const handleRetryFailed = () => {
    // TODO: Implement retry failed destinations
    toast.info('Retry functionality coming soon');
  };

  const handleResetDialAttempts = (destinationId: number) => {
    resetDialAttemptsMutation.mutate(
      { listId: id!, destinationId },
      {
        onSuccess: (data) => {
          toast.success(data.message);
        },
        onError: () => {
          toast.error('Failed to reset dial attempts');
        },
      }
    );
  };

  const handleBulkResetDialAttempts = () => {
    if (selectedDestinations.length === 0) return;
    bulkResetDialAttemptsMutation.mutate(
      { listId: id!, destinationIds: selectedDestinations },
      {
        onSuccess: (data) => {
          toast.success(data.message);
          setSelectedDestinations([]);
        },
        onError: () => {
          toast.error('Failed to reset dial attempts for selected destinations');
        },
      }
    );
  };

  const handleResetPendingDestinations = () => {
    resetPendingDestinationsMutation.mutate(
      { listId: id! },
      {
        onSuccess: (data) => {
          toast.success(data.message);
        },
        onError: () => {
          toast.error('Failed to reset pending destinations');
        },
      }
    );
  };

  const handleDeleteDestination = (destinationId: number) => {
    // TODO: Implement delete destination
    toast.info('Delete destination functionality coming soon');
  };

  const handleBulkDelete = () => {
    // TODO: Implement bulk delete
    toast.info(`Deleting ${selectedDestinations.length} destinations...`);
    setSelectedDestinations([]);
  };

  const toggleDestinationSelection = (id: number) => {
    setSelectedDestinations(prev => 
      prev.includes(id) ? prev.filter(d => d !== id) : [...prev, id]
    );
  };

  const toggleAllDestinations = () => {
    if (selectedDestinations.length === destinations.length) {
      setSelectedDestinations([]);
    } else {
      setSelectedDestinations(destinations.map(d => d.id));
    }
  };

  const openCDRHistory = (phoneNumber: string) => {
    setCdrPhoneNumber(phoneNumber);
  };

  return (
    <div className="container mx-auto p-6 space-y-6">
      {/* Header */}
      <div className="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
        <div className="space-y-2">
          <Button variant="ghost" size="sm" onClick={() => navigate(returnUrl)}>
            <ArrowLeft className="h-4 w-4 mr-2" />
            Back
          </Button>
          <div className="flex items-center gap-3 flex-wrap">
            <h1 className="text-3xl font-bold tracking-tight">{list.name}</h1>
            <Badge className={statusColors[list.status]}>{list.status_label}</Badge>
            <Badge variant="outline">v{list.version_number}</Badge>
            {!list.is_latest_version && (
              <Badge variant="secondary">Old Version</Badge>
            )}
          </div>
          {list.description && (
            <p className="text-muted-foreground">{list.description}</p>
          )}
          {list.parent_list_id && (
            <p className="text-sm text-muted-foreground">
              Copied from{' '}
              <Button variant="link" size="sm" className="h-auto p-0" onClick={() => navigate(`/ui/auto-dialer/distribution-lists/${list.parent_list_id}`)}>
                Version {list.parent_list?.version_number || 'Unknown'}
              </Button>
            </p>
          )}
        </div>

        <div className="flex flex-wrap gap-2">
          {canManage && list.status !== 'in_use' && (
            <Button variant="outline" onClick={() => setIsUploadOpen(true)}>
              <Upload className="h-4 w-4 mr-2" />
              Upload
            </Button>
          )}
          {list.can_copy && canManage && (
            <Button variant="outline" onClick={() => setIsCopyOpen(true)}>
              <Copy className="h-4 w-4 mr-2" />
              Copy
            </Button>
          )}
          <Button variant="outline" onClick={handleDownload} disabled={downloadMutation.isPending}>
            <Download className="h-4 w-4 mr-2" />
            Download
          </Button>
          {list.can_archive && canManage && list.status !== 'failed' && (
            <Button variant="outline" className="text-red-600" onClick={() => setIsArchiveOpen(true)}>
              <Archive className="h-4 w-4 mr-2" />
              Archive
            </Button>
          )}
          {list.status === 'failed' && canManage && (
            <Button variant="outline" className="text-red-600" onClick={() => setIsDeleteOpen(true)}>
              <Trash2 className="h-4 w-4 mr-2" />
              Delete
            </Button>
          )}
        </div>
      </div>

      {/* Metrics */}
      <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
        <MetricCard
          title="Total Destinations"
          value={totalDestinations.toLocaleString()}
          subtitle={`${validDestinations} valid, ${invalidDestinations} invalid`}
          icon={<Phone className="h-6 w-6 text-primary" />}
        />
        <MetricCard
          title="Connection Rate"
          value={`${connectionRate}%`}
          subtitle="Successfully connected"
          icon={<CheckCircle className="h-6 w-6 text-green-600" />}
        />
        <MetricCard
          title="Avg. Call Duration"
          value={`${Math.floor(avgDuration / 60)}:${(avgDuration % 60).toString().padStart(2, '0')}`}
          subtitle="Minutes per call"
          icon={<Clock className="h-6 w-6 text-blue-600" />}
        />
        <MetricCard
          title="Dial Attempts"
          value={destinations.reduce((acc, d) => acc + d.dial_attempts, 0).toLocaleString()}
          subtitle="Total across all destinations"
          icon={<BarChart3 className="h-6 w-6 text-purple-600" />}
        />
      </div>

      {/* Campaign Info */}
      {list.used_by_campaign && (
        <Card className="border-purple-200 bg-purple-50/50">
          <CardHeader className="pb-3">
            <CardTitle className="text-lg flex items-center gap-2">
              <BarChart3 className="h-5 w-5 text-purple-600" />
              Campaign Assignment
            </CardTitle>
          </CardHeader>
          <CardContent>
            <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <p className="text-sm text-muted-foreground">Campaign</p>
                <p className="font-medium">{list.used_by_campaign.name}</p>
              </div>
              <div>
                <p className="text-sm text-muted-foreground">Assigned On</p>
                <p className="font-medium">{new Date(list.used_at || '').toLocaleDateString()}</p>
              </div>
              <div>
                <p className="text-sm text-muted-foreground">Status</p>
                <Badge className={statusColors[list.used_by_campaign.status] || 'bg-gray-100'}>
                  {list.used_by_campaign.status}
                </Badge>
              </div>
            </div>
          </CardContent>
        </Card>
      )}

      {/* Tabs */}
      <Tabs value={activeTab} onValueChange={setActiveTab}>
        <TabsList>
          <TabsTrigger value="destinations">Destinations</TabsTrigger>
          {list.versions?.length > 0 && <TabsTrigger value="versions">Version History</TabsTrigger>}
          {list.status === 'failed' && <TabsTrigger value="errors">Validation Errors</TabsTrigger>}
          {list.original_filename && <TabsTrigger value="upload">Upload Info</TabsTrigger>}
        </TabsList>

        <TabsContent value="destinations" className="space-y-4">
          {/* Filters */}
          <Card>
            <CardContent className="p-4">
              <div className="flex flex-col sm:flex-row gap-4 items-center">
                <div className="relative flex-1 w-full">
                  <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                  <Input
                    placeholder="Search phone numbers or descriptions..."
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    className="pl-9 h-10"
                  />
                </div>
                <Select value={statusFilter} onValueChange={setStatusFilter}>
                  <SelectTrigger className="w-[180px] h-10">
                    <Filter className="h-4 w-4 mr-2" />
                    <SelectValue placeholder="Filter by status" />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="all">All Statuses</SelectItem>
                    <SelectItem value="pending">Pending</SelectItem>
                    <SelectItem value="completed">Completed</SelectItem>
                    <SelectItem value="failed">Failed</SelectItem>
                    <SelectItem value="invalid">Invalid</SelectItem>
                  </SelectContent>
                </Select>
                <Button
                  variant="outline"
                  className="h-10"
                  onClick={handleResetPendingDestinations}
                  disabled={resetPendingDestinationsMutation.isPending}
                >
                  <RotateCcw className="h-4 w-4 mr-2" />
                  Reset Pending Entries
                </Button>
                {selectedDestinations.length > 0 && (
                  <div className="flex gap-2">
                    <Button variant="outline" className="h-10" onClick={handleRetryFailed}>
                      <RotateCcw className="h-4 w-4 mr-2" />
                      Retry ({selectedDestinations.length})
                    </Button>
                    <Button variant="outline" className="h-10" onClick={handleBulkResetDialAttempts}>
                      <RotateCcw className="h-4 w-4 mr-2" />
                      Reset Dial Attempts ({selectedDestinations.length})
                    </Button>
                    <Button variant="destructive" className="h-10" onClick={handleBulkDelete}>
                      <Trash2 className="h-4 w-4 mr-2" />
                      Delete
                    </Button>
                  </div>
                )}
              </div>
            </CardContent>
          </Card>

          {/* Destinations Table */}
          <Card>
            <CardContent className="p-0">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="w-[40px]">
                      <Checkbox
                        checked={selectedDestinations.length === destinations.length && destinations.length > 0}
                        onCheckedChange={toggleAllDestinations}
                      />
                    </TableHead>
                    <TableHead>Phone Number</TableHead>
                    <TableHead>Description</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Dial Attempts</TableHead>
                    <TableHead>Last Call</TableHead>
                    <TableHead>Duration</TableHead>
                    <TableHead className="w-[80px]">Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {isDestinationsLoading ? (
                    <TableRow>
                      <TableCell colSpan={8} className="text-center py-8">
                        Loading destinations...
                      </TableCell>
                    </TableRow>
                  ) : destinations.length === 0 ? (
                    <TableRow>
                      <TableCell colSpan={8} className="text-center py-8 text-muted-foreground">
                        No destinations found
                      </TableCell>
                    </TableRow>
                  ) : (
                    destinations.map((destination) => (
                      <TableRow key={destination.id}>
                        <TableCell>
                          <Checkbox
                            checked={selectedDestinations.includes(destination.id)}
                            onCheckedChange={() => toggleDestinationSelection(destination.id)}
                          />
                        </TableCell>
                        <TableCell>
                          <button
                            className="font-mono text-sm hover:underline hover:text-primary flex items-center gap-1"
                            onClick={() => openCDRHistory(destination.phone_number)}
                          >
                            {destination.phone_number}
                            <ExternalLink className="h-3 w-3" />
                          </button>
                        </TableCell>
                        <TableCell>{destination.description || '-'}</TableCell>
                        <TableCell>
                          <Badge className={statusColors[destination.status] || 'bg-gray-100'}>
                            {destination.status_label}
                          </Badge>
                        </TableCell>
                        <TableCell>{destination.dial_attempts}</TableCell>
                        <TableCell>
                          {destination.last_dialed_at ? (
                            <div>
                              <p>{new Date(destination.last_dialed_at).toLocaleDateString()}</p>
                              {destination.last_disposition && (
                                <p className="text-xs text-muted-foreground">{destination.last_disposition}</p>
                              )}
                            </div>
                          ) : (
                            '-'
                          )}
                        </TableCell>
                        <TableCell>
                          {destination.duration > 0 ? (
                            `${Math.floor(destination.duration / 60)}:${(destination.duration % 60).toString().padStart(2, '0')}`
                          ) : (
                            '-'
                          )}
                        </TableCell>
                        <TableCell>
                          <div className="flex items-center gap-2">
                            {destination.dial_attempts > 0 && (
                              <TooltipProvider>
                                <Tooltip>
                                  <TooltipTrigger asChild>
                                    <Button
                                      variant="ghost"
                                      size="icon"
                                      onClick={() => handleResetDialAttempts(destination.id)}
                                    >
                                      <RotateCcw className="h-4 w-4" />
                                    </Button>
                                  </TooltipTrigger>
                                  <TooltipContent>
                                    <p>Reset Dial Attempts</p>
                                  </TooltipContent>
                                </Tooltip>
                              </TooltipProvider>
                            )}
                            {destination.status === 'failed' && (
                              <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => handleRetryFailed()}
                              >
                                <RotateCcw className="h-4 w-4 mr-1" />
                                Retry
                              </Button>
                            )}
                            <Button
                              variant="ghost"
                              size="icon"
                              className="text-red-600 hover:text-red-700"
                              onClick={() => handleDeleteDestination(destination.id)}
                            >
                              <Trash2 className="h-4 w-4" />
                            </Button>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))
                  )}
                </TableBody>
              </Table>

              {/* Pagination */}
              {meta && meta.last_page > 1 && (
                <div className="p-4 border-t flex justify-center gap-2">
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
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="versions">
          <Card>
            <CardHeader>
              <CardTitle>Version History</CardTitle>
              <CardDescription>All versions of this list</CardDescription>
            </CardHeader>
            <CardContent>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Version</TableHead>
                    <TableHead>Status</TableHead>
                    <TableHead>Destinations</TableHead>
                    <TableHead>Created</TableHead>
                    <TableHead>Actions</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {list.versions?.map((version) => (
                    <TableRow key={version.id}>
                      <TableCell>v{version.version_number}</TableCell>
                      <TableCell>
                        <Badge className={statusColors[version.status]}>
                          {version.status_label}
                        </Badge>
                        {version.is_latest_version && (
                          <Badge variant="secondary" className="ml-2">Latest</Badge>
                        )}
                      </TableCell>
                      <TableCell>{version.statistics?.valid_rows || 0} valid</TableCell>
                      <TableCell>{new Date(version.created_at).toLocaleDateString()}</TableCell>
                      <TableCell>
                        <Button
                          variant="outline"
                          size="sm"
                          onClick={() => navigate(`/ui/auto-dialer/distribution-lists/${version.id}`)}
                        >
                          View
                        </Button>
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="errors">
          <Card>
            <CardHeader>
              <CardTitle className="flex items-center gap-2 text-red-600">
                <XCircle className="h-5 w-5" />
                Validation Errors
              </CardTitle>
              <CardDescription>Errors found during CSV validation</CardDescription>
            </CardHeader>
            <CardContent>
              <Button variant="outline" className="mb-4">
                <Download className="h-4 w-4 mr-2" />
                Download Errors CSV
              </Button>
              {/* Errors table would go here */}
            </CardContent>
          </Card>
        </TabsContent>

        <TabsContent value="upload">
          <Card>
            <CardHeader>
              <CardTitle>Upload Information</CardTitle>
              <CardDescription>Details about the original CSV upload</CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <p className="text-sm text-muted-foreground">Original Filename</p>
                  <p className="font-medium">{list.original_filename || 'N/A'}</p>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Processed At</p>
                  <p className="font-medium">
                    {list.processed_at ? new Date(list.processed_at).toLocaleString() : 'Not processed'}
                  </p>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Total Rows</p>
                  <p className="font-medium">{list.statistics.total_rows}</p>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Valid Rows</p>
                  <p className="font-medium text-green-600">{list.statistics.valid_rows}</p>
                </div>
                <div>
                  <p className="text-sm text-muted-foreground">Invalid Rows</p>
                  <p className="font-medium text-red-600">{list.statistics.invalid_rows}</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </TabsContent>
      </Tabs>

      {/* Dialogs */}
      <UnifiedUploadDialog
        list={list}
        open={isUploadOpen}
        onOpenChange={setIsUploadOpen}
        onSuccess={() => {
          // Refresh current list data - same list is updated
          queryClient.invalidateQueries({ queryKey: distributionListKeys.detail(list.id) });
          queryClient.invalidateQueries({ queryKey: distributionListKeys.destinations(list.id) });
          toast.success('Upload completed successfully');
        }}
      />

      <CopyListDialog
        list={list}
        open={isCopyOpen}
        onOpenChange={setIsCopyOpen}
      />

      <ArchiveListDialog
        list={list}
        open={isArchiveOpen}
        onOpenChange={setIsArchiveOpen}
        onConfirm={() => {
          toast.success('List archived');
          setIsArchiveOpen(false);
        }}
        isArchiving={false}
      />

      <DeleteListDialog
        list={list}
        open={isDeleteOpen}
        onOpenChange={setIsDeleteOpen}
        onConfirm={() => {
          toast.success('List deleted');
          setIsDeleteOpen(false);
          navigate('/ui/auto-dialer/distribution-lists');
        }}
        isDeleting={false}
      />

      <ValidationErrorsDialog
        list={list}
        open={isErrorsOpen}
        onOpenChange={setIsErrorsOpen}
      />

      {/* CDR History Dialog */}
      <Dialog open={!!cdrPhoneNumber} onOpenChange={() => setCdrPhoneNumber(null)}>
        <DialogContent className="sm:max-w-[600px]">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <History className="h-5 w-5" />
              Call History
            </DialogTitle>
            <DialogDescription>
              Recent calls to {cdrPhoneNumber}
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <p className="text-sm text-muted-foreground">
              CDR integration coming soon. This will show the call detail records for this phone number.
            </p>
            <Button variant="outline" className="w-full" onClick={() => setCdrPhoneNumber(null)}>
              Close
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}
