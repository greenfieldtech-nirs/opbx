import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

interface TopSourcesTableProps {
  sources: Array<{ source: string; calls: number; conversions: number }>;
}

export function TopSourcesTable({ sources }: TopSourcesTableProps) {
  if (sources.length === 0) {
    return <p className="text-muted-foreground text-center py-8">No source data.</p>;
  }

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Source</TableHead>
          <TableHead className="text-right">Calls</TableHead>
          <TableHead className="text-right">Conversions</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {sources.map((source, index) => (
          <TableRow key={`${source.source}-${index}`}>
            <TableCell>{source.source}</TableCell>
            <TableCell className="text-right">{source.calls}</TableCell>
            <TableCell className="text-right">{source.conversions}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
