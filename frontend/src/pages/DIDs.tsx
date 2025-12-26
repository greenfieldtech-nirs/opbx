/**
 * DIDs (Phone Numbers) Page
 *
 * Manage inbound phone numbers and routing
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { didsService } from '@/services/dids.service';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Plus, PhoneCall } from 'lucide-react';
import { formatPhoneNumber, getStatusColor } from '@/utils/formatters';
import { cn } from '@/lib/utils';

export default function DIDs() {
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['dids', page],
    queryFn: () => didsService.getAll({ page, per_page: 20 }),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64">Loading...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">Phone Numbers</h1>
          <p className="text-muted-foreground">Manage inbound phone numbers and routing</p>
        </div>
        <Button>
          <Plus className="h-4 w-4 mr-2" />
          Add Phone Number
        </Button>
      </div>

      <Card>
        <CardHeader>
          <CardTitle>All Phone Numbers</CardTitle>
          <CardDescription>{data?.total || 0} DIDs configured</CardDescription>
        </CardHeader>
        <CardContent>
          <table className="w-full">
            <thead>
              <tr className="border-b">
                <th className="text-left p-4 font-medium">Phone Number</th>
                <th className="text-left p-4 font-medium">Routing Type</th>
                <th className="text-left p-4 font-medium">Destination</th>
                <th className="text-left p-4 font-medium">Status</th>
                <th className="text-right p-4 font-medium">Actions</th>
              </tr>
            </thead>
            <tbody>
              {data?.data.map((did) => (
                <tr key={did.id} className="border-b hover:bg-gray-50">
                  <td className="p-4">
                    <div className="flex items-center gap-3">
                      <PhoneCall className="h-5 w-5 text-blue-600" />
                      <span className="font-medium">{formatPhoneNumber(did.did_number)}</span>
                    </div>
                  </td>
                  <td className="p-4 capitalize">{did.routing_type.replace('_', ' ')}</td>
                  <td className="p-4 text-muted-foreground">
                    {did.extension?.extension_number || did.ring_group?.name || 'N/A'}
                  </td>
                  <td className="p-4">
                    <span className={cn('px-2 py-1 rounded-full text-xs font-medium', getStatusColor(did.status))}>
                      {did.status}
                    </span>
                  </td>
                  <td className="p-4">
                    <div className="flex justify-end gap-2">
                      <Button variant="ghost" size="sm">Edit</Button>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </CardContent>
      </Card>
    </div>
  );
}
