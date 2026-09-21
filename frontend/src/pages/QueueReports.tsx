/**
 * Queue Reports Page
 * Per-call history rows across all queues with filters and CSV export.
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Download, PhoneCall } from 'lucide-react';

import {
  callQueuesService,
  queueReportsService,
  type QueueCallDisposition,
  type QueueCallRow,
} from '@/services/callQueues.service';
import { StandardDataTable, Column, EmptyState } from '@/components/design-system';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';

const DISPOSITION_VARIANTS: Record<string, 'default' | 'destructive' | 'secondary'> = {
  answered: 'default',
  abandoned: 'destructive',
  overflow: 'secondary',
};

function formatDate(value?: string): string {
  if (!value) return '—';
  return new Date(value).toLocaleString();
}

export default function QueueReports() {
  const [queueId, setQueueId] = useState<string>('');
  const [disposition, setDisposition] = useState<string>('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');

  const params = {
    queue_id: queueId || undefined,
    disposition: disposition || undefined,
    from: from || undefined,
    to: to || undefined,
    per_page: 50,
  };

  const { data: queuesData } = useQuery({
    queryKey: ['call-queues'],
    queryFn: () => callQueuesService.getAll({ per_page: 100 }),
  });

  const { data, isLoading } = useQuery({
    queryKey: ['queue-calls', params],
    queryFn: () => queueReportsService.list(params),
  });

  const columns: Column<QueueCallRow>[] = [
    { header: 'Queue', accessorKey: 'call_queue_id' },
    { header: 'From', accessorKey: 'from_number' },
    {
      header: 'Entered',
      cell: (row) => formatDate(row.entered_at),
    },
    {
      header: 'Waiting',
      cell: (row) => (row.waiting_seconds !== null ? `${row.waiting_seconds}s` : '—'),
    },
    {
      header: 'Handling',
      cell: (row) => (row.handling_seconds !== null ? `${row.handling_seconds}s` : '—'),
    },
    {
      header: 'Disposition',
      cell: (row) =>
        row.disposition ? (
          <Badge variant={DISPOSITION_VARIANTS[row.disposition] ?? 'outline'}>{row.disposition}</Badge>
        ) : (
          '—'
        ),
    },
  ];

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-2xl font-semibold">Queue Reports</h1>
          <p className="text-sm text-muted-foreground">
            Per-call history across all queues: waiting and handling times, dispositions, and agents.
          </p>
        </div>
        <Button asChild variant="outline">
          <a href={queueReportsService.exportCsvUrl(params)} download>
            <Download className="mr-2 h-4 w-4" />
            Export CSV
          </a>
        </Button>
      </div>

      <Card>
        <CardContent className="pt-6">
          <div className="grid grid-cols-2 md:grid-cols-5 gap-4 mb-4">
            <div className="space-y-2">
              <Label>Queue</Label>
              <Select value={queueId} onValueChange={setQueueId}>
                <SelectTrigger>
                  <SelectValue placeholder="All queues" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="">All queues</SelectItem>
                  {(queuesData?.data ?? []).map((q) => (
                    <SelectItem key={q.id} value={String(q.id)}>
                      {q.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Disposition</Label>
              <Select value={disposition} onValueChange={setDisposition}>
                <SelectTrigger>
                  <SelectValue placeholder="All" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="">All</SelectItem>
                  <SelectItem value="answered">Answered</SelectItem>
                  <SelectItem value="abandoned">Abandoned</SelectItem>
                  <SelectItem value="overflow">Overflow</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>From</Label>
              <Input type="date" value={from} onChange={(e) => setFrom(e.target.value)} />
            </div>
            <div className="space-y-2">
              <Label>To</Label>
              <Input type="date" value={to} onChange={(e) => setTo(e.target.value)} />
            </div>
            <div className="flex items-end">
              <Button
                variant="ghost"
                onClick={() => {
                  setQueueId('');
                  setDisposition('');
                  setFrom('');
                  setTo('');
                }}
              >
                Reset
              </Button>
            </div>
          </div>

          <StandardDataTable
            data={data?.data ?? []}
            columns={columns}
            isLoading={isLoading}
            identityIcon={PhoneCall}
            identityIconColor="text-indigo-600"
            identityIconBg="bg-indigo-100"
            getIdentityPrimary={(row) => row.call_id}
            getIdentitySecondary={(row) => `Queue #${row.call_queue_id}`}
            emptyState={
              <EmptyState
                icon={PhoneCall}
                title="No queue calls"
                description="Queue call records will appear here once callers enter a queue."
              />
            }
          />
        </CardContent>
      </Card>
    </div>
  );
}
