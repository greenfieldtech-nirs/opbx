import { Input } from '@/components/ui/input'
import { Button } from '@/components/ui/button'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Search, X, Filter } from 'lucide-react'
import { UserFilters } from '@/hooks/useUsers'

interface UserFiltersProps {
  filters: UserFilters
  onFiltersChange: (filters: Partial<UserFilters>) => void
  userCount: number
  hasActiveFilters: boolean
  onClearFilters: () => void
}

export function UserFiltersComponent({
  filters,
  onFiltersChange,
  userCount,
  hasActiveFilters,
  onClearFilters
}: UserFiltersProps) {
  return (
    <Card>
      <CardHeader className="pb-4">
        <div className="flex items-center justify-between">
          <CardTitle className="flex items-center gap-2">
            <Filter className="h-5 w-5" />
            Filters
          </CardTitle>
          {hasActiveFilters && (
            <Button
              variant="outline"
              size="sm"
              onClick={onClearFilters}
              className="h-8"
            >
              <X className="h-4 w-4 mr-1" />
              Clear
            </Button>
          )}
        </div>
      </CardHeader>
      <CardContent className="space-y-4">
        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          {/* Search */}
          <div className="space-y-2">
            <label className="text-sm font-medium">Search</label>
            <div className="relative">
              <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
              <Input
                placeholder="Search by name or email..."
                value={filters.search || ''}
                onChange={(e) => onFiltersChange({ search: e.target.value })}
                className="pl-10"
              />
            </div>
          </div>

          {/* Role Filter */}
          <div className="space-y-2">
            <label className="text-sm font-medium">Role</label>
            <Select
              value={filters.role || ''}
              onValueChange={(value) => onFiltersChange({ role: value || undefined })}
            >
              <SelectTrigger>
                <SelectValue placeholder="All roles" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="">All roles</SelectItem>
                <SelectItem value="owner">Owner</SelectItem>
                <SelectItem value="pbx_admin">PBX Admin</SelectItem>
                <SelectItem value="pbx_user">PBX User</SelectItem>
                <SelectItem value="reporter">Reporter</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Status Filter */}
          <div className="space-y-2">
            <label className="text-sm font-medium">Status</label>
            <Select
              value={filters.status || ''}
              onValueChange={(value) => onFiltersChange({ status: value || undefined })}
            >
              <SelectTrigger>
                <SelectValue placeholder="All statuses" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="">All statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Sort */}
          <div className="space-y-2">
            <label className="text-sm font-medium">Sort by</label>
            <Select
              value={`${filters.sort || 'name'}:${filters.order || 'asc'}`}
              onValueChange={(value) => {
                const [sort, order] = value.split(':') as [UserFilters['sort'], UserFilters['order']]
                onFiltersChange({ sort, order })
              }}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="name:asc">Name (A-Z)</SelectItem>
                <SelectItem value="name:desc">Name (Z-A)</SelectItem>
                <SelectItem value="email:asc">Email (A-Z)</SelectItem>
                <SelectItem value="email:desc">Email (Z-A)</SelectItem>
                <SelectItem value="created_at:desc">Newest first</SelectItem>
                <SelectItem value="created_at:asc">Oldest first</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </div>

        {/* Results count */}
        <div className="text-sm text-muted-foreground">
          Showing {userCount} user{userCount !== 1 ? 's' : ''}
          {hasActiveFilters && ' (filtered)'}
        </div>
      </CardContent>
    </Card>
  )
}