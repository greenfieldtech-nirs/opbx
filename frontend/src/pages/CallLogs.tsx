/**
 * Call Logs Page
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { callLogsService } from '@/services/callLogs.service';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { FileText, Download } from 'lucide-react';
import { formatPhoneNumber, formatDateTime, formatDuration, getStatusColor } from '@/utils/formatters';
import { cn } from '@/lib/utils';

export default function CallLogs() {
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['call-logs', page],
    queryFn: () => callLogsService.getAll({ page, per_page: 50 }),
  });

  const handleExport = async () => {
    try {
      const blob = await callLogsService.exportToCsv();
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `call-logs-${new Date().toISOString()}.csv`;
      document.body.appendChild(a);
      a.click();
      window.URL.revokeObjectURL(url);
      document.body.removeChild(a);
    } catch (error) {
      console.error('Export failed:', error);
    }
  };

  if (isLoading) {
    return <div className="flex items-center justify-center h-64">Loading...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">Call Logs</h1>
          <p className="text-muted-foreground">View call history and records</p>
        </div>
        <Button onClick={handleExport}>
          <Download className="h-4 w-4 mr-2" />
          Export CSV
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>Call History</CardTitle>
          <CardDescription>{data?.total || 0} calls</CardDescription>
        </CardHeader>
        <CardContent>
          <table className="w-full">
            <thead>
              <tr className="border-b">
                <th className="text-left p-4 font-medium">From</th>
                <th className="text-left p-4 font-medium">To</th>
                <th className="text-left p-4 font-medium">Status</th>
                <th className="text-left p-4 font-medium">Duration</th>
                <th className="text-left p-4 font-medium">Time</th>
              </tr>
            </thead>
            <tbody>
              {!data?.data || data.data.length === 0 ? (
                <tr>
                  <td colSpan={5} className="text-center py-12">
                    <FileText className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                    <h3 className="text-lg font-semibold mb-2">No call logs found</h3>
                    <p className="text-muted-foreground">
                      Call logs will appear here once you start receiving calls
                    </p>
                  </td>
                </tr>
              ) : (
                data.data.map((call) => (
                  <tr key={call.id} className="border-b hover:bg-gray-50">
                    <td className="p-4">{formatPhoneNumber(call.from_number)}</td>
                    <td className="p-4">{formatPhoneNumber(call.to_number)}</td>
                    <td className="p-4">
                      <span className={cn('px-2 py-1 rounded-full text-xs font-medium', getStatusColor(call.status))}>
                        {call.status}
                      </span>
                    </td>
                    <td className="p-4 text-muted-foreground">
                      {call.duration ? formatDuration(call.duration) : 'N/A'}
                    </td>
                    <td className="p-4 text-muted-foreground">{formatDateTime(call.created_at)}</td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </CardContent>
      </Card>
    </div>
  );
}
