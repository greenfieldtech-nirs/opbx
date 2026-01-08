import { useQuery } from '@tanstack/react-query'
import { usersService } from '@/services/users.service'

export interface UserFilters {
  search?: string
  role?: string
  status?: string
  sort?: 'name' | 'email' | 'created_at' | 'role' | 'status'
  order?: 'asc' | 'desc'
}

export function useUsers(filters: UserFilters = {}) {
  return useQuery({
    queryKey: ['users', filters],
    queryFn: () => usersService.getAll(filters),
    staleTime: 5 * 60 * 1000, // 5 minutes
  })
}