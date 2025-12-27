/**
 * Business Hours Page
 */

import { useState } from 'react';
import { useQuery } from '@tanstack/react-query';
import { businessHoursService } from '@/services/businessHours.service';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Plus, Clock } from 'lucide-react';

export default function BusinessHours() {
  const [page, setPage] = useState(1);

  const { data, isLoading } = useQuery({
    queryKey: ['business-hours', page],
    queryFn: () => businessHoursService.getAll({ page, per_page: 20 }),
  });

  if (isLoading) {
    return <div className="flex items-center justify-center h-64">Loading...</div>;
  }

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">Business Hours</h1>
          <p className="text-muted-foreground">Configure time-based call routing</p>
        </div>
        <Button>
          <Plus className="h-4 w-4 mr-2" />
          Add Schedule
        </Button>
      </div>

      {!data?.data || data.data.length === 0 ? (
        <Card>
          <CardContent className="p-12 text-center">
            <Clock className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
            <h3 className="text-lg font-semibold mb-2">No schedules found</h3>
            <p className="text-muted-foreground mb-4">
              Get started by creating your first business hours schedule
            </p>
            <Button>
              <Plus className="h-4 w-4 mr-2" />
              Add Schedule
            </Button>
          </CardContent>
        </Card>
      ) : (
        <div className="grid gap-4">
          {data.data.map((schedule) => (
            <Card key={schedule.id}>
              <CardHeader>
                <div className="flex items-center justify-between">
                  <div className="flex items-center gap-3">
                    <div className="h-10 w-10 rounded-full bg-orange-100 flex items-center justify-center">
                      <Clock className="h-5 w-5 text-orange-600" />
                    </div>
                    <div>
                      <CardTitle>{schedule.name}</CardTitle>
                      <CardDescription>Timezone: {schedule.timezone}</CardDescription>
                    </div>
                  </div>
                  <Button variant="outline" size="sm">Edit Schedule</Button>
                </div>
              </CardHeader>
              <CardContent>
                <div className="grid gap-2">
                  {Object.entries(schedule.schedule).map(([day, hours]) => (
                    <div key={day} className="flex items-center justify-between text-sm">
                      <span className="font-medium capitalize">{day}</span>
                      <span className="text-muted-foreground">
                        {hours.enabled ? `${hours.open} - ${hours.close}` : 'Closed'}
                      </span>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          ))}
        </div>
      )}
    </div>
  );
}
