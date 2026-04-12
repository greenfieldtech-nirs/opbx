import React from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { Clock, Plus, Search, Filter, RefreshCw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent } from '@/components/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { useAuth } from '@/context/AuthContext';
import {
  StandardDataTable,
  EmptyState
} from '@/components/design-system';
import type { BusinessHoursSchedule, ScheduleStatus } from '@/types';
import { cn } from '@/lib/utils';
import {
  useBusinessHours,
  CreateEditScheduleDialog,
  ExceptionDialog,
  CopyHoursDialog,
  ScheduleDetailSheet,
} from './BusinessHours';

const BusinessHours: React.FC = () => {
  const { user } = useAuth();
  const queryClient = useQueryClient();
  const canManage = user?.role === 'owner' || user?.role === 'pbx_admin';

  const {
    schedules,
    allSchedules,
    filteredSchedules,
    extensions,
    ringGroups,
    ivrMenus,
    conferenceRooms,
    isLoading,
    error,
    refetch,
    isRefetching,
    currentPage,
    setCurrentPage,
    totalPages,
    perPage,
    searchQuery,
    setSearchQuery,
    statusFilter,
    setStatusFilter,
    sortBy,
    setSortBy,
    isCreateEditDialogOpen,
    setIsCreateEditDialogOpen,
    isDeleteDialogOpen,
    setIsDeleteDialogOpen,
    isDetailSheetOpen,
    setIsDetailSheetOpen,
    isExceptionDialogOpen,
    setIsExceptionDialogOpen,
    isCopyHoursDialogOpen,
    setIsCopyHoursDialogOpen,
    selectedSchedule,
    editingSchedule,
    editingException,
    formData,
    formErrors,
    openHoursAction,
    closedHoursAction,
    exceptionFormData,
    setExceptionFormData,
    copyFromDay,
    copyToDays,
    toggleStatusMutation,
    handleCreateNew,
    handleEdit,
    handleFormChange,
    handleDayScheduleChange,
    handleTimeRangeChange,
    handleAddTimeRange,
    handleRemoveTimeRange,
    handleOpenCopyHours,
    handleApplyCopyHours,
    toggleCopyDay,
    handleAddException,
    handleEditException,
    handleSaveException,
    handleDeleteException,
    handleSaveSchedule,
    handleOpenDelete,
    handleConfirmDelete,
    handleOpenDetail,
    handleToggleStatus,
    setOpenHoursAction,
    setClosedHoursAction,
  } = useBusinessHours();

  if (error) {
    return (
      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Clock className="h-8 w-8" />
            Business Hours
          </h1>
        </div>
        <Card>
          <CardContent className="p-6">
            <div className="text-center py-12">
              <Clock className="h-12 w-12 mx-auto text-destructive mb-4" />
              <h3 className="text-lg font-semibold mb-2">Failed to load business hours</h3>
              <p className="text-muted-foreground mb-4">
                {error instanceof Error ? error.message : 'An error occurred while loading business hours'}
              </p>
              <Button onClick={() => queryClient.invalidateQueries({ queryKey: ['business-hours'] })} >
                Try Again
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    );
  }

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Clock className="h-8 w-8" />
            Business Hours
          </h1>
          <p className="text-muted-foreground mt-1">
            Manage business hours schedules for time-based call routing
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Business Hours</span>
          </div>
        </div>
        {canManage && (
          <Button onClick={handleCreateNew}>
            <Plus className="mr-2 h-4 w-4" />
            New Schedule
          </Button>
        )}
      </div>

      {/* Filters Section */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3">
            <div className="relative flex-1 min-w-[250px]">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search schedules..."
                value={searchQuery}
                onChange={(e) => setSearchQuery(e.target.value)}
                className="pl-9"
                autoComplete="off"
              />
            </div>

            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              disabled={isRefetching}
              title="Refresh"
            >
              <RefreshCw className={cn('h-4 w-4', isRefetching && 'animate-spin')} />
            </Button>

            <Select value={statusFilter} onValueChange={(value: string) => setStatusFilter(value)}>
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>

            <Select value={sortBy} onValueChange={(value: string) => setSortBy(value)}>
              <SelectTrigger className="w-[180px]">
                <SelectValue placeholder="Sort by" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="name">Name</SelectItem>
                <SelectItem value="created_at">Created Date</SelectItem>
                <SelectItem value="status">Status</SelectItem>
                <SelectItem value="updated_at">Last Modified</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Table */}
      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<BusinessHoursSchedule>
            data={schedules}
            isLoading={isLoading}
            onRowClick={handleOpenEdit}
            identityIcon={Clock}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(schedule) => schedule.name}
            getIdentitySecondary={() => 'Business Hours'}
            onIdentityClick={handleOpenEdit}
            sortField={sortBy}
            sortDirection="asc"
            onSort={(field) => setSortBy(field as any)}
            canView={false}
            canEdit={false}
            onDelete={handleOpenDelete}
            columns={[
              {
                header: 'Timezone',
                cell: (schedule) => (
                  <span className="text-sm text-muted-foreground">{schedule.timezone}</span>
                )
              },
              {
                header: 'Exceptions',
                cell: (schedule) => (
                  <Badge variant="outline" className="text-xs">
                    {(schedule.exceptions || []).length} exceptions
                  </Badge>
                )
              },
              {
                header: 'Last Modified',
                sortKey: 'updated_at',
                cell: (schedule) => (
                  <span className="text-sm text-muted-foreground">
                    {new Date(schedule.updated_at || '').toLocaleDateString()}
                  </span>
                )
              },
              {
                header: 'Status',
                sortKey: 'status',
                cell: (schedule) => (
                  <Badge
                    variant={schedule.status === 'active' ? 'default' : 'secondary'}
                    className={cn(
                      "text-xs transition-all",
                      toggleStatusMutation.isPending && toggleStatusMutation.variables?.id === String(schedule.id)
                        ? 'opacity-50 cursor-wait'
                        : 'cursor-pointer hover:scale-105',
                      schedule.status === 'active'
                        ? "bg-green-100 text-green-800 hover:bg-green-200"
                        : "bg-gray-100 text-gray-800 hover:bg-gray-200"
                    )}
                    onClick={(e) => {
                      e.stopPropagation();
                      if (!toggleStatusMutation.isPending) {
                        handleToggleStatus(schedule);
                      }
                    }}
                  >
                    {toggleStatusMutation.isPending && toggleStatusMutation.variables?.id === String(schedule.id) ? (
                      <span className="flex items-center gap-1">
                        <RefreshCw className="h-3 w-3 animate-spin" />
                        {schedule.status === 'active' ? 'Active' : 'Disabled'}
                      </span>
                    ) : (
                      schedule.status === 'active' ? 'Active' : 'Disabled'
                    )}
                  </Badge>
                )
              }
            ]}
            emptyState={
              <EmptyState
                icon={Clock}
                title="No business hours found"
                description={searchQuery || statusFilter !== 'all' ? 'Try adjusting your filters' : 'Get started by creating your first business hours schedule'}
                action={canManage && !searchQuery && statusFilter === 'all' ? {
                  label: "Create Schedule",
                  onClick: () => setIsCreateEditDialogOpen(true)
                } : undefined}
              />
            }
          />

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, allSchedules?.length || 0)} of {allSchedules?.length || 0} schedules
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage(p => Math.max(1, p - 1))}
                  disabled={currentPage === 1}
                >
                  Previous
                </Button>
                <div className="text-sm">
                  Page {currentPage} of {totalPages}
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage(p => Math.min(totalPages, p + 1))}
                  disabled={currentPage === totalPages}
                >
                  Next
                </Button>
              </div>
            </div>
          )}
        </CardContent>
      </Card>

      {filteredSchedules.length > 0 && (
        <div className="text-sm text-muted-foreground">
          Showing {filteredSchedules.length} schedule{filteredSchedules.length !== 1 ? 's' : ''}
        </div>
      )}

      {/* Create/Edit Dialog */}
      <CreateEditScheduleDialog
        open={isCreateEditDialogOpen}
        onOpenChange={setIsCreateEditDialogOpen}
        editing={!!editingSchedule}
        formData={formData}
        formErrors={formErrors}
        onFormChange={handleFormChange}
        onDayScheduleChange={handleDayScheduleChange}
        onTimeRangeChange={handleTimeRangeChange}
        onAddTimeRange={handleAddTimeRange}
        onRemoveTimeRange={handleRemoveTimeRange}
        onOpenCopyHours={handleOpenCopyHours}
        onAddException={handleAddException}
        onEditException={handleEditException}
        onDeleteException={handleDeleteException}
        onSave={handleSaveSchedule}
        openHoursAction={openHoursAction}
        closedHoursAction={closedHoursAction}
        onOpenHoursActionChange={setOpenHoursAction}
        onClosedHoursActionChange={setClosedHoursAction}
        extensions={extensions}
        ringGroups={ringGroups}
        ivrMenus={ivrMenus}
        conferenceRooms={conferenceRooms}
      />

      {/* Exception Dialog */}
      <ExceptionDialog
        open={isExceptionDialogOpen}
        onOpenChange={setIsExceptionDialogOpen}
        editing={!!editingException}
        formData={exceptionFormData}
        onFormChange={(field, value) => setExceptionFormData((prev) => ({ ...prev, [field]: value }))}
        onSave={handleSaveException}
      />

      {/* Copy Hours Dialog */}
      {formData.schedule && (
        <CopyHoursDialog
          open={isCopyHoursDialogOpen}
          onOpenChange={setIsCopyHoursDialogOpen}
          fromDay={copyFromDay}
          toDays={copyToDays}
          schedule={formData.schedule}
          onToggleDay={toggleCopyDay}
          onApply={handleApplyCopyHours}
        />
      )}

      {/* Delete Confirmation Dialog */}
      <AlertDialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <AlertDialogContent>
          <AlertDialogHeader>
            <AlertDialogTitle>Delete Schedule</AlertDialogTitle>
            <AlertDialogDescription>
              Are you sure you want to delete "{selectedSchedule?.name}"? This action cannot be undone.
            </AlertDialogDescription>
          </AlertDialogHeader>
          <AlertDialogFooter>
            <AlertDialogCancel>Cancel</AlertDialogCancel>
            <AlertDialogAction onClick={handleConfirmDelete}>Delete</AlertDialogAction>
          </AlertDialogFooter>
        </AlertDialogContent>
      </AlertDialog>

      {/* Detail Sheet */}
      <ScheduleDetailSheet
        open={isDetailSheetOpen}
        onOpenChange={setIsDetailSheetOpen}
        schedule={selectedSchedule}
        extensions={extensions}
        ringGroups={ringGroups}
        ivrMenus={ivrMenus}
      />
    </div>
  );
};

export default BusinessHours;
