import { useState } from 'react'
import { toast } from 'sonner'
import { Plus } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Sheet, SheetContent, SheetHeader, SheetTitle } from '@/components/ui/sheet'

// Hooks
import { useUsers } from '@/hooks/useUsers'
import { useUserFilters } from '@/hooks/useUserFilters'
import { useCreateUser, useUpdateUser, useDeleteUser } from '@/hooks/useUserMutations'

// Components
import { UserTable } from '@/components/users/UserTable'
import { UserFiltersComponent } from '@/components/users/UserFilters'

// Types
import { User } from '@/types'

export default function UsersComplete() {
  const [showCreateDialog, setShowCreateDialog] = useState(false)
  const [editingUser, setEditingUser] = useState<User | null>(null)
  const [viewingUser, setViewingUser] = useState<User | null>(null)

  // Filters
  const {
    filters,
    updateSearch,
    updateRole,
    updateStatus,
    clearFilters,
    hasActiveFilters
  } = useUserFilters()

  // Data fetching
  const { data: usersResponse, isLoading } = useUsers(filters)
  const users = usersResponse?.data || []

  // Mutations
  const createUserMutation = useCreateUser()
  const updateUserMutation = useUpdateUser()
  const deleteUserMutation = useDeleteUser()

  const handleCreateUser = async (userData: any) => {
    try {
      await createUserMutation.mutateAsync(userData)
      setShowCreateDialog(false)
      toast.success('User created successfully')
    } catch (error) {
      toast.error('Failed to create user')
    }
  }

  const handleUpdateUser = async (userId: string, userData: any) => {
    try {
      await updateUserMutation.mutateAsync({ id: userId, data: userData })
      setEditingUser(null)
      toast.success('User updated successfully')
    } catch (error) {
      toast.error('Failed to update user')
    }
  }

  const handleDeleteUser = async (user: User) => {
    if (!confirm(`Are you sure you want to delete ${user.name}?`)) {
      return
    }

    try {
      await deleteUserMutation.mutateAsync(user.id)
      toast.success('User deleted successfully')
    } catch (error) {
      toast.error('Failed to delete user')
    }
  }

  const handleFiltersChange = (newFilters: Partial<typeof filters>) => {
    Object.entries(newFilters).forEach(([key, value]) => {
      switch (key) {
        case 'search':
          updateSearch(value as string)
          break
        case 'role':
          updateRole(value as string)
          break
        case 'status':
          updateStatus(value as string)
          break
      }
    })
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex items-center justify-between">
        <div>
          <h1 className="text-3xl font-bold">Users</h1>
          <p className="text-muted-foreground">
            Manage user accounts and permissions
          </p>
        </div>
        <Dialog open={showCreateDialog} onOpenChange={setShowCreateDialog}>
          <DialogTrigger asChild>
            <Button>
              <Plus className="h-4 w-4 mr-2" />
              Add User
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Create New User</DialogTitle>
            </DialogHeader>
            {/* UserForm component would go here */}
            <div className="text-sm text-muted-foreground">
              User creation form will be implemented here
            </div>
          </DialogContent>
        </Dialog>
      </div>

      {/* Filters */}
      <UserFiltersComponent
        filters={filters}
        onFiltersChange={handleFiltersChange}
        userCount={users.length}
        hasActiveFilters={hasActiveFilters}
        onClearFilters={clearFilters}
      />

      {/* Users Table */}
      <Card>
        <CardHeader>
          <CardTitle>Users ({users.length})</CardTitle>
        </CardHeader>
        <CardContent>
          <UserTable
            users={users}
            loading={isLoading}
            onEdit={setEditingUser}
            onDelete={handleDeleteUser}
            onViewDetails={setViewingUser}
          />
        </CardContent>
      </Card>

      {/* Edit User Dialog */}
      <Dialog open={!!editingUser} onOpenChange={() => setEditingUser(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit User</DialogTitle>
          </DialogHeader>
          {editingUser && (
            <div className="text-sm text-muted-foreground">
              User edit form for {editingUser.name} will be implemented here
            </div>
          )}
        </DialogContent>
      </Dialog>

      {/* User Details Sheet */}
      <Sheet open={!!viewingUser} onOpenChange={() => setViewingUser(null)}>
        <SheetContent>
          <SheetHeader>
            <SheetTitle>User Details</SheetTitle>
          </SheetHeader>
          {viewingUser && (
            <div className="space-y-4">
              <div>
                <label className="text-sm font-medium">Name</label>
                <p className="text-sm text-muted-foreground">{viewingUser.name}</p>
              </div>
              <div>
                <label className="text-sm font-medium">Email</label>
                <p className="text-sm text-muted-foreground">{viewingUser.email}</p>
              </div>
              <div>
                <label className="text-sm font-medium">Role</label>
                <p className="text-sm text-muted-foreground">{viewingUser.role}</p>
              </div>
              <div>
                <label className="text-sm font-medium">Status</label>
                <p className="text-sm text-muted-foreground">{viewingUser.status}</p>
              </div>
            </div>
          )}
        </SheetContent>
      </Sheet>
    </div>
  )
}