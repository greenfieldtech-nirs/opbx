import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';

interface TopCampaignsTableProps {
  campaigns: Array<{ campaign_id: number; campaign_name: string; calls: number; conversions: number }>;
}

export function TopCampaignsTable({ campaigns }: TopCampaignsTableProps) {
  if (campaigns.length === 0) {
    return <p className="text-muted-foreground text-center py-8">No campaign data.</p>;
  }

  return (
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Campaign</TableHead>
          <TableHead className="text-right">Calls</TableHead>
          <TableHead className="text-right">Conversions</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {campaigns.map((campaign) => (
          <TableRow key={campaign.campaign_id}>
            <TableCell>{campaign.campaign_name}</TableCell>
            <TableCell className="text-right">{campaign.calls}</TableCell>
            <TableCell className="text-right">{campaign.conversions}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}
