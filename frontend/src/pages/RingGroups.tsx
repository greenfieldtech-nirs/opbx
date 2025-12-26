/**
 * Ring Groups Page
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { ringGroupsService } from '@/services/ringGroups.service';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Plus, UserPlus } from 'lucide-react';

export default function RingGroups() {
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['ring-groups', page],
    queryFn: () => ringGroupsService.getAll({ page, per_page: 20 }),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64">Loading...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">Ring Groups</h1>
          <p className="text-muted-foreground">Manage extension ring groups</p>
        </div>
        <Button>
          <Plus className="h-4 w-4 mr-2" />
          Add Ring Group
        </Button>
      </div>

      <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        {data?.data.map((group) => (
          <Card key={group.id}>
            <CardHeader>
              <div className="flex items-center gap-3">
                <div className="h-10 w-10 rounded-full bg-purple-100 flex items-center justify-center">
                  <UserPlus className="h-5 w-5 text-purple-600" />
                </div>
                <div>
                  <CardTitle>{group.name}</CardTitle>
                  <CardDescription className="capitalize">{group.strategy.replace('_', ' ')}</CardDescription>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <p className="text-sm text-muted-foreground">
                {group.members.length} members • {group.ring_timeout}s timeout
              </p>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
