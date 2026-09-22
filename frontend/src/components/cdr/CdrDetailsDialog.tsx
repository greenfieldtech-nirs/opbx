/**
 * CdrDetailsDialog
 *
 * Shared "Call Detail Record" details view (identical presentation to Call
 * Logs), rendered for a fully-loaded CDR including raw_cdr.
 */

import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Prism as SyntaxHighlighter } from 'react-syntax-highlighter';
import { vscDarkPlus } from 'react-syntax-highlighter/dist/esm/styles/prism';
import { JsonViewer } from '@textea/json-viewer';
import { cn } from '@/lib/utils';
import { formatPhoneNumber, formatDateTime, getDispositionColor } from '@/utils/formatters';
import type { CallDetailRecord } from '@/types/api.types';

interface CdrDetailsDialogProps {
  cdr: CallDetailRecord | null;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function CdrDetailsDialog({ cdr, open, onOpenChange }: CdrDetailsDialogProps) {
  return (

      <Dialog open={open} onOpenChange={onOpenChange}>
        <DialogContent className="max-w-4xl max-h-[80vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Call Detail Record</DialogTitle>
            <DialogDescription className="break-all">
              Complete CDR information for call ID: {cdr?.call_id}
            </DialogDescription>
          </DialogHeader>
          {cdr && (
            <div className="space-y-4 min-w-0">
              <div className="grid grid-cols-2 gap-4 p-4 bg-gray-50 rounded-lg">
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">From</div>
                  <div className="text-base break-words">{formatPhoneNumber(cdr.from)}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">To</div>
                  <div className="text-base break-words">{formatPhoneNumber(cdr.to)}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Disposition</div>
                  <div>
                    <span
                      className={cn(
                        'px-2 py-1 rounded-full text-xs font-medium',
                        getDispositionColor(cdr.disposition)
                      )}
                    >
                      {cdr.disposition}
                    </span>
                  </div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Session Time</div>
                  <div className="text-base break-words">{formatDateTime(cdr.session_timestamp)}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Total Duration</div>
                  <div className="text-base break-words">{cdr.duration_formatted}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Connected Time</div>
                  <div className="text-base break-words">{cdr.billsec_formatted}</div>
                </div>
                <div className="min-w-0">
                  <div className="text-sm font-medium text-muted-foreground">Domain</div>
                  <div className="text-base break-all">{cdr.domain}</div>
                </div>
                {cdr.rated_cost !== null && cdr.rated_cost !== undefined && (
                  <div className="min-w-0">
                    <div className="text-sm font-medium text-muted-foreground">Cost</div>
                    <div className="text-base break-words">${cdr.rated_cost.toFixed(4)}</div>
                  </div>
                )}
              </div>

              {/* AMD Result Section */}
              {cdr.amd_status?.startsWith('Enabled') && (
                <div className="p-4 bg-green-50 rounded-lg border border-green-200">
                  <div className="text-sm font-semibold text-green-800 mb-2">AMD Result</div>
                  <div className="grid grid-cols-2 gap-4">
                    <div className="min-w-0">
                      <div className="text-xs font-medium text-green-700">Result</div>
                      <div className="text-sm text-green-900 font-medium">
                        {cdr.amd_status.split('::')[1] || 'Unknown'}
                      </div>
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-medium text-green-700">Confidence</div>
                      <div className="text-sm text-green-900">
                        {((cdr.raw_cdr?.session as any)?.profile?.amd?.confidence !== undefined)
                          ? `${((cdr.raw_cdr?.session as any)?.profile?.amd?.confidence * 100).toFixed(0)}%`
                          : (cdr.amd_confidence !== undefined)
                            ? `${(cdr.amd_confidence * 100).toFixed(0)}%`
                            : 'N/A'}
                      </div>
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-medium text-green-700">Detection Time</div>
                      <div className="text-sm text-green-900">
                        {((cdr.raw_cdr?.session as any)?.profile?.amd?.detectionTimeMs !== undefined)
                          ? `${(cdr.raw_cdr?.session as any)?.profile?.amd?.detectionTimeMs}ms`
                          : 'N/A'}
                      </div>
                    </div>
                    <div className="min-w-0">
                      <div className="text-xs font-medium text-green-700">Timestamp</div>
                      <div className="text-sm text-green-900">
                        {(cdr.raw_cdr?.session as any)?.profile?.amd?.timestamp
                          ? formatDateTime((cdr.raw_cdr?.session as any)?.profile?.amd?.timestamp)
                          : 'N/A'}
                      </div>
                    </div>
                    {((cdr.raw_cdr?.session as any)?.profile?.amd?.reason) && (
                      <div className="min-w-0 col-span-2">
                        <div className="text-xs font-medium text-green-700">Reason</div>
                        <div className="text-sm text-green-900 break-words">
                          {(cdr.raw_cdr?.session as any)?.profile?.amd?.reason}
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
                  {(cdr.raw_cdr?.session as any)?.profile?.application ? (
                    <div className="space-y-4 min-w-0">
                      {(cdr.raw_cdr as any).session.profile.application
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
                  {cdr.raw_cdr ? (
                    <div className="min-w-0">
                      <div className="text-sm font-semibold mb-2">Raw CDR Data</div>
                      <div className="border rounded overflow-x-auto bg-slate-900 max-w-full">
                        <div className="p-4 min-w-0">
                          <JsonViewer
                            value={cdr.raw_cdr}
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
      </Dialog>  );
}
