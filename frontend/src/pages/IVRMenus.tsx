/**
 * IVR Menus Management Page
 * Full CRUD operations with backend API integration
 */

import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ivrMenusService, type AvailableDestinations } from '@/services/ivrMenus.service';
import { extensionsService } from '@/services/extensions.service';
import { createResourceService } from '@/services/createResourceService';
import { cloudonixService } from '@/services/cloudonix.service';
import { useAuth } from '@/hooks/useAuth';
import type {
  IvrMenu,
  IvrMenuStatus,
  IvrDestinationType,
  CreateIvrMenuRequest,
  UpdateIvrMenuRequest,
  Extension,
} from '@/types/api.types';

// Create recordings service
const recordingsService = createResourceService('recordings');
import { Card, CardContent } from '@/components/ui/card';
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
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { Badge } from '@/components/ui/badge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Plus,
  Search,
  Filter,
  Phone,
  Edit,
  Trash2,
  Eye,
  ArrowUpDown,
  RefreshCw,
  X,
  Upload,
  Mic,
} from 'lucide-react';

export default function IVRMenus() {
  const queryClient = useQueryClient();
  const { user: currentUser } = useAuth();

  // Permission check
  const canManage = currentUser ? ['owner', 'pbx_admin'].includes(currentUser.role) : false;

  // UI State
  const [searchQuery, setSearchQuery] = useState('');
  const [statusFilter, setStatusFilter] = useState<IvrMenuStatus | 'all'>('all');
  const [sortField, setSortField] = useState<'name' | 'status'>('name');
  const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage, setPerPage] = useState(25);

  // Dialog states
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [selectedMenu, setSelectedMenu] = useState<IvrMenu | null>(null);

  // Form data
  const [formData, setFormData] = useState<{
    name: string;
    description?: string;
    audio_file_path?: string;
    tts_text?: string;
    tts_voice?: string;
    useTTS: boolean;
    max_turns: number;
    failover_destination_type: IvrDestinationType;
    failover_destination_id?: string;
    status: IvrMenuStatus;
    options: Array<{
      input_digits: string;
      description?: string;
      destination_type: IvrDestinationType;
      destination_id: string;
    }>;
  }>({
    name: '',
    description: '',
    audio_file_path: '',
    tts_text: '',
    tts_voice: 'en-US-Neural2-A',
    useTTS: false,
    max_turns: 3,
    failover_destination_type: 'hangup',
    status: 'active',
    options: [],
  });

  // Available destinations for dropdowns
  const { data: availableDestinations } = useQuery({
    queryKey: ['ivr-available-destinations'],
    queryFn: () => ivrMenusService.getAvailableDestinations(),
  });

  // Available recordings for audio selection
  const { data: recordingsData } = useQuery({
    queryKey: ['recordings'],
    queryFn: () => recordingsService.getAll({ per_page: 100 }),
  });

  // Cloudonix voices for TTS (cached for 30 days)
  const { data: voicesData } = useQuery({
    queryKey: ['cloudonix-voices'],
    queryFn: () => cloudonixService.getVoices(),
    staleTime: 30 * 24 * 60 * 60 * 1000, // 30 days
    cacheTime: 30 * 24 * 60 * 60 * 1000, // 30 days
  });

  // Fetch IVR menus
  const { data: ivrMenusData, isLoading, error, refetch, isRefetching } = useQuery({
    queryKey: ['ivr-menus', {
      page: currentPage,
      per_page: perPage,
      search: searchQuery || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_direction: sortDirection,
    }],
    queryFn: () => ivrMenusService.getAll({
      page: currentPage,
      per_page: perPage,
      search: searchQuery || undefined,
      status: statusFilter !== 'all' ? statusFilter : undefined,
      sort_by: sortField,
      sort_direction: sortDirection,
    }),
  });

  const ivrMenus = ivrMenusData?.data || [];
  const totalMenus = ivrMenusData?.meta?.total || 0;

  // Mutations
  const createMutation = useMutation({
    mutationFn: (data: CreateIvrMenuRequest) => ivrMenusService.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ivr-menus'] });
      setIsCreateDialogOpen(false);
      resetForm();
      toast.success('IVR menu created successfully');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Failed to create IVR menu');
    },
  });

  const updateMutation = useMutation({
    mutationFn: ({ id, data }: { id: string; data: UpdateIvrMenuRequest }) =>
      ivrMenusService.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ivr-menus'] });
      setIsEditDialogOpen(false);
      setSelectedMenu(null);
      resetForm();
      toast.success('IVR menu updated successfully');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Failed to update IVR menu');
    },
  });

  const deleteMutation = useMutation({
    mutationFn: (id: string) => ivrMenusService.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ivr-menus'] });
      setIsDeleteDialogOpen(false);
      setSelectedMenu(null);
      toast.success('IVR menu deleted successfully');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Failed to delete IVR menu');
    },
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

  // Reset form
  const resetForm = () => {
    setFormData({
      name: '',
      description: '',
      audio_file_path: '',
      tts_text: '',
      tts_voice: 'en-US-Neural2-A',
      useTTS: false,
      max_turns: 3,
      failover_destination_type: 'hangup',
      status: 'active',
      options: [],
    });
  };

  // Handle create
  const handleCreate = () => {
    if (!formData.name || !formData.options || formData.options.length === 0) {
      toast.error('Name and at least one option are required');
      return;
    }

    const requestData: CreateIvrMenuRequest = {
      name: formData.name,
      description: formData.description,
      audio_file_path: formData.useTTS ? undefined : formData.audio_file_path,
      tts_text: formData.useTTS ? formData.tts_text : undefined,
      max_turns: formData.max_turns || 3,
      failover_destination_type: formData.failover_destination_type as any,
      failover_destination_id: formData.failover_destination_id,
      status: formData.status as IvrMenuStatus,
      options: formData.options.map((option, index) => ({
        input_digits: option.input_digits,
        description: option.description,
        destination_type: option.destination_type,
        destination_id: option.destination_id,
        priority: index + 1,
      })),
    };

    createMutation.mutate(requestData);
  };

  // Add new menu option
  const addMenuOption = () => {
    setFormData({
      ...formData,
      options: [
        ...formData.options,
        {
          input_digits: '',
          description: '',
          destination_type: 'extension' as IvrDestinationType,
          destination_id: '',
        },
      ],
    });
  };

  // Remove menu option
  const removeMenuOption = (index: number) => {
    setFormData({
      ...formData,
      options: (formData.options || []).filter((_, i) => i !== index),
    });
  };

  // Update menu option
  const updateMenuOption = (index: number, field: keyof typeof formData.options[0], value: any) => {
    const updatedOptions = [...formData.options];
    updatedOptions[index] = { ...updatedOptions[index], [field]: value };
    setFormData({ ...formData, options: updatedOptions });
  };

  // Open edit dialog
  const openEditDialog = (menu: IvrMenu) => {
    setSelectedMenu(menu);
    setFormData({
      name: menu.name,
      description: menu.description,
      audio_file_path: menu.audio_file_path,
      tts_text: menu.tts_text,
      tts_voice: 'en-US-Neural2-A', // Default voice
      useTTS: !!menu.tts_text,
      max_turns: menu.max_turns,
      failover_destination_type: menu.failover_destination_type,
      failover_destination_id: menu.failover_destination_id,
      status: menu.status,
      options: [...menu.options],
    });
    setIsEditDialogOpen(true);
  };

  // Open delete dialog
  const openDeleteDialog = (menu: IvrMenu) => {
    setSelectedMenu(menu);
    setIsDeleteDialogOpen(true);
  };

  // Handle delete
  const handleDelete = () => {
    if (!selectedMenu) return;
    deleteMutation.mutate(selectedMenu.id);
  };

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="flex justify-between items-start">
        <div>
          <h1 className="text-3xl font-bold flex items-center gap-2">
            <Phone className="h-8 w-8" />
            IVR Menus
          </h1>
          <p className="text-muted-foreground mt-1">Manage interactive voice response menus</p>
          <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
            <span>Dashboard</span>
            <span>/</span>
            <span className="text-foreground">IVR Menus</span>
          </div>
        </div>
        {canManage && (
          <Button onClick={() => setIsCreateDialogOpen(true)}>
            <Plus className="h-4 w-4 mr-2" />
            Create IVR Menu
          </Button>
        )}
      </div>

      {/* Filters */}
      <Card>
        <CardContent className="p-4">
          <div className="flex flex-col md:flex-row gap-4">
            <div className="flex-1">
              <div className="relative">
                <Search className="absolute left-3 top-1/2 transform -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                <Input
                  placeholder="Search IVR menus..."
                  value={searchQuery}
                  onChange={(e) => setSearchQuery(e.target.value)}
                  className="pl-10"
                />
              </div>
            </div>
            <Button
              variant="outline"
              size="icon"
              onClick={() => refetch()}
              disabled={isRefetching}
              title="Refresh"
            >
              <RefreshCw className={isRefetching ? 'animate-spin' : ''} />
            </Button>
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
                <TableHead>Description</TableHead>
                <TableHead>Options</TableHead>
                <TableHead>Max Turns</TableHead>
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
              {isLoading ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center text-muted-foreground py-8">
                    Loading IVR menus...
                  </TableCell>
                </TableRow>
              ) : error ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center text-red-500 py-8">
                    Error loading IVR menus. Please try again.
                  </TableCell>
                </TableRow>
              ) : ivrMenus.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={6} className="text-center py-12">
                    <Phone className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
                    <h3 className="text-lg font-semibold mb-2">No IVR menus found</h3>
                    <p className="text-muted-foreground mb-4">
                      {searchQuery || statusFilter !== 'all'
                        ? 'Try adjusting your filters'
                        : 'Get started by creating your first IVR menu'}
                    </p>
                    {canManage && !searchQuery && statusFilter === 'all' && (
                      <Button onClick={() => setIsCreateDialogOpen(true)}>
                        <Plus className="h-4 w-4 mr-2" />
                        Create IVR Menu
                      </Button>
                    )}
                  </TableCell>
                </TableRow>
              ) : (
                ivrMenus.map((menu) => (
                  <TableRow key={menu.id}>
                    <TableCell>
                      <div>
                        <div className="font-medium">{menu.name}</div>
                        {menu.description && (
                          <div className="text-sm text-muted-foreground line-clamp-1">
                            {menu.description}
                          </div>
                        )}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="text-sm text-muted-foreground line-clamp-2">
                        {menu.description || 'No description'}
                      </div>
                    </TableCell>
                    <TableCell>
                      <div className="flex items-center gap-1">
                        <Phone className="h-3 w-3 text-muted-foreground" />
                        <span className="text-sm">{menu.options_count || menu.options.length}</span>
                      </div>
                    </TableCell>
                    <TableCell>
                      <span className="text-sm">{menu.max_turns}</span>
                    </TableCell>
                    <TableCell>
                      <Badge variant={menu.status === 'active' ? 'default' : 'secondary'}>
                        {menu.status}
                      </Badge>
                    </TableCell>
                    <TableCell className="text-right">
                      <div className="flex items-center justify-end gap-2">
                        <Button
                          variant="ghost"
                          size="sm"
                          onClick={() => openEditDialog(menu)}
                        >
                          <Edit className="h-4 w-4" />
                        </Button>
                        {canManage && (
                          <Button
                            variant="ghost"
                            size="sm"
                            onClick={() => openDeleteDialog(menu)}
                          >
                            <Trash2 className="h-4 w-4 text-red-500" />
                          </Button>
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
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create IVR Menu</DialogTitle>
            <DialogDescription>
              Configure a new interactive voice response menu
            </DialogDescription>
          </DialogHeader>

          <Tabs defaultValue="basic" className="w-full">
            <TabsList className="grid w-full grid-cols-4">
              <TabsTrigger value="basic">Basic</TabsTrigger>
              <TabsTrigger value="audio">Audio</TabsTrigger>
              <TabsTrigger value="options">Options</TabsTrigger>
              <TabsTrigger value="advanced">Advanced</TabsTrigger>
            </TabsList>

            <TabsContent value="basic" className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="name">Name *</Label>
                  <Input
                    id="name"
                    value={formData.name || ''}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g., Main Menu"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="status">Status</Label>
                  <Select
                    value={formData.status}
                    onValueChange={(value) => setFormData({ ...formData, status: value as IvrMenuStatus })}
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
              </div>
              <div className="space-y-2">
                <Label htmlFor="description">Description</Label>
                <Textarea
                  id="description"
                  value={formData.description || ''}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  placeholder="Optional description of the IVR menu"
                  rows={3}
                />
              </div>
            </TabsContent>

            <TabsContent value="audio" className="space-y-4">
              <div className="space-y-4">
                <div className="flex items-center space-x-4">
                  <div className="flex items-center space-x-2">
                    <input
                      type="radio"
                      id="audio-file"
                      name="audio-type"
                      checked={!formData.useTTS}
                      onChange={() => setFormData({ ...formData, useTTS: false, tts_text: '', audio_file_path: formData.audio_file_path || '' })}
                    />
                    <Label htmlFor="audio-file">Audio File</Label>
                  </div>
                  <div className="flex items-center space-x-2">
                    <input
                      type="radio"
                      id="text-to-speech"
                      name="audio-type"
                      checked={formData.useTTS}
                      onChange={() => setFormData({ ...formData, useTTS: true, audio_file_path: '', tts_text: formData.tts_text || '' })}
                    />
                    <Label htmlFor="text-to-speech">Text-to-Speech</Label>
                  </div>
                </div>

                {!formData.useTTS ? (
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <Label htmlFor="audio-source">Audio Source</Label>
                      <Select
                        value={formData.audio_file_path?.startsWith('http') ? 'remote' : 'recording'}
                        onValueChange={(value) => {
                          if (value === 'remote') {
                            setFormData({ ...formData, audio_file_path: 'https://' });
                          } else {
                            setFormData({ ...formData, audio_file_path: '' });
                          }
                        }}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="recording">From Recordings</SelectItem>
                          <SelectItem value="remote">Remote URL</SelectItem>
                        </SelectContent>
                      </Select>
                    </div>

                    {formData.audio_file_path?.startsWith('http') ? (
                      <div className="space-y-2">
                        <Label htmlFor="audio-url">Remote Audio URL</Label>
                        <Input
                          id="audio-url"
                          value={formData.audio_file_path || ''}
                          onChange={(e) => setFormData({ ...formData, audio_file_path: e.target.value })}
                          placeholder="https://example.com/audio/welcome.mp3"
                        />
                        <p className="text-sm text-muted-foreground">
                          Enter a full URL to an audio file (MP3, WAV, etc.)
                        </p>
                      </div>
                    ) : (
                      <div className="space-y-2">
                        <Label htmlFor="recording-select">Select Recording</Label>
                        <Select
                          value={formData.audio_file_path || ''}
                          onValueChange={(value) => setFormData({ ...formData, audio_file_path: value })}
                        >
                          <SelectTrigger>
                            <SelectValue placeholder="Choose a recording" />
                          </SelectTrigger>
                          <SelectContent>
                            {recordingsData?.data?.map((recording: any) => (
                              <SelectItem key={recording.id} value={recording.file_path || recording.id}>
                                {recording.name || `Recording ${recording.id}`}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                          Select from uploaded recordings or upload new ones in the Recordings page
                        </p>
                      </div>
                    )}
                  </div>
                ) : (
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <Label htmlFor="tts-voice">Voice Selection</Label>
                      <Select
                        value={formData.tts_voice || 'en-US-Neural2-A'}
                        onValueChange={(value) => setFormData({ ...formData, tts_voice: value })}
                      >
                        <SelectTrigger>
                          <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                          {voicesData?.map((voice: any) => (
                            <SelectItem key={voice.id} value={voice.id}>
                              {voice.name}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <p className="text-sm text-muted-foreground">
                        Choose the voice for text-to-speech conversion
                      </p>
                    </div>

                    <div className="space-y-2">
                      <Label htmlFor="tts-text">Text to Speak</Label>
                      <Textarea
                        id="tts-text"
                        value={formData.tts_text || ''}
                        onChange={(e) => setFormData({ ...formData, tts_text: e.target.value })}
                        placeholder="Enter the text that will be converted to speech"
                        rows={4}
                      />
                      <p className="text-sm text-muted-foreground">
                        Maximum 1000 characters. Use SSML tags for advanced formatting.
                      </p>
                    </div>
                  </div>
                )}
              </div>
            </TabsContent>

            <TabsContent value="options" className="space-y-4">
              <div className="space-y-4">
                <div className="flex justify-between items-center">
                  <Label className="text-base font-medium">Menu Options</Label>
                  <Button type="button" onClick={addMenuOption} size="sm">
                    <Plus className="h-4 w-4 mr-2" />
                    Add Option
                  </Button>
                </div>

                {(formData.options || []).length === 0 ? (
                  <div className="text-center py-8 text-muted-foreground">
                    <Phone className="h-12 w-12 mx-auto mb-4 opacity-50" />
                    <p>No menu options configured</p>
                    <p className="text-sm">Add options below to define how callers navigate your IVR menu</p>
                  </div>
                ) : (
                  <div className="space-y-4">
                    {(formData.options || []).map((option, index) => (
                      <Card key={index}>
                        <CardContent className="p-4">
                          <div className="grid grid-cols-12 gap-4 items-end">
                            <div className="col-span-2">
                              <Label>Digits *</Label>
                              <Input
                                value={option.input_digits}
                                onChange={(e) => updateMenuOption(index, 'input_digits', e.target.value)}
                                placeholder="1"
                                maxLength={10}
                              />
                            </div>
                            <div className="col-span-3">
                              <Label>Description</Label>
                              <Input
                                value={option.description || ''}
                                onChange={(e) => updateMenuOption(index, 'description', e.target.value)}
                                placeholder="Press 1 for sales"
                              />
                            </div>
                            <div className="col-span-2">
                              <Label>Type</Label>
                              <Select
                                value={option.destination_type}
                                onValueChange={(value) => updateMenuOption(index, 'destination_type', value)}
                              >
                                <SelectTrigger>
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                  <SelectItem value="extension">Extension</SelectItem>
                                  <SelectItem value="ring_group">Ring Group</SelectItem>
                                  <SelectItem value="conference_room">Conference</SelectItem>
                                  <SelectItem value="ivr_menu">IVR Menu</SelectItem>
                                </SelectContent>
                              </Select>
                            </div>
                            <div className="col-span-4">
                              <Label>Destination</Label>
                              <Select
                                value={option.destination_id}
                                onValueChange={(value) => updateMenuOption(index, 'destination_id', value)}
                              >
                                <SelectTrigger>
                                  <SelectValue placeholder="Select destination" />
                                </SelectTrigger>
                                <SelectContent>
                                  {availableDestinations?.extensions?.map((ext) => (
                                    <SelectItem key={ext.id} value={ext.id}>
                                      {ext.label}
                                    </SelectItem>
                                  ))}
                                  {availableDestinations?.ring_groups?.map((rg) => (
                                    <SelectItem key={rg.id} value={rg.id}>
                                      {rg.label}
                                    </SelectItem>
                                  ))}
                                  {availableDestinations?.conference_rooms?.map((cr) => (
                                    <SelectItem key={cr.id} value={cr.id}>
                                      {cr.label}
                                    </SelectItem>
                                  ))}
                                  {availableDestinations?.ivr_menus?.map((menu) => (
                                    <SelectItem key={menu.id} value={menu.id}>
                                      {menu.label}
                                    </SelectItem>
                                  ))}
                                </SelectContent>
                              </Select>
                            </div>
                            <div className="col-span-1">
                              <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => removeMenuOption(index)}
                                className="w-full"
                              >
                                <X className="h-4 w-4" />
                              </Button>
                            </div>
                          </div>
                        </CardContent>
                      </Card>
                    ))}
                  </div>
                )}
              </div>
            </TabsContent>

            <TabsContent value="advanced" className="space-y-4">
              <div className="grid grid-cols-2 gap-4">
                <div className="space-y-2">
                  <Label htmlFor="max-turns">Maximum Turns</Label>
                  <Select
                    value={String(formData.max_turns || 3)}
                    onValueChange={(value) => setFormData({ ...formData, max_turns: parseInt(value) })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {[1, 2, 3, 4, 5, 6, 7, 8, 9].map((num) => (
                        <SelectItem key={num} value={String(num)}>
                          {num}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <p className="text-sm text-muted-foreground">
                    How many times to replay the menu on invalid input
                  </p>
                </div>

                <div className="space-y-2">
                  <Label htmlFor="failover-type">Failover Destination</Label>
                  <Select
                    value={formData.failover_destination_type}
                    onValueChange={(value) => setFormData({
                      ...formData,
                      failover_destination_type: value as IvrDestinationType,
                      failover_destination_id: value === 'hangup' ? undefined : formData.failover_destination_id
                    })}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="hangup">Hang Up</SelectItem>
                      <SelectItem value="extension">Extension</SelectItem>
                      <SelectItem value="ring_group">Ring Group</SelectItem>
                      <SelectItem value="conference_room">Conference Room</SelectItem>
                      <SelectItem value="ivr_menu">IVR Menu</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>

              {formData.failover_destination_type && formData.failover_destination_type !== 'hangup' && (
                <div className="space-y-2">
                  <Label>Failover Destination</Label>
                  <Select
                    value={formData.failover_destination_id || ''}
                    onValueChange={(value) => setFormData({ ...formData, failover_destination_id: value })}
                  >
                    <SelectTrigger>
                      <SelectValue placeholder="Select failover destination" />
                    </SelectTrigger>
                    <SelectContent>
                      {formData.failover_destination_type === 'extension' &&
                        availableDestinations?.extensions?.map((ext) => (
                          <SelectItem key={ext.id} value={ext.id}>
                            {ext.label}
                          </SelectItem>
                        ))}
                      {formData.failover_destination_type === 'ring_group' &&
                        availableDestinations?.ring_groups?.map((rg) => (
                          <SelectItem key={rg.id} value={rg.id}>
                            {rg.label}
                          </SelectItem>
                        ))}
                      {formData.failover_destination_type === 'conference_room' &&
                        availableDestinations?.conference_rooms?.map((cr) => (
                          <SelectItem key={cr.id} value={cr.id}>
                            {cr.label}
                          </SelectItem>
                        ))}
                      {formData.failover_destination_type === 'ivr_menu' &&
                        availableDestinations?.ivr_menus?.map((menu) => (
                          <SelectItem key={menu.id} value={menu.id}>
                            {menu.label}
                          </SelectItem>
                        ))}
                    </SelectContent>
                  </Select>
                </div>
              )}
            </TabsContent>
          </Tabs>

          <DialogFooter>
            <Button variant="outline" onClick={() => setIsCreateDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleCreate} disabled={createMutation.isPending}>
              Create Menu
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Edit Dialog - Similar to create, but pre-populated */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Edit IVR Menu</DialogTitle>
            <DialogDescription>
              Update the IVR menu configuration
            </DialogDescription>
          </DialogHeader>
          <div className="space-y-4">
            <div>
              <label className="text-sm font-medium">Name</label>
              <Input
                value={formData.name || ''}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
              />
            </div>
            <div>
              <label className="text-sm font-medium">Description</label>
              <Input
                value={formData.description || ''}
                onChange={(e) => setFormData({ ...formData, description: e.target.value })}
              />
            </div>
            <div>
              <label className="text-sm font-medium">Status</label>
              <Select
                value={formData.status}
                onValueChange={(value) => setFormData({ ...formData, status: value as IvrMenuStatus })}
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
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={() => updateMutation.mutate({
              id: selectedMenu!.id,
              data: {
                name: formData.name,
                description: formData.description,
                status: formData.status as IvrMenuStatus,
              }
            })} disabled={updateMutation.isPending}>
              Update Menu
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Delete Dialog */}
      <Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>Delete IVR Menu</DialogTitle>
            <DialogDescription>
              Are you sure you want to delete "{selectedMenu?.name}"? This action cannot be undone.
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
    </div>
  );
}