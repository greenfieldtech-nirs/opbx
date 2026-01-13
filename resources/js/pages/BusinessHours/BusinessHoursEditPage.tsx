import React from 'react';
import { Link, useParams } from 'react-router-dom';
import { useQuery } from 'react-query';
import { businessHoursApi } from '@/lib/api';
import { BusinessHoursForm } from '@/components/BusinessHoursForm';

export function BusinessHoursEditPage() {
  const { id } = useParams<{ id: string }>();
  const { data: schedule, isLoading, error } = useQuery(
    ['business-hours', id],
    () => id ? businessHoursApi.getById(id) : null,
    { enabled: !!id }
  );

  if (isLoading) {
    return (
      <div className="container mx-auto py-8">
        <div className="flex items-center justify-center py-12">
          <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
        </div>
      </div>
    );
  }

  if (error || !schedule) {
    return (
      <div className="container mx-auto py-8">
        <div className="text-center py-12">
          <h2 className="text-xl font-semibold text-red-600 mb-2">Error Loading Schedule</h2>
          <p className="text-gray-600">Unable to load the business hours schedule.</p>
          <Link
            to="/business-hours"
            className="text-blue-600 hover:underline mt-4 inline-block"
          >
            Back to Business Hours
          </Link>
        </div>
      </div>
    );
  }

  return (
    <div className="container mx-auto py-8">
      <div className="mb-8">
        <div className="flex items-center space-x-4 mb-4">
          <Link
            to="/business-hours"
            className="text-blue-600 hover:underline"
          >
            ← Back to Business Hours
          </Link>
        </div>
        <h1 className="text-3xl font-bold">Edit Business Hours Schedule</h1>
        <p className="text-gray-600 mt-2">
          Modify the schedule and routing rules for "{schedule.name}".
        </p>
      </div>

      <BusinessHoursForm schedule={schedule} />
    </div>
  );
}