/**
 * Debug Dialog Component for Call Notifications
 *
 * Displays detailed request/response information for webhook delivery logs
 */

import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { RefreshCw } from 'lucide-react';
import { JsonViewer } from '@/components/ui/JsonViewer';
import { safeParseJson } from '@/utils/formatters';
import type { CallNotificationLog } from '@/types';

interface DebugDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  selectedLog: CallNotificationLog | null;
  sessionLogs: CallNotificationLog[] | undefined;
  isLoadingSessionLogs: boolean;
  selectedSessionToken: string | null;
  onSelectLog: (log: CallNotificationLog) => void;
}

export function DebugDialog({
  open,
  onOpenChange,
  selectedLog,
  sessionLogs,
  isLoadingSessionLogs,
  selectedSessionToken,
  onSelectLog,
}: DebugDialogProps) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-6xl max-h-[90vh]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            Call Notification Debug
          </DialogTitle>
          <DialogDescription>
            Session: {selectedSessionToken?.substring(0, 24)}...
          </DialogDescription>
        </DialogHeader>
        {selectedLog && sessionLogs && (
          <div className="grid grid-cols-2 gap-4 overflow-hidden max-h-[75vh]">
            {/* Left Side - Notification History */}
            <div className="border rounded-lg overflow-auto max-h-[75vh]">
              <div className="sticky top-0 bg-muted p-2 border-b">
                <h3 className="text-sm font-semibold">Notifications</h3>
              </div>
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead className="text-xs">Time</TableHead>
                    <TableHead className="text-xs">Status</TableHead>
                    <TableHead className="text-xs">Response</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {sessionLogs.map((log) => (
                    <TableRow
                      key={log.id}
                      className={`cursor-pointer ${
                        log.id === selectedLog.id ? 'bg-muted' : ''
                      }`}
                      onClick={() => onSelectLog(log)}
                    >
                      <TableCell className="text-xs whitespace-nowrap">
                        {new Date(log.created_at).toLocaleTimeString()}
                      </TableCell>
                      <TableCell className="text-xs">
                        <span
                          className={`px-1 rounded text-xs ${
                            log.is_success
                              ? 'bg-green-100 text-green-800'
                              : 'bg-red-100 text-red-800'
                          }`}
                        >
                          {log.status}
                        </span>
                      </TableCell>
                      <TableCell className="text-xs">
                        {log.response_status_code || '-'}
                      </TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            {/* Right Side - Selected Notification Details */}
            <div className="space-y-4 overflow-auto max-h-[75vh] pr-2">
              {/* Selected Notification Info */}
              <div className="flex items-center justify-between">
                <div>
                  <span className="text-sm text-muted-foreground">Selected: </span>
                  <span className="text-sm font-medium">
                    {new Date(selectedLog.created_at).toLocaleString()}
                  </span>
                </div>
                <Badge variant={selectedLog.is_success ? 'default' : 'destructive'}>
                  {selectedLog.is_success ? 'Success' : 'Failed'}
                </Badge>
              </div>

              {/* Request Section */}
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-sm font-medium">Request</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-xs">
                  <div>
                    <span className="font-semibold">URL: </span>
                    <span className="font-mono break-all">{selectedLog.webhook_url}</span>
                  </div>
                  <div>
                    <span className="font-semibold">Method: </span>
                    <span className="font-mono">POST</span>
                  </div>
                  {selectedLog.request_headers && (
                    <div>
                      <span className="font-semibold">Headers:</span>
                      <pre className="mt-1 p-2 rounded border bg-muted text-foreground overflow-x-auto text-xs">
                        {JSON.stringify(selectedLog.request_headers, null, 2)}
                      </pre>
                    </div>
                  )}
                  {selectedLog.request_body && (
                    <div>
                      <span className="font-semibold">Body:</span>
                      <div className="mt-1">
                        {(() => {
                          const { data, error } = safeParseJson(selectedLog.request_body);
                          if (error) {
                            return (
                              <pre className="p-2 rounded border bg-muted text-foreground overflow-x-auto text-xs">
                                {selectedLog.request_body}
                              </pre>
                            );
                          }
                          return <JsonViewer data={data} light className="p-1" />;
                        })()}
                      </div>
                    </div>
                  )}
                </CardContent>
              </Card>

              {/* Response Section */}
              <Card>
                <CardHeader className="pb-2">
                  <CardTitle className="text-sm font-medium">Response</CardTitle>
                </CardHeader>
                <CardContent className="space-y-2 text-xs">
                  <div className="flex items-center gap-4">
                    <div>
                      <span className="font-semibold">Status: </span>
                      <span
                        className={`font-mono ${
                          selectedLog.response_status_code &&
                          selectedLog.response_status_code >= 200 &&
                          selectedLog.response_status_code < 300
                            ? 'text-green-600'
                            : 'text-red-600'
                        }`}
                      >
                        {selectedLog.response_status_code || 'N/A'}
                      </span>
                    </div>
                    <div>
                      <span className="font-semibold">Time: </span>
                      <span className="font-mono">
                        {selectedLog.response_time_ms ? `${selectedLog.response_time_ms}ms` : 'N/A'}
                      </span>
                    </div>
                  </div>
                  {selectedLog.response_headers && (
                    <div>
                      <span className="font-semibold">Headers:</span>
                      <pre className="mt-1 p-2 rounded border bg-muted text-foreground overflow-x-auto text-xs">
                        {JSON.stringify(selectedLog.response_headers, null, 2)}
                      </pre>
                    </div>
                  )}
                  {selectedLog.response_body && (
                    <div>
                      <span className="font-semibold">Body:</span>
                      <div className="mt-1">
                        {(() => {
                          const { data, error } = safeParseJson(selectedLog.response_body);
                          if (error) {
                            return (
                              <pre className="p-2 rounded border bg-muted text-foreground overflow-x-auto text-xs">
                                {selectedLog.response_body}
                              </pre>
                            );
                          }
                          return <JsonViewer data={data} light className="p-1" />;
                        })()}
                      </div>
                    </div>
                  )}
                  {!selectedLog.response_body && !selectedLog.response_headers && (
                    <p className="text-muted-foreground italic">No response received</p>
                  )}
                </CardContent>
              </Card>

              {/* Error Section */}
              {selectedLog.error_message && (
                <Card className="border-red-200 bg-red-50">
                  <CardHeader className="pb-2">
                    <CardTitle className="text-sm font-medium text-red-800">
                      Error
                    </CardTitle>
                  </CardHeader>
                  <CardContent>
                    <p className="text-xs text-red-700 font-mono">
                      {selectedLog.error_message}
                    </p>
                  </CardContent>
                </Card>
              )}
            </div>
          </div>
        )}
        {isLoadingSessionLogs && (
          <div className="flex items-center justify-center p-8">
            <RefreshCw className="h-6 w-6 animate-spin text-muted-foreground" />
          </div>
        )}
      </DialogContent>
    </Dialog>
  );
}

export default DebugDialog;
