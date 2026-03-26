import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { AlertCircle } from 'lucide-react';
import { useValidationErrors } from '@/hooks/useDistributionLists';
import type { AutoDialerList } from '@/types';

interface ValidationErrorsDialogProps {
  list: AutoDialerList;
  open: boolean;
  onOpenChange: (open: boolean) => void;
}

export function ValidationErrorsDialog({
  list,
  open,
  onOpenChange,
}: ValidationErrorsDialogProps) {
  const { data, isLoading } = useValidationErrors(list.id);
  const errors = data?.data || [];

  const handleDownload = () => {
    // Create CSV content
    const csvContent = [
      ['Row', 'Phone Number', 'Error'],
      ...errors.map((e) => [e.row.toString(), e.phone_number, e.error]),
    ]
      .map((row) => row.join(','))
      .join('\n');

    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `${list.name}_validation_errors.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-[700px] max-h-[80vh]">
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <AlertCircle className="h-5 w-5 text-red-600" />
            Validation Errors
          </DialogTitle>
          <DialogDescription>
            The following errors were found when processing &quot;{list.name}&quot;
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <p className="text-center py-4">Loading errors...</p>
        ) : errors.length === 0 ? (
          <p className="text-center py-4">No validation errors found.</p>
        ) : (
          <>
            <div className="overflow-auto max-h-[400px] border rounded-md">
              <Table>
                <TableHeader>
                  <TableRow>
                    <TableHead>Row</TableHead>
                    <TableHead>Phone Number</TableHead>
                    <TableHead>Error</TableHead>
                  </TableRow>
                </TableHeader>
                <TableBody>
                  {errors.map((error, index) => (
                    <TableRow key={index}>
                      <TableCell>{error.row}</TableCell>
                      <TableCell>{error.phone_number}</TableCell>
                      <TableCell className="text-red-600">{error.error}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </div>

            <p className="text-sm text-muted-foreground mt-2">
              Total errors: {errors.length}
            </p>
          </>
        )}

        <DialogFooter>
          {errors.length > 0 && (
            <Button variant="outline" onClick={handleDownload}>Download CSV</Button>
          )}
          <Button onClick={() => onOpenChange(false)}>Close</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
