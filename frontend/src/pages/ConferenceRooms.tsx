import { useState, useEffect } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import {
  Plus,
  Search,
  Filter,
  MoreVertical,
  Edit2,
  Trash2,
  Users,
  Lock,
  Mic,
  Video,
  ChevronDown,
  RefreshCw,
  Eye,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Switch } from '@/components/ui/switch';
import { Badge } from '@/components/ui/badge';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import {
  StandardDataTable,
  Column,
  EmptyState
} from '@/components/design-system';
import { toast } from 'sonner';
import { conferenceRoomsService } from '@/services/createResourceService';
import { useAuth } from '@/hooks/useAuth';
import type { ConferenceRoom, CreateConferenceRoomRequest, UpdateConferenceRoomRequest, Status } from '@/types';
import { cn } from '@/lib/utils';

type RoomFormData = {
  name: string;
  description: string;
  max_participants: string;
  status: 'active' | 'inactive';
  pin: string;
  pin_required: boolean;
  host_pin: string;
  recording_enabled: boolean;
  recording_auto_start: boolean;
  recording_webhook_url: string;
  wait_for_host: boolean;
  mute_on_entry: boolean;
  announce_join_leave: boolean;
  music_on_hold: boolean;
  talk_detection_enabled: boolean;
  talk_detection_webhook_url: string;
};

const emptyFormData: RoomFormData = {
  name: '',
  description: '',
  max_participants: '25',
  status: 'active',
  pin: '',
  pin_required: false,
  host_pin: '',
  recording_enabled: false,
  recording_auto_start: false,
  recording_webhook_url: '',
  wait_for_host: false,
  mute_on_entry: false,
  announce_join_leave: false,
  music_on_hold: false,
  talk_detection_enabled: false,
  talk_detection_webhook_url: '',
};

