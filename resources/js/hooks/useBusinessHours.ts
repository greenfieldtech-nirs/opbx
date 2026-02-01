import { useQuery, useMutation, useQueryClient } from 'react-query';
import { businessHoursApi } from '@/lib/api';
import { CreateBusinessHoursScheduleRequest } from '@/types/business-hours';
import { toast } from '@/hooks/use-toast';

const BUSINESS_HOURS_QUERY_KEY = 'business-hours';

// Form data type for creating/updating business hours
export type BusinessHoursFormData = CreateBusinessHoursScheduleRequest & {
  id?: string;
};

/**
 * Hook for fetching business hours schedules with optional search.
 */
export function useBusinessHours(search: string = '') {
  return useQuery({
    queryKey: [BUSINESS_HOURS_QUERY_KEY, search],
    queryFn: () => businessHoursApi.getAll({
      search: search || undefined,
      per_page: 50,
    }),
    keepPreviousData: true,
  });
}

/**
 * Hook for creating a new business hours schedule.
 */
export function useCreateBusinessHours() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data: BusinessHoursFormData) => businessHoursApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [BUSINESS_HOURS_QUERY_KEY] });
      toast({
        title: 'Success',
        description: 'Business hours schedule created successfully!',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create business hours schedule.',
        variant: 'destructive',
      });
    },
  });
}

/**
 * Hook for updating an existing business hours schedule.
 */
export function useUpdateBusinessHours() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: BusinessHoursFormData }) => 
      businessHoursApi.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [BUSINESS_HOURS_QUERY_KEY] });
      toast({
        title: 'Success',
        description: 'Business hours schedule updated successfully!',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update business hours schedule.',
        variant: 'destructive',
      });
    },
  });
}

/**
 * Hook for deleting a business hours schedule.
 */
export function useDeleteBusinessHours() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id: string) => businessHoursApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [BUSINESS_HOURS_QUERY_KEY] });
      toast({
        title: 'Success',
        description: 'Business hours schedule deleted successfully.',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete business hours schedule.',
        variant: 'destructive',
      });
    },
  });
}

/**
 * Hook for duplicating a business hours schedule.
 */
export function useDuplicateBusinessHours() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id: string) => businessHoursApi.duplicate(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [BUSINESS_HOURS_QUERY_KEY] });
      toast({
        title: 'Success',
        description: 'Business hours schedule duplicated successfully.',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to duplicate business hours schedule.',
        variant: 'destructive',
      });
    },
  });
}

/**
 * Hook for fetching a single business hours schedule by ID.
 */
export function useBusinessHoursById(id: string) {
  return useQuery({
    queryKey: [BUSINESS_HOURS_QUERY_KEY, id],
    queryFn: () => businessHoursApi.getById(id),
    enabled: !!id,
  });
}
