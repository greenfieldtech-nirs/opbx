/**
 * Call Logs Page
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { cdrService } from '@/services/cdr.service';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Database, Download, Eye, Filter, X, Loader2, RefreshCw } from 'lucide-react';
import { formatPhoneNumber, formatDateTime, getDispositionColor } from '@/utils/formatters';
import { cn } from '@/lib/utils';
import type { CallDetailRecord, CDRFilters } from '@/types/api.types';

export default function CallLogs() {
  // CDR state
  const [cdrPage, setCdrPage] = useState(1);
  const [cdrFilters, setCdrFilters] = useState<CDRFilters>({ per_page: 50 });
  const [showFilters, setShowFilters] = useState(false);
  const [selectedCdr, setSelectedCdr] = useState<CallDetailRecord | null>(null);
  const [showCdrDetails, setShowCdrDetails] = useState(false);

  // Form state for filters
  const [filterForm, setFilterForm] = useState({
    from: '',
    to: '',
    from_date: '',
    to_date: '',
    disposition: '',
  });

  // CDR query
  const {
    data: cdrData,
    isLoading: cdrIsLoading,
    refetch: refetchCdr,
  } = useQuery({
    queryKey: ['cdrs', cdrPage, cdrFilters],
    queryFn: () => cdrService.getAll({ ...cdrFilters, page: cdrPage }),
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
      console.error('CDR export failed:', error);
    }
  };

  const handleApplyFilters = () => {
    const newFilters: CDRFilters = { per_page: 50 };
    if (filterForm.from) newFilters.from = filterForm.from;
    if (filterForm.to) newFilters.to = filterForm.to;
    if (filterForm.from_date) newFilters.from_date = filterForm.from_date;
    if (filterForm.to_date) newFilters.to_date = filterForm.to_date;
    if (filterForm.disposition) newFilters.disposition = filterForm.disposition;

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
                disabled={cdrIsLoading}
              >
                <RefreshCw className={cn('h-4 w-4 mr-2', cdrIsLoading && 'animate-spin')} />
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
              <Button variant="outline" size="sm" onClick={handleExportCdr}>
                <Download className="h-4 w-4 mr-2" />
                Export
              </Button>
            </div>
          </div>
        </CardHeader>
        <CardContent>
          {/* Filters */}
          {showFilters && (
            <div className="mb-6 p-4 bg-gray-50 rounded-lg border">
              <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
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
          {cdrIsLoading ? (
            <div className="flex items-center justify-center py-12">
              <Loader2 className="h-8 w-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <>
              <div className="overflow-x-auto">
                <table className="w-full">
                  <thead>
                    <tr className="border-b">
                      <th className="text-left p-4 font-medium whitespace-nowrap">Session Time</th>
                      <th className="text-left p-4 font-medium whitespace-nowrap">From</th>
                      <th className="text-left p-4 font-medium whitespace-nowrap">To</th>
                      <th className="text-left p-4 font-medium whitespace-nowrap">Disposition</th>
                      <th className="text-left p-4 font-medium whitespace-nowrap">Duration</th>
                      <th className="text-left p-4 font-medium whitespace-nowrap">Connected Time</th>
                      <th className="text-left p-4 font-medium whitespace-nowrap">Actions</th>
                    </tr>
                  </thead>
                  <tbody>
                    {!cdrData?.data || cdrData.data.length === 0 ? (
                      <tr>
                        <td colSpan={7} className="text-center py-12">
                          <Database className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                          <h3 className="text-lg font-semibold mb-2">No CDRs found</h3>
                          <p className="text-muted-foreground">
                            {Object.keys(cdrFilters).length > 1
                              ? 'Try adjusting your filters'
                              : 'CDRs will appear here once calls are completed'}
                          </p>
                        </td>
                      </tr>
                    ) : (
                      cdrData.data.map((cdr) => (
                        <tr key={cdr.id} className="border-b hover:bg-gray-50">
                          <td className="p-4 text-sm whitespace-nowrap">
                            {formatDateTime(cdr.session_timestamp)}
                          </td>
                          <td className="p-4 whitespace-nowrap">{formatPhoneNumber(cdr.from)}</td>
                          <td className="p-4 whitespace-nowrap">{formatPhoneNumber(cdr.to)}</td>
                          <td className="p-4">
                            <span
                              className={cn(
                                'px-2 py-1 rounded-full text-xs font-medium whitespace-nowrap',
                                getDispositionColor(cdr.disposition)
                              )}
                            >
                              {cdr.disposition}
                            </span>
                          </td>
                          <td className="p-4 text-muted-foreground whitespace-nowrap">
                            {cdr.duration_formatted}
                          </td>
                          <td className="p-4 text-muted-foreground whitespace-nowrap">
                            {cdr.billsec_formatted}
                          </td>
                          <td className="p-4">
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => handleViewCdrDetails(cdr)}
                            >
                              <Eye className="h-4 w-4 mr-1" />
                              Details
                            </Button>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                </table>
              </div>

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
            </>
          )}
        </CardContent>
      </Card>

      {/* CDR Details Dialog */}
      <Dialog open={showCdrDetails} onOpenChange={setShowCdrDetails}>
        <DialogContent className="max-w-4xl max-h-[80vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Call Detail Record</DialogTitle>
            <DialogDescription>
              Complete CDR information for call ID: {selectedCdr?.call_id}
            </DialogDescription>
          </DialogHeader>
          {selectedCdr && (
            <div className="space-y-4">
              <div className="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-lg">
                <div>
                  <div className="text-sm font-medium text-muted-foreground">From</div>
                  <div className="text-base">{formatPhoneNumber(selectedCdr.from)}</div>
                </div>
                <div>
                  <div className="text-sm font-medium text-muted-foreground">To</div>
                  <div className="text-base">{formatPhoneNumber(selectedCdr.to)}</div>
                </div>
                <div>
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
                <div>
                  <div className="text-sm font-medium text-muted-foreground">Session Time</div>
                  <div className="text-base">{formatDateTime(selectedCdr.session_timestamp)}</div>
                </div>
                <div>
                  <div className="text-sm font-medium text-muted-foreground">Total Duration</div>
                  <div className="text-base">{selectedCdr.duration_formatted}</div>
                </div>
                <div>
                  <div className="text-sm font-medium text-muted-foreground">Connected Duration</div>
                  <div className="text-base">{selectedCdr.billsec_formatted}</div>
                </div>
                {selectedCdr.call_start_time && (
                  <div>
                    <div className="text-sm font-medium text-muted-foreground">Call Start</div>
                    <div className="text-base">{formatDateTime(selectedCdr.call_start_time)}</div>
                  </div>
                )}
                {selectedCdr.call_answer_time && (
                  <div>
                    <div className="text-sm font-medium text-muted-foreground">Call Answer</div>
                    <div className="text-base">{formatDateTime(selectedCdr.call_answer_time)}</div>
                  </div>
                )}
                {selectedCdr.call_end_time && (
                  <div>
                    <div className="text-sm font-medium text-muted-foreground">Call End</div>
                    <div className="text-base">{formatDateTime(selectedCdr.call_end_time)}</div>
                  </div>
                )}
                <div>
                  <div className="text-sm font-medium text-muted-foreground">Domain</div>
                  <div className="text-base">{selectedCdr.domain}</div>
                </div>
                {selectedCdr.rated_cost !== null && selectedCdr.rated_cost !== undefined && (
                  <div>
                    <div className="text-sm font-medium text-muted-foreground">Cost</div>
                    <div className="text-base">${selectedCdr.rated_cost.toFixed(4)}</div>
                  </div>
                )}
              </div>

              {selectedCdr.raw_cdr && (
                <div>
                  <div className="text-sm font-semibold mb-2">Raw CDR Data</div>
                  <pre className="p-4 bg-gray-900 text-gray-100 rounded-lg overflow-x-auto text-xs">
                    {JSON.stringify(selectedCdr.raw_cdr, null, 2)}
                  </pre>
                </div>
              )}
            </div>
          )}
        </DialogContent>
      </Dialog>
    </div>
  );
}