export default function ConferenceRooms() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // UI state
  const [searchQuery, setSearchQuery] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState<Status | 'all'>('all');
  const [sortField, setSortField] = useState<'name' | 'max_participants' | 'created_at'>('name');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [selectedRoom, setSelectedRoom] = useState<ConferenceRoom | null>(null);
  const [isDetailSheetOpen, setIsDetailSheetOpen] = useState(false);

  const [formData, setFormData] = useState<RoomFormData>(emptyFormData);
  const [isSecurityOpen, setIsSecurityOpen] = useState(false);
  const [isAudioOpen, setIsAudioOpen] = useState(false);

  const canManageRooms = currentUser && ['owner', 'pbx_admin'].includes(currentUser.role);
  const isReadOnly = currentUser?.role === 'reporter';

  // Debounce search input
  useEffect(() => {
    const timer = setTimeout(() => {
      setDebouncedSearch(searchQuery);
      setCurrentPage(1); // Reset to first page on search
    }, 300);

    return () => clearTimeout(timer);
  }, [searchQuery]);

  // Fetch conference rooms
  const { data, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['conference-rooms', {
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_order: sortDirection,
    }],
    queryFn: () => conferenceRoomsService.getAll({
      page: currentPage,
      per_page: perPage,
      search: debouncedSearch || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_order: sortDirection,
    }),
  });

  const rooms = data?.data || [];
  const totalRooms = data?.meta?.total || 0;
  const totalPages = data?.meta?.last_page || 1;

  // Create mutation
  const createMutation = useMutation({
    mutationFn: (data: CreateConferenceRoomRequest) => conferenceRoomsService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['conference-rooms'] });
      setIsCreateDialogOpen(false);
      setFormData(emptyFormData);
      toast.success('Conference room created successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Failed to create conference room';
      toast.error(message);
    },
  });

  // Update mutation
  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateConferenceRoomRequest }) =>
      conferenceRoomsService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['conference-rooms'] });
      setIsEditDialogOpen(false);
      setSelectedRoom(null);
      setFormData(emptyFormData);
      toast.success('Conference room updated successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Failed to update conference room';
      toast.error(message);
    },
  });

  // Delete mutation
  const deleteMutation = useMutation({
    mutationFn: (id: string) => conferenceRoomsService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['conference-rooms'] });
      setIsDeleteDialogOpen(false);
      setSelectedRoom(null);
      toast.success('Conference room deleted successfully');
    },
    onError: (error: any) => {
      const message = error.response?.data?.error?.message || error.response?.data?.message || 'Failed to delete conference room';
      toast.error(message);
    },
  });

  const handleSort = (field: typeof sortField) => {
    if (sortField === field) {
      setSortDirection(sortDirection === 'asc' ? 'desc' : 'asc');
    } else {
      setSortField(field);
      setSortDirection('asc');
    }
  };

  const handleCreateRoom = () => {
    // Validate required fields
    if (!formData.name.trim()) {
      toast.error('Conference room name is required');
      return;
    }

    if (!formData.max_participants || parseInt(formData.max_participants, 10) < 2) {
      toast.error('Maximum participants must be at least 2');
      return;
    }

    // Validate PIN if required
    if (formData.pin_required && !formData.pin.trim()) {
      toast.error('PIN is required when PIN protection is enabled');
      return;
    }

    // Validate talk detection webhook URL if enabled
    if (formData.talk_detection_enabled && !formData.talk_detection_webhook_url.trim()) {
      toast.error('Webhook URL is required when talk detection is enabled');
      return;
    }

    const requestData: CreateConferenceRoomRequest = {
      name: formData.name.trim(),
      description: formData.description.trim() || undefined,
      max_participants: parseInt(formData.max_participants, 10),
      status: formData.status,
      pin: formData.pin.trim() || undefined,
      pin_required: formData.pin_required,
      host_pin: formData.host_pin.trim() || undefined,
      recording_enabled: formData.recording_enabled,
      recording_auto_start: formData.recording_auto_start,
      recording_webhook_url: formData.recording_webhook_url.trim() || undefined,
      wait_for_host: formData.wait_for_host,
      mute_on_entry: formData.mute_on_entry,
      announce_join_leave: formData.announce_join_leave,
      music_on_hold: formData.music_on_hold,
      talk_detection_enabled: formData.talk_detection_enabled,
      talk_detection_webhook_url: formData.talk_detection_webhook_url.trim() || undefined,
    };

    createMutation.mutate(requestData);
  };

  const handleEditRoom = () => {
    if (!selectedRoom) return;

    // Validate required fields
    if (!formData.name.trim()) {
      toast.error('Conference room name is required');
      return;
    }

    if (!formData.max_participants || parseInt(formData.max_participants, 10) < 2) {
      toast.error('Maximum participants must be at least 2');
      return;
    }

    // Validate PIN if required
    if (formData.pin_required && !formData.pin.trim()) {
      toast.error('PIN is required when PIN protection is enabled');
      return;
    }

    // Validate talk detection webhook URL if enabled
    if (formData.talk_detection_enabled && !formData.talk_detection_webhook_url.trim()) {
      toast.error('Webhook URL is required when talk detection is enabled');
      return;
    }

    const requestData: UpdateConferenceRoomRequest = {
      name: formData.name.trim(),
      description: formData.description.trim() || undefined,
      max_participants: parseInt(formData.max_participants, 10),
      status: formData.status,
      pin: formData.pin.trim() || undefined,
      pin_required: formData.pin_required,
      host_pin: formData.host_pin.trim() || undefined,
      recording_enabled: formData.recording_enabled,
      recording_auto_start: formData.recording_auto_start,
      recording_webhook_url: formData.recording_webhook_url.trim() || undefined,
      wait_for_host: formData.wait_for_host,
      mute_on_entry: formData.mute_on_entry,
      announce_join_leave: formData.announce_join_leave,
      music_on_hold: formData.music_on_hold,
      talk_detection_enabled: formData.talk_detection_enabled,
      talk_detection_webhook_url: formData.talk_detection_webhook_url.trim() || undefined,
    };

    updateMutation.mutate({ id: selectedRoom.id, data: requestData });
  };

  const handleToggleStatus = (room: ConferenceRoom) => {
    const newStatus = room.status === 'active' ? 'inactive' : 'active';
    updateMutation.mutate({
      id: room.id,
      data: { status: newStatus }
    });
  };

  const handleDeleteRoom = () => {
    if (!selectedRoom) return;
    deleteMutation.mutate(selectedRoom.id);
  };

  const openCreateDialog = () => {
    setFormData(emptyFormData);
    setIsSecurityOpen(false);
    setIsAudioOpen(false);
    setIsCreateDialogOpen(true);
  };

  const openEditDialog = (room: ConferenceRoom) => {
    setSelectedRoom(room);
    setFormData({
      name: room.name,
      description: room.description || '',
      max_participants: room.max_participants.toString(),
      status: room.status,
      pin: room.pin || '',
      pin_required: room.pin_required,
      host_pin: room.host_pin || '',
      recording_enabled: room.recording_enabled,
      recording_auto_start: room.recording_auto_start,
      recording_webhook_url: room.recording_webhook_url || '',
      wait_for_host: room.wait_for_host,
      mute_on_entry: room.mute_on_entry,
      announce_join_leave: room.announce_join_leave,
      music_on_hold: room.music_on_hold,
      talk_detection_enabled: room.talk_detection_enabled,
      talk_detection_webhook_url: room.talk_detection_webhook_url || '',
    });
    setIsSecurityOpen(false);
    setIsAudioOpen(false);
    setIsEditDialogOpen(true);
  };

  const openDeleteDialog = (room: ConferenceRoom) => {
    setSelectedRoom(room);
    setIsDeleteDialogOpen(true);
  };

  const openDetailSheet = (room: ConferenceRoom) => {
    setSelectedRoom(room);
    setIsDetailSheetOpen(true);
  };

  const renderRoomForm = () => (
    <div className="space-y-4">
      <div className="grid grid-cols-4 gap-4">
        <div className="col-span-3 space-y-2">
          <Label htmlFor="name">
            Conference Room Name <span className="text-destructive">*</span>
          </Label>
          <Input
            id="name"
            placeholder="e.g., Executive Board Room"
            value={formData.name}
            onChange={(e) => setFormData({ ...formData, name: e.target.value })}
          />
        </div>
        <div className="space-y-2">
          <Label htmlFor="max_participants">
            Max Participants <span className="text-destructive">*</span>
          </Label>
          <Input
            id="max_participants"
            type="number"
            min="2"
            max="500"
            value={formData.max_participants}
            onChange={(e) => setFormData({ ...formData, max_participants: e.target.value })}
          />
        </div>
      </div>

      {/* Security Settings Collapsible */}
      <div className="border rounded-md">
        <Collapsible open={isSecurityOpen} onOpenChange={setIsSecurityOpen}>
          {/* ... inner logic ... */}
          <CollapsibleTrigger asChild>
            <Button variant="ghost" className="w-full justify-between p-3 h-auto hover:bg-muted/50">
              <span className="flex items-center gap-2 font-medium">
                <Lock className="h-4 w-4 text-blue-500" />
                Security & Access
              </span>
              <ChevronDown className={cn("h-4 w-4 transition-transform", isSecurityOpen && "rotate-180")} />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="p-3 pt-0 space-y-4 border-t">
            <div className="space-y-4 pt-4">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="pin_required">PIN Protection</Label>
                  <p className="text-sm text-muted-foreground">
                    Require participants to enter a PIN to join
                  </p>
                </div>
                <Switch
                  id="pin_required"
                  checked={formData.pin_required}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, pin_required: checked })
                  }
                />
              </div>

              {formData.pin_required && (
                <div className="space-y-2">
                  <Label htmlFor="pin">Participant PIN</Label>
                  <Input
                    id="pin"
                    type="text"
                    placeholder="e.g., 1234"
                    value={formData.pin}
                    onChange={(e) => setFormData({ ...formData, pin: e.target.value })}
                  />
                </div>
              )}

              <div className="space-y-2">
                <Label htmlFor="host_pin">Host PIN (Optional)</Label>
                <Input
                  id="host_pin"
                  type="text"
                  placeholder="e.g., 5678"
                  value={formData.host_pin}
                  onChange={(e) => setFormData({ ...formData, host_pin: e.target.value })}
                />
                <p className="text-sm text-muted-foreground">
                  Separate PIN for hosts with additional controls
                </p>
              </div>

              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="wait_for_host">Wait for Host</Label>
                  <p className="text-sm text-muted-foreground">
                    Conference starts only after host joins
                  </p>
                </div>
                <Switch
                  id="wait_for_host"
                  checked={formData.wait_for_host}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, wait_for_host: checked })
                  }
                />
              </div>
            </div>
          </CollapsibleContent>
        </Collapsible>
      </div>

      {/* Audio Settings Collapsible */}
      <div className="border rounded-md">
        <Collapsible open={isAudioOpen} onOpenChange={setIsAudioOpen}>
          <CollapsibleTrigger asChild>
            <Button variant="ghost" className="w-full justify-between p-3 h-auto hover:bg-muted/50">
              <span className="flex items-center gap-2 font-medium">
                <Mic className="h-4 w-4 text-purple-500" />
                Audio Settings
              </span>
              <ChevronDown className={cn("h-4 w-4 transition-transform", isAudioOpen && "rotate-180")} />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="p-3 pt-0 space-y-6 border-t">
            {/* Basic Audio Controls */}
            <div className="space-y-4 pt-4">
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="mute_on_entry">Mute on Entry</Label>
                  <p className="text-sm text-muted-foreground">
                    Participants join with microphone muted
                  </p>
                </div>
                <Switch
                  id="mute_on_entry"
                  checked={formData.mute_on_entry}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, mute_on_entry: checked })
                  }
                />
              </div>

              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="announce_join_leave">Announce Join/Leave</Label>
                  <p className="text-sm text-muted-foreground">
                    Play tone when participants join or leave
                  </p>
                </div>
                <Switch
                  id="announce_join_leave"
                  checked={formData.announce_join_leave}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, announce_join_leave: checked })
                  }
                />
              </div>

              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="music_on_hold">Music on Hold</Label>
                  <p className="text-sm text-muted-foreground">
                    Play music while waiting for host or other participants
                  </p>
                </div>
                <Switch
                  id="music_on_hold"
                  checked={formData.music_on_hold}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, music_on_hold: checked })
                  }
                />
              </div>
            </div>

            {/* Recording Settings (Moved inside Audio) */}
            <div className="space-y-4 border-t pt-4">
              <h4 className="font-medium flex items-center gap-2 text-sm text-muted-foreground">
                <Video className="h-3.5 w-3.5" />
                Recording
              </h4>
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="recording_enabled">Enable Recording</Label>
                  <p className="text-sm text-muted-foreground">
                    Allow this conference to be recorded
                  </p>
                </div>
                <Switch
                  id="recording_enabled"
                  checked={formData.recording_enabled}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, recording_enabled: checked })
                  }
                />
              </div>

              {formData.recording_enabled && (
                <div className="space-y-4 pl-4 border-l-2 border-muted ml-1">
                  <div className="flex items-center justify-between">
                    <div className="space-y-0.5">
                      <Label htmlFor="recording_auto_start">Auto-Start Recording</Label>
                      <p className="text-sm text-muted-foreground">
                        Begin recording automatically when conference starts
                      </p>
                    </div>
                    <Switch
                      id="recording_auto_start"
                      checked={formData.recording_auto_start}
                      onCheckedChange={(checked) =>
                        setFormData({ ...formData, recording_auto_start: checked })
                      }
                    />
                  </div>

                  <div className="space-y-2">
                    <Label htmlFor="recording_webhook_url">Recording Webhook URL (Optional)</Label>
                    <Input
                      id="recording_webhook_url"
                      type="url"
                      placeholder="https://example.com/webhooks/recording-complete"
                      value={formData.recording_webhook_url}
                      onChange={(e) =>
                        setFormData({ ...formData, recording_webhook_url: e.target.value })
                      }
                    />
                    <p className="text-sm text-muted-foreground">
                      HTTP endpoint to receive events
                    </p>
                  </div>
                </div>
              )}
            </div>

            {/* Talk Detection (Moved inside Audio) */}
            <div className="space-y-4 border-t pt-4">
              <h4 className="font-medium flex items-center gap-2 text-sm text-muted-foreground">
                <Mic className="h-3.5 w-3.5" />
                Talk Detection
              </h4>
              <div className="flex items-center justify-between">
                <div className="space-y-0.5">
                  <Label htmlFor="talk_detection_enabled">Enable Talk Detection</Label>
                  <p className="text-sm text-muted-foreground">
                    Send start/stop talking events to webhook
                  </p>
                </div>
                <Switch
                  id="talk_detection_enabled"
                  checked={formData.talk_detection_enabled}
                  onCheckedChange={(checked) =>
                    setFormData({ ...formData, talk_detection_enabled: checked })
                  }
                />
              </div>

              {formData.talk_detection_enabled && (
                <div className="space-y-2 pl-4 border-l-2 border-muted ml-1">
                  <Label htmlFor="talk_detection_webhook_url">
                    Webhook URL <span className="text-destructive">*</span>
                  </Label>
                  <Input
                    id="talk_detection_webhook_url"
                    type="url"
                    placeholder="https://example.com/webhooks/talk-events"
                    value={formData.talk_detection_webhook_url}
                    onChange={(e) =>
                      setFormData({ ...formData, talk_detection_webhook_url: e.target.value })
                    }
                  />
                </div>
              )}
            </div>
          </CollapsibleContent>
        </Collapsible>
      </div>
    </div>
  );

  return (
    <div className="space-y-6">
      <div className="flex justify-between items-start">
        <div>
          <div className="flex items-center gap-3">
            <h1 className="text-3xl font-bold flex items-center gap-2">
              <Video className="h-8 w-8" />
              Conference Rooms
            </h1>
            {isReadOnly && (
              <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
                Read-Only
              </Badge>
            )}
          </div>
          <p className="text-muted-foreground mt-1">
            Manage conference rooms for audio/video meetings
          </p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">Conference Rooms</span>
          </div>
        </div>
        {canManageRooms && (
          <Button onClick={openCreateDialog}>
            <Plus className="mr-2 h-4 w-4" />
            Add Conference Room
          </Button>
        )}
      </div>

      {/* Filters Section */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-wrap gap-3">
            {/* Search */}
            <div className="relative flex-1 min-w-[250px]">
              <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                placeholder="Search conference rooms..."
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

            {/* Filter dropdowns */}
            <Select value={statusFilter} onValueChange={(val: any) => setStatusFilter(val)}>
              <SelectTrigger className="w-[180px]">
                <Filter className="h-4 w-4 mr-2" />
                <SelectValue placeholder="Status" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="all">All Statuses</SelectItem>
                <SelectItem value="active">Active</SelectItem>
                <SelectItem value="inactive">Disabled</SelectItem>
              </SelectContent>
            </Select>
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<ConferenceRoom>
            data={rooms}
            isLoading={isLoading}
            onRowClick={openDetailSheet}
            identityIcon={Mic}
            identityIconBg="bg-purple-100"
            identityIconColor="text-purple-600"
            getIdentityPrimary={(room) => room.name}
            getIdentitySecondary={() => 'Conference Room'}
            onIdentityClick={openDetailSheet}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={handleSort}
            onView={openDetailSheet}
            onEdit={canManageRooms ? openEditDialog : undefined}
            onDelete={canManageRooms ? ((room) => {
              setSelectedRoom(room);
              setIsDeleteDialogOpen(true);
            }) : undefined}
            canEdit={canManageRooms}
            canDelete={canManageRooms}
            columns={[
              {
                header: 'Capacity',
                sortKey: 'max_participants',
                cell: (room) => (
                  <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <Users className="h-4 w-4" />
                    {room.max_participants}
                  </div>
                )
              },
              {
                header: 'Status',
                cell: (room) => (
                  <Badge
                    variant={room.status === 'active' ? 'default' : 'secondary'}
                    className={cn(
                      "text-xs",
                      !isReadOnly && "cursor-pointer transition-all hover:scale-105",
                      room.status === 'active'
                        ? 'bg-green-100 text-green-800 hover:bg-green-200'
                        : 'bg-gray-100 text-gray-800 hover:bg-gray-200'
                    )}
                    onClick={(e) => {
                      e.stopPropagation();
                      if (!isReadOnly) {
                        handleToggleStatus(room);
                      }
                    }}
                  >
                    {room.status === 'active' ? 'Active' : 'Inactive'}
                  </Badge>
                )
              },
              {
                header: 'Audio Settings',
                cell: (room) => {
                  const audioFeatures = [];
                  if (room.recording_enabled) audioFeatures.push('Recording');
                  if (room.talk_detection_enabled) audioFeatures.push('Talk Detection');
                  if (room.mute_on_entry) audioFeatures.push('Mute on Entry');
                  if (room.music_on_hold) audioFeatures.push('Music on Hold');

                  return audioFeatures.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                      {audioFeatures.slice(0, 2).map((feature) => (
                        <Badge key={feature} variant="outline" className="text-[10px] py-0">
                          {feature}
                        </Badge>
                      ))}
                      {audioFeatures.length > 2 && (
                        <Badge variant="outline" className="text-[10px] py-0">
                          +{audioFeatures.length - 2}
                        </Badge>
                      )}
                    </div>
                  ) : (
                    <span className="text-muted-foreground text-xs">Standard</span>
                  );
                }
              },
              {
                header: 'Security',
                cell: (room) => {
                  const securityFeatures = [];
                  if (room.pin_required) securityFeatures.push('PIN');
                  if (room.wait_for_host) securityFeatures.push('Wait for Host');

                  return securityFeatures.length > 0 ? (
                    <div className="flex flex-wrap gap-1">
                      {securityFeatures.map((feature) => (
                        <Badge key={feature} variant="outline" className="text-[10px] py-0 border-orange-200 text-orange-700">
                          {feature}
                        </Badge>
                      ))}
                    </div>
                  ) : (
                    <span className="text-muted-foreground text-xs">Open</span>
                  );
                }
              }
            ]}
            emptyState={
              <EmptyState
                icon={Video}
                title="No conference rooms found"
                description={searchQuery || statusFilter !== 'all' ? 'Try adjusting your filters' : 'Get started by creating your first conference room'}
                action={canManageRooms && !searchQuery && statusFilter === 'all' ? {
                  label: "Add Conference Room",
                  onClick: openCreateDialog
                } : undefined}
              />
            }
          />

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4 pt-4 border-t">
              <div className="text-sm text-muted-foreground">
                Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalRooms)} of {totalRooms} rooms
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

      {/* Create Dialog */}
      <Dialog open={isCreateDialogOpen} onOpenChange={setIsCreateDialogOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create Conference Room</DialogTitle>
            <DialogDescription>
              Add a new conference room for audio/video meetings
            </DialogDescription>
          </DialogHeader>
          {renderRoomForm()}
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setIsCreateDialogOpen(false)}
              disabled={createMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              onClick={handleCreateRoom}
              disabled={createMutation.isPending}
            >
              {createMutation.isPending ? 'Creating...' : 'Create Conference Room'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Dialog */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit Conference Room</DialogTitle>
            <DialogDescription>
              Update conference room settings
            </DialogDescription>
          </DialogHeader>
          {renderRoomForm()}
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setIsEditDialogOpen(false)}
              disabled={updateMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              onClick={handleEditRoom}
              disabled={updateMutation.isPending}
            >
              {updateMutation.isPending ? 'Saving...' : 'Save Changes'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Dialog */}
      <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete Conference Room</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{selectedRoom?.name}"? This action cannot be
              undone.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              variant="outline"
              onClick={() => setIsDeleteDialogOpen(false)}
              disabled={deleteMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              variant="destructive"
              onClick={handleDeleteRoom}
              disabled={deleteMutation.isPending}
            >
              {deleteMutation.isPending ? 'Deleting...' : 'Delete'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Detail Sheet */}
      <Sheet open={isDetailSheetOpen} onOpenChange={setIsDetailSheetOpen}>
        <SheetContent className="overflow-y-auto w-full sm:max-w-2xl">
          {selectedRoom && (
            <>
              <SheetHeader>
                <div className="flex items-center justify-between">
                  <SheetTitle>{selectedRoom.name}</SheetTitle>
                  <Badge variant={selectedRoom.status === 'active' ? 'default' : 'secondary'}>
                    {selectedRoom.status === 'active' ? 'Active' : 'Inactive'}
                  </Badge>
                </div>
                {selectedRoom.description && (
                  <SheetDescription>{selectedRoom.description}</SheetDescription>
                )}
              </SheetHeader>

              <div className="mt-6 space-y-6">
                {/* Basic Info */}
                <div className="space-y-3">
                  <h3 className="font-semibold">Basic Information</h3>
                  <div className="grid grid-cols-2 gap-4 text-sm">
                    <div>
                      <span className="text-muted-foreground">Maximum Participants</span>
                      <p className="font-medium flex items-center gap-2 mt-1">
                        <Users className="h-4 w-4" />
                        {selectedRoom.max_participants}
                      </p>
                    </div>
                    <div>
                      <span className="text-muted-foreground">Status</span>
                      <p className="font-medium mt-1">
                        {selectedRoom.status === 'active' ? 'Active' : 'Inactive'}
                      </p>
                    </div>
                  </div>
                </div>

                {/* Conference Room Capacity */}
                <div className="space-y-3">
                  <h3 className="font-semibold flex items-center gap-2">
                    <Users className="h-4 w-4" />
                    Capacity
                  </h3>
                  <div className="p-3 rounded-md bg-muted">
                    <p className="text-sm">
                      Maximum <span className="font-semibold">{selectedRoom.max_participants}</span> participants
                    </p>
                  </div>
                </div>

                {/* Security Settings */}
                <div className="space-y-3">
                  <h3 className="font-semibold flex items-center gap-2">
                    <Lock className="h-4 w-4" />
                    Security & Access
                  </h3>
                  <div className="space-y-2 text-sm">
                    <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                      <span>PIN Protection</span>
                      <Badge variant={selectedRoom.pin_required ? 'default' : 'secondary'}>
                        {selectedRoom.pin_required ? 'Enabled' : 'Disabled'}
                      </Badge>
                    </div>
                    {selectedRoom.pin_required && selectedRoom.pin && (
                      <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                        <span>Participant PIN</span>
                        <code className="font-mono">{selectedRoom.pin}</code>
                      </div>
                    )}
                    {selectedRoom.host_pin && (
                      <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                        <span>Host PIN</span>
                        <code className="font-mono">{selectedRoom.host_pin}</code>
                      </div>
                    )}
                    <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                      <span>Wait for Host</span>
                      <Badge variant={selectedRoom.wait_for_host ? 'default' : 'secondary'}>
                        {selectedRoom.wait_for_host ? 'Enabled' : 'Disabled'}
                      </Badge>
                    </div>
                  </div>
                </div>

                {/* Audio Settings */}
                <div className="space-y-3">
                  <h3 className="font-semibold flex items-center gap-2">
                    <Mic className="h-4 w-4" />
                    Audio Settings
                  </h3>
                  <div className="space-y-2 text-sm">
                    <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                      <span>Mute on Entry</span>
                      <Badge variant={selectedRoom.mute_on_entry ? 'default' : 'secondary'}>
                        {selectedRoom.mute_on_entry ? 'Enabled' : 'Disabled'}
                      </Badge>
                    </div>
                    <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                      <span>Announce Join/Leave</span>
                      <Badge variant={selectedRoom.announce_join_leave ? 'default' : 'secondary'}>
                        {selectedRoom.announce_join_leave ? 'Enabled' : 'Disabled'}
                      </Badge>
                    </div>
                    <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                      <span>Music on Hold</span>
                      <Badge variant={selectedRoom.music_on_hold ? 'default' : 'secondary'}>
                        {selectedRoom.music_on_hold ? 'Enabled' : 'Disabled'}
                      </Badge>
                    </div>
                  </div>
                </div>

                {/* Recording Settings */}
                <div className="space-y-3">
                  <h3 className="font-semibold flex items-center gap-2">
                    <Video className="h-4 w-4" />
                    Recording
                  </h3>
                  <div className="space-y-2 text-sm">
                    <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                      <span>Recording</span>
                      <Badge variant={selectedRoom.recording_enabled ? 'default' : 'secondary'}>
                        {selectedRoom.recording_enabled ? 'Enabled' : 'Disabled'}
                      </Badge>
                    </div>
                    {selectedRoom.recording_enabled && (
                      <>
                        <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                          <span>Auto-Start Recording</span>
                          <Badge
                            variant={selectedRoom.recording_auto_start ? 'default' : 'secondary'}
                          >
                            {selectedRoom.recording_auto_start ? 'Enabled' : 'Disabled'}
                          </Badge>
                        </div>
                        {selectedRoom.recording_webhook_url && (
                          <div className="flex flex-col gap-1 p-2 rounded-md bg-muted">
                            <span className="font-medium">Recording Webhook URL</span>
                            <code className="text-xs break-all">{selectedRoom.recording_webhook_url}</code>
                          </div>
                        )}
                      </>
                    )}
                  </div>
                </div>

                {/* Talk Detection */}
                <div className="space-y-3">
                  <h3 className="font-semibold flex items-center gap-2">
                    <Mic className="h-4 w-4" />
                    Talk Detection
                  </h3>
                  <div className="space-y-2 text-sm">
                    <div className="flex justify-between items-center p-2 rounded-md bg-muted">
                      <span>Talk Detection</span>
                      <Badge variant={selectedRoom.talk_detection_enabled ? 'default' : 'secondary'}>
                        {selectedRoom.talk_detection_enabled ? 'Enabled' : 'Disabled'}
                      </Badge>
                    </div>
                    {selectedRoom.talk_detection_enabled && selectedRoom.talk_detection_webhook_url && (
                      <div className="flex flex-col gap-1 p-2 rounded-md bg-muted">
                        <span className="font-medium">Webhook URL</span>
                        <code className="text-xs break-all">{selectedRoom.talk_detection_webhook_url}</code>
                      </div>
                    )}
                  </div>
                </div>

                {/* Timestamps */}
                <div className="space-y-2 pt-4 border-t text-xs text-muted-foreground">
                  <div className="flex justify-between">
                    <span>Created</span>
                    <span>{new Date(selectedRoom.created_at).toLocaleString()}</span>
                  </div>
                  <div className="flex justify-between">
                    <span>Last Updated</span>
                    <span>{new Date(selectedRoom.updated_at).toLocaleString()}</span>
                  </div>
                </div>
              </div>
            </>
          )}
        </SheetContent>
      </Sheet>
    </div >
  );
}
