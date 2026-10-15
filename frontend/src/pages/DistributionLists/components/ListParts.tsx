import { useNavigate } from 'react-router-dom';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useListParts } from '@/hooks/useDistributionLists';

/**
 * The "(Part N)" lists a large upload to this list was split into, each a list of its own.
 */
export function ListParts({ listId }: { listId: string }) {
  const navigate = useNavigate();
  const { data, isLoading } = useListParts(listId);
  const parts = data?.data ?? [];

  return (
    <Card>
      <CardHeader>
        <CardTitle>Parts</CardTitle>
        <CardDescription>
          The file uploaded to this list had more entries than one list holds, so it was split into these lists.
          Each part is assigned to campaigns on its own.
        </CardDescription>
      </CardHeader>
      <CardContent className="p-0">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead>Part</TableHead>
              <TableHead>Status</TableHead>
              <TableHead>Entries</TableHead>
              <TableHead>Assigned Campaign</TableHead>
              <TableHead className="w-[100px]">Actions</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {isLoading ? (
              <TableRow>
                <TableCell colSpan={5} className="text-center py-8">Loading parts...</TableCell>
              </TableRow>
            ) : parts.length === 0 ? (
              <TableRow>
                <TableCell colSpan={5} className="text-center py-8 text-muted-foreground">No parts</TableCell>
              </TableRow>
            ) : (
              parts.map((part) => (
                <TableRow key={part.id}>
                  <TableCell className="font-medium">{part.name}</TableCell>
                  <TableCell>
                    <Badge variant="outline">{part.status_label}</Badge>
                  </TableCell>
                  <TableCell>{part.statistics.valid_rows.toLocaleString()}</TableCell>
                  <TableCell>{part.campaign?.name || '-'}</TableCell>
                  <TableCell>
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => navigate(`/ui/auto-dialer/distribution-lists/${part.id}`)}
                    >
                      Open
                    </Button>
                  </TableCell>
                </TableRow>
              ))
            )}
          </TableBody>
        </Table>
      </CardContent>
    </Card>
  );
}
