import { useState, useMemo, useEffect } from 'react';
import { Phone, PhoneOff, CheckCircle } from 'lucide-react';
import { format } from 'date-fns';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { Label } from '@/components/ui/label';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useCallTrackingSessions } from '@/hooks/useCallTrackingSessions';

function formatDuration(seconds: number): string {
  const m = Math.floor(seconds / 60);
  const s = seconds % 60;
  return `${m}:${s.toString().padStart(2, '0')}`;
}

export default function CallTrackingSessions() {
  const today = useMemo(() => new Date(), []);
  const thirtyDaysAgo = useMemo(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d;
  }, []);

  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState(search);
  const [startDate, setStartDate] = useState<string>(format(thirtyDaysAgo, 'yyyy-MM-dd'));
  const [endDate, setEndDate] = useState<string>(format(today, 'yyyy-MM-dd'));
  const [convertedOnly, setConvertedOnly] = useState(false);
  const [page, setPage] = useState(1);

  useEffect(() => {
    const timer = setTimeout(() => setDebouncedSearch(search), 300);
    return () => clearTimeout(timer);
  }, [search]);

  useEffect(() => {
    setPage(1);
  }, [debouncedSearch, startDate, endDate, convertedOnly]);

  const params = useMemo(
    () => ({
      search: debouncedSearch || undefined,
      start_date: startDate,
      end_date: endDate,
      is_converted: convertedOnly || undefined,
      page,
    }),
    [debouncedSearch, startDate, endDate, convertedOnly, page]
  );

  const isDateRangeValid = startDate <= endDate;

  const { data, isLoading, isError, error } = useCallTrackingSessions(params, {
    enabled: isDateRangeValid,
  });
  const sessions = data?.data ?? [];
  const meta = data?.meta;

  if (isLoading) {
    return <p className="p-6 text-muted-foreground">Loading sessions...</p>;
  }

  if (isError) {
    return (
      <div className="p-6">
        <p className="text-red-600">Failed to load sessions: {(error as Error)?.message || 'Unknown error'}</p>
      </div>
    );
  }

  const hasActiveFilters = debouncedSearch !== '' || convertedOnly || startDate !== format(thirtyDaysAgo, 'yyyy-MM-dd') || endDate !== format(today, 'yyyy-MM-dd');

  return (
    <div className="p-6 space-y-4">
      <h1 className="text-2xl font-bold">Call Tracking Sessions</h1>

      <div className="flex flex-col lg:flex-row gap-2">
        <Input
          placeholder="Search caller or called number..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="lg:max-w-xs"
        />
        <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
        <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
        <div className="flex items-center space-x-2">
          <Switch
            id="converted-only"
            checked={convertedOnly}
            onCheckedChange={(checked) => setConvertedOnly(checked)}
          />
          <Label htmlFor="converted-only" className="cursor-pointer">Converted Only</Label>
        </div>
      </div>

      {!isDateRangeValid && (
        <p className="text-red-600">Start date must be before or equal to end date.</p>
      )}

      {isDateRangeValid && (sessions.length === 0 ? (
        <Card>
          <CardContent className="text-center py-12">
            <Phone className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No sessions found</h3>
            <p className="text-muted-foreground mb-4">
              {hasActiveFilters ? 'Try adjusting your filters' : 'Sessions will appear once calls are tracked.'}
            </p>
          </CardContent>
        </Card>
      ) : (
        <Card>
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>Caller</TableHead>
                <TableHead>Called Number</TableHead>
                <TableHead>Campaign</TableHead>
                <TableHead>Source/Medium</TableHead>
                <TableHead>Duration</TableHead>
                <TableHead>Disposition</TableHead>
                <TableHead>Converted</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {sessions.map((session) => (
                <TableRow key={session.id}>
                  <TableCell>{session.caller_number}</TableCell>
                  <TableCell>{session.called_number}</TableCell>
                  <TableCell>{session.campaign_name || '—'}</TableCell>
                  <TableCell className="text-sm text-muted-foreground">
                    {session.source || '—'} / {session.medium || '—'}
                  </TableCell>
                  <TableCell>{formatDuration(session.duration)}</TableCell>
                  <TableCell>
                    <Badge variant={session.is_answered ? 'default' : 'secondary'}>
                      {session.is_answered ? 'Answered' : session.disposition}
                    </Badge>
                  </TableCell>
                  <TableCell>
                    {session.is_converted ? (
                      <CheckCircle className="h-5 w-5 text-green-600" />
                    ) : (
                      <PhoneOff className="h-5 w-5 text-muted-foreground" />
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </Card>
      ))}

      {meta && meta.last_page > 1 && (
        <div className="flex items-center justify-between">
          <Button variant="outline" disabled={page <= 1} onClick={() => setPage((p) => p - 1)}>Previous</Button>
          <span className="text-sm text-muted-foreground">Page {meta.current_page} of {meta.last_page}</span>
          <Button variant="outline" disabled={page >= meta.last_page} onClick={() => setPage((p) => p + 1)}>Next</Button>
        </div>
      )}
    </div>
  );
}
