import { useState, useCallback } from 'react'

export interface UserFilters {
  search?: string
  role?: string
  status?: string
  sort?: 'name' | 'email' | 'created_at' | 'role' | 'status'
  order?: 'asc' | 'desc'
}

export function useUserFilters(initialFilters: UserFilters = {}) {
  const [filters, setFilters] = useState<UserFilters>(initialFilters)

  const updateSearch = useCallback((search: string) => {
    setFilters(prev => ({ ...prev, search: search || undefined }))
  }, [])

  const updateRole = useCallback((role: string) => {
    setFilters(prev => ({ ...prev, role: role || undefined }))
  }, [])

  const updateStatus = useCallback((status: string) => {
    setFilters(prev => ({ ...prev, status: status || undefined }))
  }, [])

  const updateSort = useCallback((sort: UserFilters['sort']) => {
    setFilters(prev => ({ ...prev, sort }))
  }, [])

  const updateOrder = useCallback((order: UserFilters['order']) => {
    setFilters(prev => ({ ...prev, order }))
  }, [])

  const toggleSort = useCallback((field: UserFilters['sort']) => {
    setFilters(prev => {
      if (prev.sort === field) {
        // Toggle order if same field
        return {
          ...prev,
          order: prev.order === 'asc' ? 'desc' : 'asc'
        }
      } else {
        // New field, default to ascending
        return {
          ...prev,
          sort: field,
          order: 'asc'
        }
      }
    })
  }, [])

  const clearFilters = useCallback(() => {
    setFilters({})
  }, [])

  const hasActiveFilters = Object.values(filters).some(value =>
    value !== undefined && value !== ''
  )

  return {
    filters,
    updateSearch,
    updateRole,
    updateStatus,
    updateSort,
    updateOrder,
    toggleSort,
    clearFilters,
    hasActiveFilters,
  }
}