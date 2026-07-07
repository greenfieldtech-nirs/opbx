import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import type { CallTrackingNotificationLog } from '@/types/callTracking';

interface Props {
  logs: CallTrackingNotificationLog[];
  isLoading: boolean;
}

export function CallTrackingNotificationLogsTable({ logs, isLoading }: Props) {
  if (isLoading) return <p className="text-muted-foreground">Loading logs...</p>;
  if (logs.length === 0) return <p className="text-muted-foreground">No notification logs yet.</p>;

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Event</TableHead>
          <TableHead>URL</TableHead>
          <TableHead>Status</TableHead>
          <TableHead>Time</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {logs.map((log) => (
          <TableRow key={log.id}>
            <TableCell>{log.event_type}</TableCell>
            <TableCell className="max-w-xs truncate">{log.webhook_url}</TableCell>
            <TableCell>
              <Badge variant={log.is_success ? 'default' : 'destructive'}>
                {log.response_status_code ?? 'Error'}
              </Badge>
            </TableCell>
            <TableCell>{new Date(log.created_at).toLocaleString()}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
