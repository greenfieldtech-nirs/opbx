import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useQuery, useMutation, useQueryClient } from 'react-query';
import { businessHoursApi } from '@/lib/api';
import { BusinessHoursSchedule } from '@/types/business-hours';

export function BusinessHoursPage() {
  const [search, setSearch] = useState('');
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false);
  const [scheduleToDelete, setScheduleToDelete] = useState<BusinessHoursSchedule | null>(null);
  const queryClient = useQueryClient();

  const { data: schedulesResponse, isLoading } = useQuery(
    ['business-hours', search],
    () => businessHoursApi.getAll({
      search: search || undefined,
      per_page: 50,
    }),
    {
      keepPreviousData: true,
    }
  );

  const deleteMutation = useMutation(
    (id: string) => businessHoursApi.delete(id),
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['business-hours']);
        alert('Business hours schedule deleted successfully.');
        setDeleteDialogOpen(false);
        setScheduleToDelete(null);
      },
      onError: (error: any) => {
        alert(error.response?.data?.message || 'Failed to delete business hours schedule.');
      },
    }
  );

  const duplicateMutation = useMutation(
    (id: string) => businessHoursApi.duplicate(id),
    {
      onSuccess: () => {
        queryClient.invalidateQueries(['business-hours']);
        alert('Business hours schedule duplicated successfully.');
      },
      onError: (error: any) => {
        alert(error.response?.data?.message || 'Failed to duplicate business hours schedule.');
      },
    }
  );

  const handleDelete = (schedule: BusinessHoursSchedule) => {
    setScheduleToDelete(schedule);
    setDeleteDialogOpen(true);
  };

  const confirmDelete = () => {
    if (scheduleToDelete) {
      deleteMutation.mutate(scheduleToDelete.id);
    }
  };

  const handleDuplicate = (schedule: BusinessHoursSchedule) => {
    duplicateMutation.mutate(schedule.id);
  };

  const schedules = schedulesResponse?.data || [];

  return (
    <div className="container mx-auto py-8">
      <div className="flex items-center justify-between mb-8">
        <div>
          <h1 className="text-3xl font-bold">Business Hours</h1>
          <p className="text-gray-600 mt-2">
            Manage business hours schedules for call routing
          </p>
        </div>
        <Link
          to="/business-hours/create"
          className="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700"
        >
          Create Schedule
        </Link>
      </div>

      <div className="bg-white rounded-lg shadow">
        <div className="p-6 border-b">
          <div className="flex items-center justify-between">
            <h2 className="text-xl font-semibold">Schedules</h2>
            <div className="flex items-center space-x-2">
              <input
                type="text"
                placeholder="Search schedules..."
                value={search}
                onChange={(e) => setSearch(e.target.value)}
                className="border border-gray-300 rounded px-3 py-2 w-64"
              />
            </div>
          </div>
        </div>
        <div className="p-6">
          {isLoading ? (
            <div className="flex items-center justify-center py-12">
              <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-blue-600"></div>
            </div>
          ) : schedules.length === 0 ? (
            <div className="text-center py-12">
              <div className="text-6xl mb-4">🕒</div>
              <h3 className="text-lg font-semibold mb-2">No business hours schedules found</h3>
              <p className="text-gray-600 mb-4">
                {search
                  ? 'Try adjusting your search terms'
                  : 'Get started by creating your first business hours schedule'}
              </p>
              {!search && (
                <Link
                  to="/business-hours/create"
                  className="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700 inline-flex items-center"
                >
                  <span className="mr-2">+</span>
                  Create Schedule
                </Link>
              )}
            </div>
          ) : (
            <table className="w-full">
              <thead>
                <tr className="border-b">
                  <th className="text-left py-2">Name</th>
                  <th className="text-left py-2">Status</th>
                  <th className="text-left py-2">Current Status</th>
                  <th className="text-left py-2">Created</th>
                  <th className="text-left py-2 w-20">Actions</th>
                </tr>
              </thead>
              <tbody>
                {schedules.map((schedule) => (
                  <tr key={schedule.id} className="border-b">
                    <td className="py-2">
                      <Link
                        to={`/business-hours/${schedule.id}/edit`}
                        className="text-blue-600 hover:underline"
                      >
                        {schedule.name}
                      </Link>
                    </td>
                    <td className="py-2">
                      <span className={`px-2 py-1 rounded text-xs ${
                        schedule.status === 'active'
                          ? 'bg-green-100 text-green-800'
                          : 'bg-gray-100 text-gray-800'
                      }`}>
                        {schedule.status}
                      </span>
                    </td>
                    <td className="py-2">
                      <span className={`px-2 py-1 rounded text-xs ${
                        schedule.current_status === 'open'
                          ? 'bg-green-100 text-green-800'
                          : schedule.current_status === 'closed'
                          ? 'bg-gray-100 text-gray-800'
                          : 'bg-blue-100 text-blue-800'
                      }`}>
                        {schedule.current_status}
                      </span>
                    </td>
                    <td className="py-2">
                      {new Date(schedule.created_at).toLocaleDateString()}
                    </td>
                    <td className="py-2">
                      <div className="flex space-x-2">
                        <Link
                          to={`/business-hours/${schedule.id}/edit`}
                          className="text-blue-600 hover:underline text-sm"
                        >
                          Edit
                        </Link>
                        <button
                          onClick={() => handleDuplicate(schedule)}
                          className="text-blue-600 hover:underline text-sm"
                        >
                          Duplicate
                        </button>
                        <button
                          onClick={() => handleDelete(schedule)}
                          className="text-red-600 hover:underline text-sm"
                        >
                          Delete
                        </button>
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </div>
      </div>

      {deleteDialogOpen && (
        <div className="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center">
          <div className="bg-white p-6 rounded-lg shadow-lg max-w-md w-full">
            <h3 className="text-lg font-semibold mb-4">Delete Business Hours Schedule</h3>
            <p className="text-gray-600 mb-6">
              Are you sure you want to delete "{scheduleToDelete?.name}"? This action cannot be undone.
            </p>
            <div className="flex justify-end space-x-4">
              <button
                onClick={() => setDeleteDialogOpen(false)}
                className="px-4 py-2 text-gray-600 hover:text-gray-800"
              >
                Cancel
              </button>
              <button
                onClick={confirmDelete}
                className="px-4 py-2 bg-red-600 text-white rounded hover:bg-red-700"
              >
                Delete
              </button>
            </div>
          </div>
        </div>
      )}
    </div>
  );
}