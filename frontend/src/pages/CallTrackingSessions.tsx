import { useState, useMemo } from 'react';
import { Phone, PhoneOff, CheckCircle } from 'lucide-react';
import { format } from 'date-fns';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { useCallTrackingSessions } from '@/hooks/useCallTrackingSessions';

export default function CallTrackingSessions() {
  const today = useMemo(() => new Date(), []);
  const thirtyDaysAgo = useMemo(() => {
    const d = new Date();
    d.setDate(d.getDate() - 30);
    return d;
  }, []);

  const [search, setSearch] = useState('');
  const [startDate, setStartDate] = useState<string>(format(thirtyDaysAgo, 'yyyy-MM-dd'));
  const [endDate, setEndDate] = useState<string>(format(today, 'yyyy-MM-dd'));
  const [convertedOnly, setConvertedOnly] = useState(false);

  const params = useMemo(
    () => ({
      search: search || undefined,
      start_date: startDate,
      end_date: endDate,
      is_converted: convertedOnly || undefined,
    }),
    [search, startDate, endDate, convertedOnly]
  );

  const { data, isLoading, isError, error } = useCallTrackingSessions(params);
  const sessions = data?.data ?? [];

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

  return (
    <div className="p-6 space-y-4">
      <h1 className="text-2xl font-bold">Call Tracking Sessions</h1>

      <div className="flex flex-col lg:flex-row gap-2">
        <Input
          placeholder="Search caller or campaign..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          className="lg:max-w-xs"
        />
        <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
        <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} />
        <Button variant={convertedOnly ? 'default' : 'outline'} onClick={() => setConvertedOnly((v) => !v)}>
          <CheckCircle className="h-4 w-4 mr-2" />
          Converted Only
        </Button>
      </div>

      {sessions.length === 0 ? (
        <Card>
          <CardContent className="text-center py-12">
            <Phone className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No sessions found</h3>
            <p className="text-muted-foreground">Try adjusting your filters.</p>
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
                  <TableCell>{session.duration}s</TableCell>
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
      )}
    </div>
  );
}
