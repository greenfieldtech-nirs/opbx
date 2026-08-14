/**
 * Call Logs Page
 */

import { useState, useEffect, useCallback, useRef } from 'react';
import { useQuery } from '@tanstack/react-query';
import { cdrService } from '@/services/cdr.service';
import { extensionsService } from '@/services/extensions.service';
import api from '@/services/api';
import { useAuth } from '@/hooks/useAuth';
import { useAudioPlayer } from '@/hooks/useAudioPlayer';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import logger from '@/utils/logger';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Database, Download, Eye, Filter, X, Loader2, RefreshCw, Lock, Play, Pause } from 'lucide-react';
import { formatPhoneNumber, formatDateTime, getDispositionColor } from '@/utils/formatters';
import { cn } from '@/lib/utils';
import { StandardDataTable, EmptyState } from '@/components/design-system';
import { useRefreshTimer } from '@/context/RefreshTimerContext';
import type { CallDetailRecord, CDRFilters } from '@/types/api.types';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Prism as SyntaxHighlighter } from 'react-syntax-highlighter';
import { vscDarkPlus } from 'react-syntax-highlighter/dist/esm/styles/prism';
import { JsonViewer } from '@textea/json-viewer';
export default function CallLogs() {
  const { user: currentUser } = useAuth();
  const isPbxUser = currentUser?.role === 'pbx_user';
  const isSupervisor = currentUser?.role === 'supervisor';
  const isReadOnly = isPbxUser || currentUser?.role === 'reporter' || isSupervisor;

  // CDR state
  const [cdrPage, setCdrPage] = useState(1);
  const [cdrFilters, setCdrFilters] = useState<CDRFilters>({ per_page: 50 });
  const [showFilters, setShowFilters] = useState(false);
  const [selectedCdr, setSelectedCdr] = useState<CallDetailRecord | null>(null);
  const [showCdrDetails, setShowCdrDetails] = useState(false);

  // Sorting state - always defaults to Session Time descending
  const [sortField, setSortField] = useState('session_timestamp');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('desc');

  const handleSort = (field: string) => {
    if (field === sortField) {
      setSortDirection((prev) => (prev === 'asc' ? 'desc' : 'asc'));
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  // Recording playback
  const { play, pause, isPlaying } = useAudioPlayer();
  const [loadingRecordingId, setLoadingRecordingId] = useState<number | null>(null);
  // Tracks the blob URL backing the currently-loaded recording so it can be
  // revoked once playback moves on - object URLs otherwise leak for the
  // lifetime of the page, which matters here since this page auto-refreshes.
  const activeBlobUrlRef = useRef<string | null>(null);

  useEffect(() => {
    return () => {
      if (activeBlobUrlRef.current) {
        URL.revokeObjectURL(activeBlobUrlRef.current);
      }
    };
  }, []);

  const handlePlayRecording = async (cdr: CallDetailRecord) => {
    const id = Number(cdr.id);

    if (isPlaying(id)) {
      pause();
      return;
    }

    setLoadingRecordingId(id);
    try {
      const response = await api.get(`/call-detail-records/${id}/recording`, {
        responseType: 'blob',
      });

      if (activeBlobUrlRef.current) {
        URL.revokeObjectURL(activeBlobUrlRef.current);
      }

      const blobUrl = URL.createObjectURL(response.data);
      activeBlobUrlRef.current = blobUrl;
      await play(id, blobUrl);
    } catch (error) {
      logger.error('Failed to load recording:', { error });
    } finally {
      setLoadingRecordingId(null);
    }
  };

  // Form state for filters
  const [filterForm, setFilterForm] = useState({
    from: '',
    to: '',
    from_date: '',
    to_date: '',
    disposition: '',
    user: '',
    direction: '',
  });

  // Auto-refresh interval state (default: 30 seconds)
  const [refreshInterval, setRefreshInterval] = useState<number>(30000);

  // For PBX Users, find their extension number
  const [userExtensionNumber, setUserExtensionNumber] = useState<string | null>(null);

  useEffect(() => {
    const fetchUserExtension = async () => {
      if (isPbxUser) {
        try {
          const extensionsData = await extensionsService.getAll({ per_page: 100 });
          const userExtension = extensionsData.data.find((ext: any) => ext.user_id === currentUser?.id);
          if (userExtension) {
            setUserExtensionNumber(userExtension.extension_number);
          }
        } catch (error) {
          logger.error('Failed to fetch user extension:', { error });
        }
      }
    };
    fetchUserExtension();
  }, [isPbxUser, currentUser?.id]);

  // Set default filter for PBX Users to show only their calls
  useEffect(() => {
    if (isPbxUser && userExtensionNumber) {
      setCdrFilters({ 
        per_page: 50,
        from: userExtensionNumber,  // Show calls from their extension
        to: userExtensionNumber,    // Or to their extension
      });
    }
  }, [isPbxUser, userExtensionNumber]);

  // CDR query
  const {
    data: cdrData,
    isLoading: cdrIsLoading,
    isFetching: cdrIsFetching,
    refetch: refetchCdr,
  } = useQuery({
    queryKey: ['cdrs', cdrPage, cdrFilters, sortField, sortDirection, isSupervisor ? 'supervisor' : 'all'],
    queryFn: () => cdrService.getAll(
      { ...cdrFilters, page: cdrPage, sort_by: sortField, sort_order: sortDirection },
      isSupervisor
    ),
    refetchInterval: refreshInterval,
    refetchIntervalInBackground: true,
    staleTime: 0,
  });

  const handleExportCdr = async () => {
    try {
      const blob = await cdrService.exportToCsv(cdrFilters);
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `cdr-${new Date().toISOString()}.csv`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);
    } catch (error) {
      logger.error('CDR export failed:', { error });
    }
  };

  const handleApplyFilters = () => {
    const newFilters: CDRFilters = { per_page: 50 };
    if (filterForm.from) newFilters.from = filterForm.from;
    if (filterForm.to) newFilters.to = filterForm.to;
    if (filterForm.from_date) newFilters.from_date = filterForm.from_date;
    if (filterForm.to_date) newFilters.to_date = filterForm.to_date;
    if (filterForm.disposition) newFilters.disposition = filterForm.disposition;
    if (filterForm.user) newFilters.user = filterForm.user;
    if (filterForm.direction) newFilters.direction = filterForm.direction;

    setCdrFilters(newFilters);
    setCdrPage(1);
  };

  const handleClearFilters = () => {
    setFilterForm({
      from: '',
      to: '',
      from_date: '',
      to_date: '',
      disposition: '',
      user: '',
      direction: '',
    });
    setCdrFilters({ per_page: 50 });
    setCdrPage(1);
  };

  const handleViewCdrDetails = async (cdr: CallDetailRecord) => {
    try {
      // Fetch full CDR with raw_cdr data
      const fullCdr = await cdrService.getById(cdr.id);
      setSelectedCdr(fullCdr);
      setShowCdrDetails(true);
    } catch (error) {
      console.error('Failed to load CDR details:', error);
    }
  };

  const handleRefresh = useCallback(() => refetchCdr(), [refetchCdr]);

  // Register refresh timer with AppLayout bar
  useRefreshTimer(refreshInterval, cdrIsFetching);

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Database className="h-8 w-8" />
            Call Logs
          </h1>
          <p className="text-muted-foreground mt-1">View call detail records and history</p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Call Logs</span>
          </div>
        </div>
        <div className="flex items-center gap-4">
          {/* Refresh Rate Selector */}
          <div className="flex items-center gap-2">
            <span className="text-sm text-muted-foreground">Refresh:</span>
            <Select
              value={String(refreshInterval)}
              onValueChange={(value) => setRefreshInterval(Number(value))}
            >
              <SelectTrigger className="w-[140px]">
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="0">Manual</SelectItem>
                <SelectItem value="5000">5 Seconds</SelectItem>
                <SelectItem value="10000">10 Seconds</SelectItem>
                <SelectItem value="30000">30 Seconds</SelectItem>
                <SelectItem value="60000">60 Seconds</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>
      </div>

      {/* Call Detail Records (CDR) Section */}
      <Card>
        <CardHeader>
          <div className="flex justify-between items-start">
            <div>
              <CardTitle>Call Detail Records</CardTitle>
              <CardDescription>
                {cdrData?.meta?.total || 0} total records
              </CardDescription>
            </div>
            <div className="flex gap-2">
              <Button
                variant="outline"
                size="sm"
                onClick={() => refetchCdr()}
                disabled={cdrIsFetching}
              >
                <RefreshCw className={cn('h-4 w-4 mr-2', cdrIsFetching && 'animate-spin')} />
                Refresh
              </Button>
              <Button
                variant="outline"
                size="sm"
                onClick={() => setShowFilters(!showFilters)}
              >
                <Filter className="h-4 w-4 mr-2" />
                {showFilters ? 'Hide Filters' : 'Show Filters'}
              </Button>
              {!isReadOnly && (
                <Button variant="outline" size="sm" onClick={handleExportCdr}>
                  <Download className="h-4 w-4 mr-2" />
                  Export
                </Button>
              )}
            </div>
          </div>
        </CardHeader>
        <CardContent className="pt-6">
          {/* Filters */}
          {showFilters && (
            <div className="mb-6 p-4 bg-gray-50 rounded-lg border">
              <div className="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 xl:grid-cols-7 gap-4">
                <div>
                  <Label htmlFor="filter-from">From Number</Label>
                  <Input
                    id="filter-from"
                    placeholder="e.g., +1415"
                    value={filterForm.from}
                    onChange={(e) => setFilterForm({ ...filterForm, from: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="filter-to">To Number</Label>
                  <Input
                    id="filter-to"
                    placeholder="e.g., +1312"
                    value={filterForm.to}
                    onChange={(e) => setFilterForm({ ...filterForm, to: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="filter-disposition">Disposition</Label>
                  <Select
                    value={filterForm.disposition || undefined}
                    onValueChange={(value) => setFilterForm({ ...filterForm, disposition: value })}
                  >
                    <SelectTrigger id="filter-disposition">
                      <SelectValue placeholder="All dispositions" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="ANSWERED">Answered</SelectItem>
                      <SelectItem value="NO ANSWER">No Answer</SelectItem>
                      <SelectItem value="BUSY">Busy</SelectItem>
                      <SelectItem value="FAILED">Failed</SelectItem>
                      <SelectItem value="CANCELLED">Cancelled</SelectItem>
                      <SelectItem value="CONGESTION">Congestion</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label htmlFor="filter-user">User</Label>
                  <Input
                    id="filter-user"
                    placeholder="e.g., Jane Doe"
                    value={filterForm.user}
                    onChange={(e) => setFilterForm({ ...filterForm, user: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="filter-direction">Direction</Label>
                  <Select
                    value={filterForm.direction || undefined}
                    onValueChange={(value) => setFilterForm({ ...filterForm, direction: value })}
                  >
                    <SelectTrigger id="filter-direction">
                      <SelectValue placeholder="All directions" />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="incoming">Incoming</SelectItem>
                      <SelectItem value="outgoing">Outgoing</SelectItem>
                      <SelectItem value="internal">Internal</SelectItem>
                      <SelectItem value="application">Application</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
                <div>
                  <Label htmlFor="filter-from-date">From Date</Label>
                  <Input
                    id="filter-from-date"
                    type="date"
                    value={filterForm.from_date}
                    onChange={(e) => setFilterForm({ ...filterForm, from_date: e.target.value })}
                  />
                </div>
                <div>
                  <Label htmlFor="filter-to-date">To Date</Label>
                  <Input
                    id="filter-to-date"
                    type="date"
                    value={filterForm.to_date}
                    onChange={(e) => setFilterForm({ ...filterForm, to_date: e.target.value })}
                  />
                </div>
              </div>
              <div className="flex gap-2 mt-4">
                <Button onClick={handleApplyFilters} size="sm">
                  <Filter className="h-4 w-4 mr-2" />
                  Apply Filters
                </Button>
                <Button onClick={handleClearFilters} variant="outline" size="sm">
                  <X className="h-4 w-4 mr-2" />
                  Clear Filters
                </Button>
              </div>
            </div>
          )}

          {/* CDR Table */}
          <StandardDataTable<CallDetailRecord & { id: string | number }>
            data={(cdrData?.data || []).map(cdr => ({ ...cdr, id: cdr.id }))}
            isLoading={cdrIsLoading}
            showIdentityColumn={false}
            identityIcon={Database}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(cdr) => formatPhoneNumber(cdr.from)}
            getIdentitySecondary={(cdr) => `To: ${formatPhoneNumber(cdr.to)}`}
            onRowClick={handleViewCdrDetails}
            canView={false}
            canEdit={false}
            canDelete={false}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={handleSort}
            columns={[
              {
                header: 'Session Time',
                accessorKey: 'session_timestamp' as any,
                sortKey: 'session_timestamp',
                cell: (cdr) => formatDateTime(cdr.session_timestamp)
              },
              {
                header: 'Extension Number',
                accessorKey: 'from' as any,
                sortKey: 'from',
                cell: (cdr) => formatPhoneNumber(cdr.from),
              },
              {
                header: 'User',
                accessorKey: 'user_full_name' as any,
                sortKey: 'user_full_name',
                cell: (cdr) => (
                  <span className={cn(
                    'text-sm',
                    cdr.user_full_name === 'Unassigned User' && 'text-muted-foreground italic'
                  )}>
                    {cdr.user_full_name}
                  </span>
                ),
              },
              {
                header: 'Destination',
                accessorKey: 'to' as any,
                cell: (cdr) => formatPhoneNumber(cdr.to),
              },
              {
                header: 'Direction',
                accessorKey: 'direction' as any,
                sortKey: 'direction',
                cell: (cdr) => (
                  <span className="text-sm capitalize">
                    {cdr.direction || '-'}
                  </span>
                ),
              },
              {
                header: 'Disposition',
                accessorKey: 'disposition' as any,
                sortKey: 'disposition',
                cell: (cdr) => (
                  <span
                    className={cn(
                      'px-2 py-1 rounded-full text-xs font-medium whitespace-nowrap',
                      getDispositionColor(cdr.disposition)
                    )}
                  >
                    {cdr.disposition}
                  </span>
                )
              },
              {
                header: 'AMD Status',
                accessorKey: 'amd_status' as any,
                cell: (cdr) => {
                  const isEnabled = cdr.amd_status?.startsWith('Enabled');
                  const result = isEnabled ? cdr.amd_status.split('::')[1] : null;
                  return (
                    <span
                      className={cn(
                        'px-2 py-1 rounded-full text-xs font-medium whitespace-nowrap',
                        isEnabled
                          ? 'bg-green-100 text-green-700'
                          : 'bg-gray-100 text-gray-500'
                      )}
                    >
                      {isEnabled ? `Enabled::${result}` : 'Disabled'}
                    </span>
                  );
                }
              },
              {
                header: 'Duration',
                accessorKey: 'duration_formatted' as any,
              },
              {
                header: 'Connected Time',
                accessorKey: 'billsec_formatted' as any,
              },
              {
                header: 'Recording',
                accessorKey: 'has_recording' as any,
                cell: (cdr) => {
                  if (!cdr.has_recording) {
                    return <span className="text-muted-foreground text-sm">-</span>;
                  }

                  const id = Number(cdr.id);
                  const loading = loadingRecordingId === id;
                  const playing = isPlaying(id);

                  return (
                    <Button
                      variant="ghost"
                      size="sm"
                      className="h-8 w-8 p-0"
                      disabled={loading}
                      onClick={(e) => {
                        e.stopPropagation();
                        handlePlayRecording(cdr);
                      }}
                    >
                      {loading ? (
                        <Loader2 className="h-4 w-4 animate-spin" />
                      ) : playing ? (
                        <Pause className="h-4 w-4" />
                      ) : (
                        <Play className="h-4 w-4" />
                      )}
                    </Button>
                  );
                },
              }
            ]}
            emptyState={
              <EmptyState
                icon={Database}
                title="No CDRs found"
                description={Object.keys(cdrFilters).length > 1
                  ? 'Try adjusting your filters'
                  : 'CDRs will appear here once calls are completed'}
              />
            }
          />

          {/* Pagination */}
          {cdrData && cdrData.data.length > 0 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {cdrData.meta.from || 0} to {cdrData.meta.to || 0} of{' '}
                {cdrData.meta.total} records
              </div>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCdrPage(cdrPage - 1)}
                  disabled={cdrPage === 1}
                >
                  Previous
                </Button>
                <div className="flex items-center px-3 text-sm">
                  Page {cdrData.meta.current_page} of {cdrData.meta.last_page}
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCdrPage(cdrPage + 1)}
                  disabled={cdrPage >= cdrData.meta.last_page}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {/* CDR Details Dialog */}
      <Dialog open={showCdrDetails} onOpenChange={setShowCdrDetails}>
        <DialogContent className="max-w-4xl max-h-[80vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Call Detail Record</DialogTitle>
            <DialogDescription className="break-all">
              Complete CDR information for call ID: {selectedCdr?.call_id}
            </DialogDescription>
          </DialogHeader>
          {selectedCdr && (
            <div className="space-y-4 min-w-0">
              <div className="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-lg">
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">From</div>
                  <div className="text-base break-words">{formatPhoneNumber(selectedCdr.from)}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">To</div>
                  <div className="text-base break-words">{formatPhoneNumber(selectedCdr.to)}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Disposition</div>
                  <div>
                    <span
                      className={cn(
                        'px-2 py-1 rounded-full text-xs font-medium',
                        getDispositionColor(selectedCdr.disposition)
                      )}
                    >
                      {selectedCdr.disposition}
                    </span>
                  </div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Session Time</div>
                  <div className="text-base break-words">{formatDateTime(selectedCdr.session_timestamp)}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Total Duration</div>
                  <div className="text-base break-words">{selectedCdr.duration_formatted}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Connected Time</div>
                  <div className="text-base break-words">{selectedCdr.billsec_formatted}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Domain</div>
                  <div className="text-base break-all">{selectedCdr.domain}</div>
                </div>
                {selectedCdr.rated_cost !== null && selectedCdr.rated_cost !== undefined && (
                  <div className="min-w-0">
                    <div className="text-sm font-medium text-muted-foreground">Cost</div>
                    <div className="text-base break-words">${selectedCdr.rated_cost.toFixed(4)}</div>
                  </div>
                )}
              </div>

              {/* AMD Result Section */}
              {selectedCdr.amd_status?.startsWith('Enabled') && (
                <div className="p-4 bg-green-50 rounded-lg border border-green-200">
                  <div className="text-sm font-semibold text-green-800 mb-2">AMD Result</div>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="min-w-0">
                      <div className="text-xs font-medium text-green-700">Result</div>
                      <div className="text-sm text-green-900 font-medium">
                        {selectedCdr.amd_status.split('::')[1] || 'Unknown'}
                      </div>
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-medium text-green-700">Confidence</div>
                      <div className="text-sm text-green-900">
                        {((selectedCdr.raw_cdr?.session as any)?.profile?.amd?.confidence !== undefined)
                          ? `${((selectedCdr.raw_cdr?.session as any)?.profile?.amd?.confidence * 100).toFixed(0)}%`
                          : (selectedCdr.amd_confidence !== undefined)
                            ? `${(selectedCdr.amd_confidence * 100).toFixed(0)}%`
                            : 'N/A'}
                      </div>
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-medium text-green-700">Detection Time</div>
                      <div className="text-sm text-green-900">
                        {((selectedCdr.raw_cdr?.session as any)?.profile?.amd?.detectionTimeMs !== undefined)
                          ? `${(selectedCdr.raw_cdr?.session as any)?.profile?.amd?.detectionTimeMs}ms`
                          : 'N/A'}
                      </div>
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-medium text-green-700">Timestamp</div>
                      <div className="text-sm text-green-900">
                        {(selectedCdr.raw_cdr?.session as any)?.profile?.amd?.timestamp
                          ? formatDateTime((selectedCdr.raw_cdr?.session as any)?.profile?.amd?.timestamp)
                          : 'N/A'}
                      </div>
                    </div>
                    {((selectedCdr.raw_cdr?.session as any)?.profile?.amd?.reason) && (
                      <div className="min-w-0 col-span-2">
                        <div className="text-xs font-medium text-green-700">Reason</div>
                        <div className="text-sm text-green-900 break-words">
                          {(selectedCdr.raw_cdr?.session as any)?.profile?.amd?.reason}
                        </div>
                      </div>
                    )}
                  </div>
                </div>
              )}

              <Tabs defaultValue="executions" className="w-full min-w-0">
                <TabsList className="grid w-full grid-cols-2">
                  <TabsTrigger value="executions">Application Executions</TabsTrigger>
                  <TabsTrigger value="raw-data">Raw Data</TabsTrigger>
                </TabsList>

                <TabsContent value="executions" className="space-y-4 min-w-0">
                  {(selectedCdr.raw_cdr?.session as any)?.profile?.application ? (
                    <div className="space-y-4 min-w-0">
                      {(selectedCdr.raw_cdr as any).session.profile.application
                        .sort((a: any, b: any) => new Date(a.time).getTime() - new Date(b.time).getTime())
                        .map((execution: any, index: number) => (
                          <div key={index} className="border rounded-lg p-4 bg-gray-50 min-w-0">
                            <div className="flex items-center justify-between mb-2 gap-2">
                              <div className="text-sm font-medium text-gray-900 min-w-0 break-words">
                                Execution #{index + 1}
                              </div>
                              <div className="text-xs text-gray-500 whitespace-nowrap shrink-0">
                                {formatDateTime(execution.time)}
                              </div>
                            </div>
                            <div className="mb-2 min-w-0">
                              <div className="text-xs font-medium text-gray-700 mb-1">URL:</div>
                              <div className="text-xs font-mono bg-white p-2 rounded border text-gray-800 break-all overflow-wrap-anywhere max-w-full">
                                {execution.url}
                              </div>
                            </div>
                            <div className="min-w-0">
                              <div className="text-xs font-medium text-gray-700 mb-1">CXML Response:</div>
                              <div className="rounded border overflow-auto max-w-full">
                                <SyntaxHighlighter
                                  language="xml"
                                  style={vscDarkPlus}
                                  customStyle={{
                                    margin: 0,
                                    fontSize: '0.75rem',
                                    lineHeight: '1.5',
                                    maxWidth: '100%',
                                    whiteSpace: 'pre-wrap',
                                    wordBreak: 'break-word',
                                    overflowWrap: 'break-word',
                                  }}
                                  wrapLongLines={true}
                                  showLineNumbers={true}
                                  lineNumberStyle={{
                                    minWidth: '2.5em',
                                  }}
                                >
                                  {execution.source}
                                </SyntaxHighlighter>
                              </div>
                            </div>
                          </div>
                        ))}
                    </div>
                  ) : (
                    <div className="text-center py-8 text-gray-500">
                      No application execution data available for this call.
                    </div>
                  )}
                </TabsContent>

                <TabsContent value="raw-data" className="min-w-0">
                  {selectedCdr.raw_cdr ? (
                    <div className="min-w-0">
                      <div className="text-sm font-semibold mb-2">Raw CDR Data</div>
                      <div className="border rounded overflow-x-auto bg-slate-900 max-w-full">
                        <div className="p-4 min-w-0">
                          <JsonViewer
                            value={selectedCdr.raw_cdr}
                            theme="dark"
                            defaultInspectDepth={1}
                            enableClipboard={true}
                            displayDataTypes={false}
                            quotesOnKeys={false}
                            style={{
                              backgroundColor: '#0f172a',
                              fontSize: '0.875rem',
                              wordBreak: 'break-word',
                              overflowWrap: 'break-word',
                              maxWidth: '100%',
                            }}
                          />
                        </div>
                      </div>
                    </div>
                  ) : (
                    <div className="text-center py-8 text-gray-500">
                      No raw CDR data available.
                    </div>
                  )}
                </TabsContent>
              </Tabs>
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
