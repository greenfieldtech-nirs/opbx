import { FileSpreadsheet } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';

export function DistributionListsEmpty() {
  return (
    <Card>
      <CardContent className="flex flex-col items-center justify-center py-12">
        <FileSpreadsheet className="h-12 w-12 text-muted-foreground mb-4" />
        <h3 className="text-lg font-semibold mb-2">No distribution lists found</h3>
        <p className="text-muted-foreground text-center max-w-md">
          Get started by creating your first distribution list. You can upload phone numbers via CSV or add them manually.
        </p>
      </CardContent>
    </Card>
  );
}
