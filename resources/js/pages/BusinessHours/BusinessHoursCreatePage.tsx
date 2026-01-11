import React from 'react';
import { Link } from 'react-router-dom';
import { BusinessHoursForm } from '@/components/BusinessHoursForm';

export function BusinessHoursCreatePage() {
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
        <h1 className="text-3xl font-bold">Create Business Hours Schedule</h1>
        <p className="text-gray-600 mt-2">
          Define when your business is open and how calls should be routed during different hours.
        </p>
      </div>

      <BusinessHoursForm />
    </div>
  );
}