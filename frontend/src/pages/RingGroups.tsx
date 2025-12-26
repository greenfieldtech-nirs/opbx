/**
 * Ring Groups Management Page
 * Full CRUD operations with mock data
 */

import { useState } from 'react';
import {
  mockRingGroups,
  mockAvailableExtensions,
  mockCurrentUserForRingGroups,
  type RingGroup,
  type RingGroupStrategy,
  type RingGroupStatus,
  type FallbackAction,
  type RingGroupMember,
  getNextRingGroupId,
  getStrategyDisplayName,
  getStrategyDescription,
  getFallbackDisplayText,
} from '@/mock/ringGroups';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
  Plus,
  Search,
  Filter,
  Users,
  RotateCw,
  List,
  Mail,
  PhoneForwarded,
  PhoneOff,
  Repeat,
  Edit,
  Trash2,
  Eye,
  ChevronUp,
  ChevronDown,
  X,
  Info,
  ArrowUpDown,
} from 'lucide-react';

export default function RingGroups() {
  // Current user for permissions
  const currentUser = mockCurrentUserForRingGroups;
  const canManage = ['owner', 'pbx_admin'].includes(currentUser.role);

  // State
  const [ringGroups, setRingGroups] = useState<RingGroup[]>(mockRingGroups);
  const [searchQuery, setSearchQuery] = useState('');
  const [strategyFilter, setStrategyFilter] = useState<RingGroupStrategy | 'all'>('all');
  const [statusFilter, setStatusFilter] = useState<RingGroupStatus | 'all'>('all');
  const [sortField, setSortField] = useState<'name' | 'strategy' | 'members' | 'status'>('name');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');

  // Dialog states
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [isDetailSheetOpen, setIsDetailSheetOpen] = useState(false);
  const [selectedGroup, setSelectedGroup] = useState<RingGroup | null>(null);

  // Form data
  const [formData, setFormData] = useState<Partial<RingGroup>>({
    name: '',
    description: '',
    strategy: 'simultaneous',
    timeout: 30,
    fallback_action: 'voicemail',
    status: 'active',
    members: [],
  });

  const [formErrors, setFormErrors] = useState<Record<string, string>>({});

  // Strategy icon mapping
  const getStrategyIcon = (strategy: RingGroupStrategy) => {
    switch (strategy) {
      case 'simultaneous':
        return <Users className="h-4 w-4" />;
      case 'round_robin':
        return <RotateCw className="h-4 w-4" />;
      case 'sequential':
        return <List className="h-4 w-4" />;
    }
  };

  // Fallback icon mapping
  const getFallbackIcon = (action: FallbackAction) => {
    switch (action) {
      case 'voicemail':
        return <Mail className="h-4 w-4" />;
      case 'extension':
        return <PhoneForwarded className="h-4 w-4" />;
      case 'hangup':
        return <PhoneOff className="h-4 w-4" />;
      case 'repeat':
        return <Repeat className="h-4 w-4" />;
    }
  };

  // Filter and sort ring groups
  const filteredAndSortedGroups = ringGroups
    .filter((group) => {
      const matchesSearch =
        group.name.toLowerCase().includes(searchQuery.toLowerCase()) ||
        group.description?.toLowerCase().includes(searchQuery.toLowerCase());
      const matchesStrategy = strategyFilter === 'all' || group.strategy === strategyFilter;
      const matchesStatus = statusFilter === 'all' || group.status === statusFilter;
      return matchesSearch && matchesStrategy && matchesStatus;
    })
    .sort((a, b) => {
      let aVal: string | number;
      let bVal: string | number;

      switch (sortField) {
        case 'name':
          aVal = a.name.toLowerCase();
          bVal = b.name.toLowerCase();
          break;
        case 'strategy':
          aVal = a.strategy;
          bVal = b.strategy;
          break;
        case 'members':
          aVal = a.members.length;
          bVal = b.members.length;
          break;
        case 'status':
          aVal = a.status;
          bVal = b.status;
          break;
        default:
          return 0;
      }

      if (aVal < bVal) return sortDirection === 'asc' ? -1 : 1;
      if (aVal > bVal) return sortDirection === 'asc' ? 1 : -1;
      return 0;
    });

  // Toggle sort
  const toggleSort = (field: typeof sortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  // Validation
  const validateForm = (): boolean => {
    const errors: Record<string, string> = {};

    if (!formData.name || formData.name.trim().length < 2) {
      errors.name = 'Name must be at least 2 characters';
    }

    if (formData.name && formData.name.length > 100) {
      errors.name = 'Name must be less than 100 characters';
    }

    if (!formData.members || formData.members.length === 0) {
      errors.members = 'At least one member is required';
    }

    if (formData.members && formData.members.length > 50) {
      errors.members = 'Maximum 50 members allowed';
    }

    if (!formData.timeout || formData.timeout < 5 || formData.timeout > 300) {
      errors.timeout = 'Timeout must be between 5 and 300 seconds';
    }

    if (formData.fallback_action === 'extension' && !formData.fallback_extension_id) {
      errors.fallback_extension = 'Fallback extension is required';
    }

    setFormErrors(errors);
    return Object.keys(errors).length === 0;
  };

  // Reset form
  const resetForm = () => {
    setFormData({
      name: '',
      description: '',
      strategy: 'simultaneous',
      timeout: 30,
      fallback_action: 'voicemail',
      status: 'active',
      members: [],
    });
    setFormErrors({});
  };

  // Handle create
  const handleCreate = () => {
    if (!validateForm()) return;

    const newGroup: RingGroup = {
      id: getNextRingGroupId(ringGroups),
      organization_id: currentUser.organization_id,
      name: formData.name!,
      description: formData.description,
      strategy: formData.strategy as RingGroupStrategy,
      timeout: formData.timeout!,
      fallback_action: formData.fallback_action as FallbackAction,
      fallback_extension_id: formData.fallback_extension_id,
      fallback_extension_number: formData.fallback_extension_number,
      status: formData.status as RingGroupStatus,
      members: formData.members as RingGroupMember[],
      created_at: new Date().toISOString(),
      updated_at: new Date().toISOString(),
    };

    setRingGroups([...ringGroups, newGroup]);
    setIsCreateDialogOpen(false);
    resetForm();
  };

  // Handle edit
  const handleEdit = () => {
    if (!validateForm() || !selectedGroup) return;

    const updatedGroups = ringGroups.map((group) =>
      group.id === selectedGroup.id
        ? {
            ...group,
            name: formData.name!,
            description: formData.description,
            strategy: formData.strategy as RingGroupStrategy,
            timeout: formData.timeout!,
            fallback_action: formData.fallback_action as FallbackAction,
            fallback_extension_id: formData.fallback_extension_id,
            fallback_extension_number: formData.fallback_extension_number,
            status: formData.status as RingGroupStatus,
            members: formData.members as RingGroupMember[],
            updated_at: new Date().toISOString(),
          }
        : group
    );

    setRingGroups(updatedGroups);
    setIsEditDialogOpen(false);
    setSelectedGroup(null);
    resetForm();
  };

  // Handle delete
  const handleDelete = () => {
    if (!selectedGroup) return;

    setRingGroups(ringGroups.filter((group) => group.id !== selectedGroup.id));
    setIsDeleteDialogOpen(false);
    setSelectedGroup(null);
  };

  // Open create dialog
  const openCreateDialog = () => {
    resetForm();
    setIsCreateDialogOpen(true);
  };

  // Open edit dialog
  const openEditDialog = (group: RingGroup) => {
    setSelectedGroup(group);
    setFormData({
      name: group.name,
      description: group.description,
      strategy: group.strategy,
      timeout: group.timeout,
      fallback_action: group.fallback_action,
      fallback_extension_id: group.fallback_extension_id,
      fallback_extension_number: group.fallback_extension_number,
      status: group.status,
      members: [...group.members],
    });
    setIsEditDialogOpen(true);
  };

  // Open delete dialog
  const openDeleteDialog = (group: RingGroup) => {
    setSelectedGroup(group);
    setIsDeleteDialogOpen(true);
  };

  // Open detail sheet
  const openDetailSheet = (group: RingGroup) => {
    setSelectedGroup(group);
    setIsDetailSheetOpen(true);
  };

  // Member management functions
  const addMember = () => {
    const currentMembers = formData.members || [];
    const usedExtensionIds = currentMembers.map((m) => m.extension_id);
    const availableExtensions = mockAvailableExtensions.filter(
      (ext) => !usedExtensionIds.includes(ext.id)
    );

    if (availableExtensions.length === 0) return;

    const firstAvailable = availableExtensions[0];
    const newMember: RingGroupMember = {
      extension_id: firstAvailable.id,
      extension_number: firstAvailable.extension_number,
      user_name: firstAvailable.user_name,
      priority: currentMembers.length + 1,
    };

    setFormData({
      ...formData,
      members: [...currentMembers, newMember],
    });
  };

  const removeMember = (index: number) => {
    const currentMembers = formData.members || [];
    const newMembers = currentMembers.filter((_, i) => i !== index);

    // Recalculate priorities
    const reorderedMembers = newMembers.map((member, i) => ({
      ...member,
      priority: i + 1,
    }));

    setFormData({
      ...formData,
      members: reorderedMembers,
    });
  };

  const updateMemberExtension = (index: number, extensionId: string) => {
    const currentMembers = formData.members || [];
    const extension = mockAvailableExtensions.find((ext) => ext.id === extensionId);
    if (!extension) return;

    const newMembers = [...currentMembers];
    newMembers[index] = {
      ...newMembers[index],
      extension_id: extension.id,
      extension_number: extension.extension_number,
      user_name: extension.user_name,
    };

    setFormData({
      ...formData,
      members: newMembers,
    });
  };

  const updateMemberPriority = (index: number, priority: number) => {
    const currentMembers = formData.members || [];
    const newMembers = [...currentMembers];
    newMembers[index] = {
      ...newMembers[index],
      priority: Math.max(1, Math.min(100, priority)),
    };

    setFormData({
      ...formData,
      members: newMembers,
    });
  };

  const moveMemberUp = (index: number) => {
    if (index === 0) return;
    const currentMembers = formData.members || [];
    const newMembers = [...currentMembers];
    [newMembers[index - 1], newMembers[index]] = [newMembers[index], newMembers[index - 1]];

    // Recalculate priorities
    const reorderedMembers = newMembers.map((member, i) => ({
      ...member,
      priority: i + 1,
    }));

    setFormData({
      ...formData,
      members: reorderedMembers,
    });
  };

  const moveMemberDown = (index: number) => {
    const currentMembers = formData.members || [];
    if (index === currentMembers.length - 1) return;
    const newMembers = [...currentMembers];
    [newMembers[index], newMembers[index + 1]] = [newMembers[index + 1], newMembers[index]];

    // Recalculate priorities
    const reorderedMembers = newMembers.map((member, i) => ({
      ...member,
      priority: i + 1,
    }));

    setFormData({
      ...formData,
      members: reorderedMembers,
    });
  };

  const getAvailableExtensionsForMember = (currentMemberExtensionId?: string) => {
    const currentMembers = formData.members || [];
    const usedExtensionIds = currentMembers
      .map((m) => m.extension_id)
      .filter((id) => id !== currentMemberExtensionId);
    return mockAvailableExtensions.filter((ext) => !usedExtensionIds.includes(ext.id));
  };

  // Render form dialog content
  const renderFormDialog = (isEdit: boolean) => {
    const title = isEdit ? 'Edit Ring Group' : 'Create Ring Group';
    const description = isEdit
      ? 'Update ring group settings and members'
      : 'Configure a new ring group with extension members';

    return (
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{title}</DialogTitle>
          <DialogDescription>{description}</DialogDescription>
        </DialogHeader>

        <Alert>
          <Info className="h-4 w-4" />
          <AlertDescription>
            Only PBX User extensions (type: user, status: active) can be added to ring groups.
          </AlertDescription>
        </Alert>

        <div className="space-y-4 py-4">
          {/* Name */}
          <div className="space-y-2">
            <Label htmlFor="name">
              Name <span className="text-red-500">*</span>
            </Label>
            <Input
              id="name"
              value={formData.name || ''}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              placeholder="e.g., Sales Team"
              className={formErrors.name ? 'border-red-500' : ''}
            />
            {formErrors.name && <p className="text-sm text-red-500">{formErrors.name}</p>}
          </div>

          {/* Description */}
          <div className="space-y-2">
            <Label htmlFor="description">Description</Label>
            <Textarea
              id="description"
              value={formData.description || ''}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              placeholder="Optional description"
              rows={2}
            />
          </div>

          {/* Strategy */}
          <div className="space-y-2">
            <Label htmlFor="strategy">
              Ring Strategy <span className="text-red-500">*</span>
            </Label>
            <Select
              value={formData.strategy}
              onValueChange={(value) =>
                setFormData({ ...formData, strategy: value as RingGroupStrategy })
              }
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="simultaneous">
                  <div className="flex items-center gap-2">
                    <Users className="h-4 w-4" />
                    <div>
                      <div className="font-medium">Simultaneous (Ring All)</div>
                      <div className="text-xs text-muted-foreground">
                        All members ring at the same time
                      </div>
                    </div>
                  </div>
                </SelectItem>
                <SelectItem value="round_robin">
                  <div className="flex items-center gap-2">
                    <RotateCw className="h-4 w-4" />
                    <div>
                      <div className="font-medium">Round Robin</div>
                      <div className="text-xs text-muted-foreground">
                        Calls distributed evenly in rotation
                      </div>
                    </div>
                  </div>
                </SelectItem>
                <SelectItem value="sequential">
                  <div className="flex items-center gap-2">
                    <List className="h-4 w-4" />
                    <div>
                      <div className="font-medium">Sequential</div>
                      <div className="text-xs text-muted-foreground">
                        Ring one at a time by priority
                      </div>
                    </div>
                  </div>
                </SelectItem>
              </SelectContent>
            </Select>
            <p className="text-sm text-muted-foreground">
              {getStrategyDescription(formData.strategy as RingGroupStrategy)}
            </p>
          </div>

          {/* Timeout */}
          <div className="space-y-2">
            <Label htmlFor="timeout">
              Ring Timeout (seconds) <span className="text-red-500">*</span>
            </Label>
            <Input
              id="timeout"
              type="number"
              min="5"
              max="300"
              value={formData.timeout || 30}
              onChange={(e) => setFormData({ ...formData, timeout: parseInt(e.target.value) })}
              className={formErrors.timeout ? 'border-red-500' : ''}
            />
            {formErrors.timeout && <p className="text-sm text-red-500">{formErrors.timeout}</p>}
            <p className="text-sm text-muted-foreground">How long to ring before moving to fallback (5-300 seconds)</p>
          </div>

          {/* Fallback Action */}
          <div className="space-y-2">
            <Label htmlFor="fallback_action">
              Fallback Action <span className="text-red-500">*</span>
            </Label>
            <Select
              value={formData.fallback_action}
              onValueChange={(value) => {
                setFormData({
                  ...formData,
                  fallback_action: value as FallbackAction,
                  fallback_extension_id: value === 'extension' ? formData.fallback_extension_id : undefined,
                  fallback_extension_number: value === 'extension' ? formData.fallback_extension_number : undefined,
                });
              }}
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="voicemail">
                  <div className="flex items-center gap-2">
                    <Mail className="h-4 w-4" />
                    <span>Voicemail</span>
                  </div>
                </SelectItem>
                <SelectItem value="extension">
                  <div className="flex items-center gap-2">
                    <PhoneForwarded className="h-4 w-4" />
                    <span>Forward to Extension</span>
                  </div>
                </SelectItem>
                <SelectItem value="hangup">
                  <div className="flex items-center gap-2">
                    <PhoneOff className="h-4 w-4" />
                    <span>Hangup</span>
                  </div>
                </SelectItem>
                <SelectItem value="repeat">
                  <div className="flex items-center gap-2">
                    <Repeat className="h-4 w-4" />
                    <span>Repeat (try again)</span>
                  </div>
                </SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Fallback Extension (conditional) */}
          {formData.fallback_action === 'extension' && (
            <div className="space-y-2">
              <Label htmlFor="fallback_extension">
                Fallback Extension <span className="text-red-500">*</span>
              </Label>
              <Select
                value={formData.fallback_extension_id || ''}
                onValueChange={(value) => {
                  const ext = mockAvailableExtensions.find((e) => e.id === value);
                  setFormData({
                    ...formData,
                    fallback_extension_id: value,
                    fallback_extension_number: ext?.extension_number,
                  });
                }}
              >
                <SelectTrigger className={formErrors.fallback_extension ? 'border-red-500' : ''}>
                  <SelectValue placeholder="Select extension" />
                </SelectTrigger>
                <SelectContent>
                  {mockAvailableExtensions.map((ext) => (
                    <SelectItem key={ext.id} value={ext.id}>
                      {ext.extension_number} - {ext.user_name || 'Unassigned'}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {formErrors.fallback_extension && (
                <p className="text-sm text-red-500">{formErrors.fallback_extension}</p>
              )}
            </div>
          )}

          {/* Status */}
          <div className="space-y-2">
            <Label htmlFor="status">Status</Label>
            <Select
              value={formData.status}
              onValueChange={(value) =>
                setFormData({ ...formData, status: value as RingGroupStatus })
              }
            >
              <SelectTrigger>
                <SelectValue />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>
          </div>

          {/* Members */}
          <div className="space-y-2">
            <div className="flex items-center justify-between">
              <Label>
                Members <span className="text-red-500">*</span>
              </Label>
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={addMember}
                disabled={
                  (formData.members || []).length >= 50 ||
                  getAvailableExtensionsForMember().length === 0
                }
              >
                <Plus className="h-4 w-4 mr-1" />
                Add Member
              </Button>
            </div>

            {formErrors.members && <p className="text-sm text-red-500">{formErrors.members}</p>}

            {(!formData.members || formData.members.length === 0) && (
              <div className="border rounded-lg p-8 text-center text-muted-foreground">
                <Users className="h-8 w-8 mx-auto mb-2 opacity-50" />
                <p className="text-sm">No members added yet</p>
                <p className="text-xs">Click "Add Member" to add extensions</p>
              </div>
            )}

            {formData.members && formData.members.length > 0 && (
              <div className="border rounded-lg divide-y">
                {formData.members.map((member, index) => (
                  <div key={index} className="p-3 flex items-center gap-3">
                    <div className="flex flex-col gap-1">
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-6 w-6 p-0"
                        onClick={() => moveMemberUp(index)}
                        disabled={index === 0}
                      >
                        <ChevronUp className="h-4 w-4" />
                      </Button>
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-6 w-6 p-0"
                        onClick={() => moveMemberDown(index)}
                        disabled={index === (formData.members?.length || 0) - 1}
                      >
                        <ChevronDown className="h-4 w-4" />
                      </Button>
                    </div>

                    <div className="flex-1">
                      <Select
                        value={member.extension_id}
                        onValueChange={(value) => updateMemberExtension(index, value)}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {getAvailableExtensionsForMember(member.extension_id).map((ext) => (
                            <SelectItem key={ext.id} value={ext.id}>
                              {ext.extension_number} - {ext.user_name || 'Unassigned'}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </div>

                    {formData.strategy === 'sequential' && (
                      <div className="w-24">
                        <Input
                          type="number"
                          min="1"
                          max="100"
                          value={member.priority}
                          onChange={(e) =>
                            updateMemberPriority(index, parseInt(e.target.value))
                          }
                          placeholder="Priority"
                        />
                      </div>
                    )}

                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => removeMember(index)}
                    >
                      <X className="h-4 w-4" />
                    </Button>
                  </div>
                ))}
              </div>
            )}

            {formData.strategy === 'sequential' && (
              <p className="text-xs text-muted-foreground">
                Priority order: Lower numbers ring first (1 = highest priority)
              </p>
            )}
          </div>
        </div>

        <DialogFooter>
          <Button
            variant="outline"
            onClick={() => {
              if (isEdit) {
                setIsEditDialogOpen(false);
                setSelectedGroup(null);
              } else {
                setIsCreateDialogOpen(false);
              }
              resetForm();
            }}
          >
            Cancel
          </Button>
          <Button onClick={isEdit ? handleEdit : handleCreate}>
            {isEdit ? 'Save Changes' : 'Create Ring Group'}
          </Button>
        </DialogFooter>
      </DialogContent>
    );
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-center">
        <div>
          <h1 className="text-2xl font-bold">Ring Groups</h1>
          <p className="text-muted-foreground">Manage extension ring groups and routing strategies</p>
        </div>
        {canManage && (
          <Button onClick={openCreateDialog}>
            <Plus className="h-4 w-4 mr-2" />
            Create Ring Group
          </Button>
        )}
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="pt-6">
          <div className="flex flex-col md:flex-row gap-4">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search ring groups..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="pl-10"
                />
              </div>
            </div>
            <Select value={strategyFilter} onValueChange={(value: any) => setStrategyFilter(value)}>
              <SelectTrigger className="w-full md:w-48">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Strategy" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Strategies</SelectItem>
                <SelectItem value="simultaneous">Simultaneous</SelectItem>
                <SelectItem value="round_robin">Round Robin</SelectItem>
                <SelectItem value="sequential">Sequential</SelectItem>
              </SelectContent>
            </Select>
            <Select value={statusFilter} onValueChange={(value: any) => setStatusFilter(value)}>
              <SelectTrigger className="w-full md:w-48">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Status</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Inactive</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      {/* Table */}
      <Card>
        <CardContent className="pt-6">
          <Table>
            <TableHeader>
              <TableRow>
                <TableHead>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 px-2"
                    onClick={() => toggleSort('name')}
                  >
                    Name
                    <ArrowUpDown className="ml-2 h-3 w-3" />
                  </Button>
                </TableHead>
                <TableHead>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 px-2"
                    onClick={() => toggleSort('strategy')}
                  >
                    Strategy
                    <ArrowUpDown className="ml-2 h-3 w-3" />
                  </Button>
                </TableHead>
                <TableHead>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 px-2"
                    onClick={() => toggleSort('members')}
                  >
                    Members
                    <ArrowUpDown className="ml-2 h-3 w-3" />
                  </Button>
                </TableHead>
                <TableHead>Timeout</TableHead>
                <TableHead>Fallback</TableHead>
                <TableHead>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 px-2"
                    onClick={() => toggleSort('status')}
                  >
                    Status
                    <ArrowUpDown className="ml-2 h-3 w-3" />
                  </Button>
                </TableHead>
                <TableHead className="text-right">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {filteredAndSortedGroups.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={7} className="text-center text-muted-foreground py-8">
                    No ring groups found
                  </TableCell>
                </TableRow>
              ) : (
                filteredAndSortedGroups.map((group) => (
                  <TableRow key={group.id}>
                    <TableCell>
                      <div>
                        <div className="font-medium">{group.name}</div>
                        {group.description && (
                          <div className="text-sm text-muted-foreground line-clamp-1">
                            {group.description}
                          </div>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        {getStrategyIcon(group.strategy)}
                        <Badge variant="outline">{getStrategyDisplayName(group.strategy)}</Badge>
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-1">
                        <Users className="h-3 w-3 text-muted-foreground" />
                        <span className="text-sm">{group.members.length}</span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <span className="text-sm">{group.timeout}s</span>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-2">
                        {getFallbackIcon(group.fallback_action)}
                        <span className="text-sm">
                          {getFallbackDisplayText(
                            group.fallback_action,
                            group.fallback_extension_number
                          )}
                        </span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <Badge variant={group.status === 'active' ? 'default' : 'secondary'}>
                        {group.status}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => openDetailSheet(group)}
                        >
                          <Eye className="h-4 w-4" />
                        </Button>
                        {canManage && (
                          <>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => openEditDialog(group)}
                            >
                              <Edit className="h-4 w-4" />
                            </Button>
                            <Button
                              variant="ghost"
                              size="sm"
                              onClick={() => openDeleteDialog(group)}
                            >
                              <Trash2 className="h-4 w-4 text-red-500" />
                            </Button>
                          </>
                        )}
                      </div>
                    </TableCell>
                  </TableRow>
                ))
              )}
            </TableBody>
          </Table>
        </CardContent>
      </Card>

      {/* Create Dialog */}
      <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
        {renderFormDialog(false)}
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        {renderFormDialog(true)}
      </Dialog>

      {/* Delete Dialog */}
      <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Ring Group</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{selectedGroup?.name}"? This action cannot be undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsDeleteDialogOpen(false)}>
              Cancel
            </Button>
            <Button variant="destructive" onClick={handleDelete}>
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Detail Sheet */}
      <Sheet open={isDetailSheetOpen} onOpenChange={setIsDetailSheetOpen}>
        <SheetContent className="w-full sm:max-w-lg overflow-y-auto">
          {selectedGroup && (
            <>
              <SheetHeader>
                <SheetTitle>{selectedGroup.name}</SheetTitle>
                <SheetDescription>
                  {selectedGroup.description || 'No description provided'}
                </SheetDescription>
              </SheetHeader>

              <div className="space-y-6 mt-6">
                {/* Strategy */}
                <div>
                  <h3 className="text-sm font-medium mb-2">Ring Strategy</h3>
                  <div className="flex items-center gap-2">
                    {getStrategyIcon(selectedGroup.strategy)}
                    <Badge variant="outline">{getStrategyDisplayName(selectedGroup.strategy)}</Badge>
                  </div>
                  <p className="text-sm text-muted-foreground mt-1">
                    {getStrategyDescription(selectedGroup.strategy)}
                  </p>
                </div>

                {/* Timeout & Fallback */}
                <div className="grid grid-cols-2 gap-4">
                  <div>
                    <h3 className="text-sm font-medium mb-2">Timeout</h3>
                    <p className="text-sm">{selectedGroup.timeout} seconds</p>
                  </div>
                  <div>
                    <h3 className="text-sm font-medium mb-2">Status</h3>
                    <Badge variant={selectedGroup.status === 'active' ? 'default' : 'secondary'}>
                      {selectedGroup.status}
                    </Badge>
                  </div>
                </div>

                <div>
                  <h3 className="text-sm font-medium mb-2">Fallback Action</h3>
                  <div className="flex items-center gap-2">
                    {getFallbackIcon(selectedGroup.fallback_action)}
                    <span className="text-sm">
                      {getFallbackDisplayText(
                        selectedGroup.fallback_action,
                        selectedGroup.fallback_extension_number
                      )}
                    </span>
                  </div>
                </div>

                {/* Members */}
                <div>
                  <h3 className="text-sm font-medium mb-2">
                    Members ({selectedGroup.members.length})
                  </h3>
                  <div className="space-y-2">
                    {selectedGroup.members.map((member, index) => (
                      <div
                        key={index}
                        className="flex items-center justify-between p-3 border rounded-lg"
                      >
                        <div>
                          <p className="font-medium">
                            Ext {member.extension_number}
                          </p>
                          <p className="text-sm text-muted-foreground">
                            {member.user_name || 'Unassigned'}
                          </p>
                        </div>
                        {selectedGroup.strategy === 'sequential' && (
                          <Badge variant="outline">Priority {member.priority}</Badge>
                        )}
                      </div>
                    ))}
                  </div>
                </div>

                {/* Timestamps */}
                <div className="pt-4 border-t text-xs text-muted-foreground space-y-1">
                  <p>Created: {new Date(selectedGroup.created_at).toLocaleString()}</p>
                  <p>Updated: {new Date(selectedGroup.updated_at).toLocaleString()}</p>
                </div>
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </div>
  );
}
