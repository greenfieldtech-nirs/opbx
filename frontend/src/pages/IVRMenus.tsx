/**
 * IVR Menus Management Page
 * Full CRUD operations with backend API integration
 */

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ivrMenusService } from '@/services/ivrMenus.service';
import { extensionsService } from '@/services/extensions.service';
import { ringGroupsService } from '@/services/ringGroups.service';
import { conferenceRoomsService } from '@/services/conferenceRooms.service';
import { createResourceService } from '@/services/createResourceService';
import { cloudonixService } from '@/services/cloudonix.service';
import { settingsService } from '@/services/settings.service';
import { useAuth } from '@/hooks/useAuth';
import type {
  IvrMenu,
  IvrMenuStatus,
  IvrDestinationType,
  CreateIvrMenuRequest,
  UpdateIvrMenuRequest,
} from '@/types/api.types';
import type { CloudonixSettings } from '@/types';

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
  ArrowUpDown,
  RefreshCw,
  X,
} from 'lucide-react';

// Voice selector component with search and advanced filters
const VoiceSelector: React.FC<{
  value: string;
  onChange: (voiceId: string) => void;
  voices: any[];
  filters: any;
  onRefresh?: () => void;
  cloudonixSettings?: CloudonixSettings;
}> = ({ value, onChange, voices, filters, onRefresh, cloudonixSettings }) => {
  // Helper function to get language name from code
  const getLanguageName = (languageCode: string): string => {
    const language = filters?.languages?.find((lang: any) => lang.code === languageCode);
    return language?.name || languageCode;
  };

  const [searchTerm, setSearchTerm] = useState('');
  const [languageFilter, setLanguageFilter] = useState<string>('all');
  const [genderFilter, setGenderFilter] = useState<string>('all');
  const [providerFilter, setProviderFilter] = useState<string>('all');

  // Restrict to standard voices only for Free Tier users
  const isFreeTier = cloudonixSettings?.cloudonix_package === 'Free Tier';
  const [pricingFilter, setPricingFilter] = useState<'all' | 'standard' | 'premium'>(isFreeTier ? 'standard' : 'all');

  const filteredVoices = voices.filter((voice: any) => {
    const matchesSearch = searchTerm === '' ||
                         voice.name.toLowerCase().includes(searchTerm.toLowerCase()) ||
                         (voice.provider && voice.provider.toLowerCase().includes(searchTerm.toLowerCase())) ||
                         voice.language.toLowerCase().includes(searchTerm.toLowerCase());
    const matchesLanguage = languageFilter === 'all' || voice.language === languageFilter;
    const matchesGender = genderFilter === 'all' || voice.gender === genderFilter;
    const matchesProvider = providerFilter === 'all' || voice.provider === providerFilter;
    const matchesPricing = pricingFilter === 'all' || voice.pricing === pricingFilter;
    return matchesSearch && matchesLanguage && matchesGender && matchesProvider && matchesPricing;
  });

  return (
    <div className="space-y-3">
      <div className="flex items-center justify-between">
        <Label htmlFor="voice-select">Select Voice</Label>
        {onRefresh && (
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={onRefresh}
            className="h-8 px-3"
          >
            <RefreshCw className="h-4 w-4 mr-2" />
            Refresh
          </Button>
        )}
      </div>

      {/* Search input */}
      <Input
        placeholder="Search voices by name, provider, or language..."
        value={searchTerm}
        onChange={(e) => setSearchTerm(e.target.value)}
      />

      {/* Filter row */}
      <div className="grid grid-cols-2 md:grid-cols-4 gap-2">
        {/* Language filter */}
        <div className="space-y-1">
          <Label className="text-xs text-muted-foreground">Language</Label>
          <Select value={languageFilter} onValueChange={setLanguageFilter}>
            <SelectTrigger className="h-8">
              <SelectValue placeholder="All" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Languages</SelectItem>
              {filters?.languages?.map((lang: any) => (
                <SelectItem key={lang.code} value={lang.code}>
                  {lang.name}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Gender filter */}
        <div className="space-y-1">
          <Label className="text-xs text-muted-foreground">Gender</Label>
          <Select value={genderFilter} onValueChange={setGenderFilter}>
            <SelectTrigger className="h-8">
              <SelectValue placeholder="All" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Genders</SelectItem>
              {filters?.genders?.map((gender: string) => (
                <SelectItem key={gender} value={gender}>
                  <div className="flex items-center gap-2">
                    <span className="capitalize">{gender}</span>
                    <Badge
                      variant="secondary"
                      className={`text-xs ${
                        gender === 'female'
                          ? 'bg-pink-100 text-pink-800 border-pink-200'
                          : gender === 'male'
                          ? 'bg-blue-100 text-blue-800 border-blue-200'
                          : 'bg-gray-100 text-gray-800 border-gray-200'
                      }`}
                    >
                      {gender}
                    </Badge>
                  </div>
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Provider filter */}
        <div className="space-y-1">
          <Label className="text-xs text-muted-foreground">Provider</Label>
          <Select value={providerFilter} onValueChange={setProviderFilter}>
            <SelectTrigger className="h-8">
              <SelectValue placeholder="All" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Providers</SelectItem>
              {filters?.providers?.map((provider: string) => (
                <SelectItem key={provider} value={provider}>
                  {provider}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
        </div>

        {/* Pricing filter */}
        <div className="space-y-1">
          <Label className="text-xs text-muted-foreground">Pricing</Label>
          <Select
            value={pricingFilter}
            onValueChange={(value: 'all' | 'standard' | 'premium') => {
              if (isFreeTier && value === 'premium') return; // Prevent selecting premium for Free Tier
              setPricingFilter(value);
            }}
          >
            <SelectTrigger className="h-8">
              <SelectValue />
            </SelectTrigger>
            <SelectContent>
              {!isFreeTier && <SelectItem value="all">All Tiers</SelectItem>}
              <SelectItem value="standard">Standard</SelectItem>
              <SelectItem value="premium" disabled={isFreeTier}>
                Premium {isFreeTier && "(Upgrade required)"}
              </SelectItem>
            </SelectContent>
          </Select>
          {isFreeTier && (
            <p className="text-xs text-amber-600">
              Free Tier limited to standard voices. Upgrade to access premium voices.
            </p>
          )}
        </div>
      </div>

      {/* Voice selection */}
      <div className="space-y-1">
        <Label className="text-xs text-muted-foreground">Voice</Label>
        <Select value={value || 'Cloudonix-Neural:Zoe'} onValueChange={onChange}>
          <SelectTrigger>
            <SelectValue placeholder="Choose a voice" />
          </SelectTrigger>
          <SelectContent>
            {filteredVoices.length === 0 ? (
              <div className="p-2 text-center text-sm text-muted-foreground">
                Loading voices... Please wait...
              </div>
            ) : (
              filteredVoices.map((voice: any) => {
                const languageProviderPricing = `${getLanguageName(voice.language)} / ${voice.provider} / ${voice.pricing}`;

                return (
                  <SelectItem key={voice.id} value={voice.id}>
                    <div className="flex items-center w-full">
                      <Badge
                        variant="secondary"
                        className="bg-gray-100 text-gray-800 border-gray-200"
                        style={{
                          width: '400px',
                          padding: '8px 8px',
                          marginLeft: '12px',
                          marginRight: '12px'
                        }}
                      >
                        {languageProviderPricing}
                      </Badge>
                      <Badge
                        variant="secondary"
                        className={`text-center ${
                          voice.gender === 'female'
                            ? 'bg-pink-100 text-pink-800 border-pink-200'
                            : voice.gender === 'male'
                            ? 'bg-blue-100 text-blue-800 border-blue-200'
                            : 'bg-gray-100 text-gray-800 border-gray-200'
                        }`}
                        style={{
                          width: '72px',
                          padding: '8px 8px',
                          marginLeft: '12px',
                          marginRight: '12px'
                        }}
                      >
                        {voice.gender}
                      </Badge>
                      <span className="font-bold">{voice.name}</span>
                    </div>
                  </SelectItem>
                );
              })
            )}
          </SelectContent>
        </Select>
      </div>

      <p className="text-sm text-muted-foreground">
        {filteredVoices.length} voices available • Standard voices are free, premium voices may incur additional costs
      </p>
    </div>
  );
};

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
  const [currentPage] = useState(1);
  const [perPage] = useState(25);

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
    recording_id?: number;
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
    recording_id: undefined,
    tts_text: '',
    tts_voice: 'en-US-Neural2-A',
    useTTS: false,
    max_turns: 3,
    failover_destination_type: 'hangup',
    status: 'active',
    options: [],
  });

  // Available destinations for dropdowns - using existing API endpoints
  const { data: extensionsData, isLoading: extensionsLoading, error: extensionsError } = useQuery({
    queryKey: ['ivr-extensions'],
    queryFn: () => extensionsService.getAll({ status: 'active', per_page: 100, with: 'user' }),
  });

  const { data: ringGroupsData, isLoading: ringGroupsLoading, error: ringGroupsError } = useQuery({
    queryKey: ['ivr-ring-groups'],
    queryFn: () => ringGroupsService.getAll({ status: 'active', per_page: 100 }),
  });

  const { data: conferenceRoomsData, isLoading: conferenceRoomsLoading, error: conferenceRoomsError } = useQuery({
    queryKey: ['ivr-conference-rooms'],
    queryFn: () => conferenceRoomsService.getAll({ per_page: 100 }),
  });

  const { data: ivrMenusList, isLoading: ivrMenusLoading, error: ivrMenusError } = useQuery({
    queryKey: ['ivr-menus-list'],
    queryFn: () => ivrMenusService.getAll({ status: 'active', per_page: 100 }),
  });

  // Combine all destinations
  const availableDestinations = {
    extensions: extensionsData?.data?.map(ext => ({
      id: String(ext.id),
      label: `Ext ${ext.extension_number} - ${ext.user?.name || 'Unassigned'}`
    })) || [],
    ring_groups: ringGroupsData?.data?.map(rg => ({
      id: String(rg.id),
      label: `Ring Group: ${rg.name}`
    })) || [],
    conference_rooms: conferenceRoomsData?.data?.map(cr => ({
      id: String(cr.id),
      label: `Conference: ${cr.name}`
    })) || [],
    ivr_menus: ivrMenusList?.data?.map(menu => ({
      id: String(menu.id),
      label: `IVR Menu: ${menu.name}`
    })) || []
  };

  const destinationsLoading = extensionsLoading || ringGroupsLoading || conferenceRoomsLoading || ivrMenusLoading;
  const destinationsError = extensionsError || ringGroupsError || conferenceRoomsError || ivrMenusError;



  // Available recordings for audio selection
  const { data: recordingsData } = useQuery({
    queryKey: ['recordings'],
    queryFn: () => recordingsService.getAll({ per_page: 100 }),
  });

  // Cloudonix voices for TTS (cached for 30 days)
  const { data: voicesData, refetch: refetchVoices } = useQuery({
    queryKey: ['cloudonix-voices'],
    queryFn: () => cloudonixService.getVoices(),
    staleTime: 30 * 24 * 60 * 60 * 1000, // 30 days
    gcTime: 30 * 24 * 60 * 60 * 1000, // 30 days (gcTime replaces cacheTime in newer versions)
  });

  const voices = voicesData?.data || [];
  const filters = voicesData?.filters || {};

  // Fetch Cloudonix settings to check package tier
  const { data: cloudonixSettings } = useQuery({
    queryKey: ['cloudonix-settings'],
    queryFn: () => settingsService.getCloudonixSettings(),
    staleTime: 5 * 60 * 1000, // 5 minutes
  });



  // Function to refresh voices
  const refreshVoices = async () => {
    try {
      await refetchVoices();
      toast.success('Voices list refreshed successfully');
    } catch (error) {
      toast.error('Failed to refresh voices list');
    }
  };

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

  // Mutations
  const createMutation = useMutation({
    mutationFn: (data: CreateIvrMenuRequest) => ivrMenusService.create(data),
    onSuccess: () => {
      // Invalidate all IVR menu queries including those with parameters
      queryClient.invalidateQueries({ queryKey: ['ivr-menus'], exact: false });
      queryClient.invalidateQueries({ queryKey: ['ivr-menus-list'] });
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
      // Invalidate all IVR menu queries including those with parameters
      queryClient.invalidateQueries({ queryKey: ['ivr-menus'], exact: false });
      queryClient.invalidateQueries({ queryKey: ['ivr-menus-list'] });
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
      // Invalidate all IVR menu queries including those with parameters
      queryClient.invalidateQueries({ queryKey: ['ivr-menus'], exact: false });
      queryClient.invalidateQueries({ queryKey: ['ivr-menus-list'] });
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
    tts_voice: 'Cloudonix-Neural:Zoe',
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

    if (formData.options.length > 20) {
      toast.error('Maximum 20 menu options allowed');
      return;
    }

    if (formData.description && formData.description.length > 1000) {
      toast.error('Description must be 1000 characters or less');
      return;
    }

    if (formData.audio_file_path && formData.audio_file_path.length > 500) {
      toast.error('Audio file path must be 500 characters or less');
      return;
    }

    const requestData: CreateIvrMenuRequest = {
      name: formData.name,
      description: formData.description,
      audio_file_path: formData.useTTS ? undefined : (formData.recording_id ? undefined : formData.audio_file_path),
      recording_id: formData.useTTS ? undefined : formData.recording_id,
      tts_text: formData.useTTS ? formData.tts_text : undefined,
      tts_voice: formData.useTTS ? formData.tts_voice : undefined,
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

  // Handle update
  const handleUpdate = () => {
    if (!selectedMenu) return;

    if (!formData.name || !formData.options || formData.options.length === 0) {
      toast.error('Name and at least one option are required');
      return;
    }

    if (formData.options.length > 20) {
      toast.error('Maximum 20 menu options allowed');
      return;
    }

    if (formData.description && formData.description.length > 1000) {
      toast.error('Description must be 1000 characters or less');
      return;
    }

    if (formData.audio_file_path && formData.audio_file_path.length > 500) {
      toast.error('Audio file path must be 500 characters or less');
      return;
    }

    const requestData: UpdateIvrMenuRequest = {
      name: formData.name,
      description: formData.description,
      audio_file_path: formData.useTTS ? undefined : (formData.recording_id ? undefined : formData.audio_file_path),
      recording_id: formData.useTTS ? undefined : formData.recording_id,
      tts_text: formData.useTTS ? formData.tts_text : undefined,
      tts_voice: formData.useTTS ? formData.tts_voice : undefined,
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

    updateMutation.mutate({ id: selectedMenu.id, data: requestData });
  };

  // Add new menu option
  const addMenuOption = () => {
    // Find the highest digit in existing options and increment by 1
    const existingDigits = formData.options
      .map(option => parseInt(option.input_digits))
      .filter(digit => !isNaN(digit));
    const nextDigit = existingDigits.length > 0 ? Math.max(...existingDigits) + 1 : 1;

    setFormData({
      ...formData,
      options: [
        ...formData.options,
        {
          input_digits: nextDigit.toString(),
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
    // Convert destination_id from string to number for backend compatibility
    const processedValue = field === 'destination_id' && value !== '' ? parseInt(value, 10) : value;
    updatedOptions[index] = { ...updatedOptions[index], [field]: processedValue };
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
      tts_voice: menu.tts_voice || 'Cloudonix-Neural:Zoe', // Load from menu or default
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
      <Dialog
        open={isCreateDialogOpen}
        onOpenChange={(open) => {
          setIsCreateDialogOpen(open);
          if (open) {
            resetForm();
          }
        }}
      >
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

            {/* Debug: Show current form data */}
            {/* <div className="mb-4 p-2 bg-gray-100 text-xs">
              <strong>Debug - Form Data:</strong>
              <pre>{JSON.stringify(formData, null, 2)}</pre>
            </div> */}

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
                  maxLength={1000}
                />
                <p className="text-sm text-muted-foreground">
                  {(formData.description || '').length}/1000 characters
                  {(formData.description || '').length > 900 && (
                    <span className="text-amber-600 ml-2">Approaching limit</span>
                  )}
                </p>
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
                        value={typeof formData.audio_file_path === 'string' && formData.audio_file_path.startsWith('http') ? 'remote' : 'recording'}
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

                    {typeof formData.audio_file_path === 'string' && formData.audio_file_path.startsWith('http') ? (
                      <div className="space-y-2">
                        <Label htmlFor="audio-url">Remote Audio URL</Label>
                        <Input
                          id="audio-url"
                          value={formData.audio_file_path || ''}
                          onChange={(e) => setFormData({ ...formData, audio_file_path: e.target.value })}
                          placeholder="https://example.com/audio/welcome.mp3"
                          maxLength={500}
                        />
                        <p className="text-sm text-muted-foreground">
                          Enter a full URL to an audio file (MP3, WAV, etc.) - {(formData.audio_file_path || '').length}/500 characters
                          {(formData.audio_file_path || '').length > 450 && (
                            <span className="text-amber-600 ml-2">Approaching limit</span>
                          )}
                        </p>
                      </div>
                    ) : (
                       <div className="space-y-2">
                         <Label htmlFor="recording-select">Select Recording</Label>
                        <Select
                          value={formData.recording_id?.toString() || ''}
                          onValueChange={(value) => setFormData({ ...formData, recording_id: value ? parseInt(value) : undefined, audio_file_path: '' })}
                        >
                         <SelectTrigger>
                           <SelectValue placeholder="Choose a recording" />
                         </SelectTrigger>
                         <SelectContent>
                           {recordingsData?.data?.map((recording: any) => (
                             <SelectItem key={recording.id} value={recording.id.toString()}>
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
                      <VoiceSelector
                        value={formData.tts_voice || 'en-US-Neural2-A'}
                        onChange={(value) => setFormData({ ...formData, tts_voice: value })}
                        voices={voices}
                        filters={filters}
                        onRefresh={refreshVoices}
                        cloudonixSettings={cloudonixSettings}
                      />

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
                  <div className="flex items-center gap-2">
                    {(formData.options || []).length >= 20 && (
                      <span className="text-sm text-amber-600">Maximum 20 options</span>
                    )}
                    <Button
                      type="button"
                      onClick={addMenuOption}
                      size="sm"
                      disabled={(formData.options || []).length >= 20}
                    >
                      <Plus className="h-4 w-4 mr-2" />
                      Add Option
                    </Button>
                  </div>
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
                              <div>
                                <Input
                                  value={option.input_digits}
                                  onChange={(e) => {
                                    const value = e.target.value;
                                    // Only allow digits and some special characters (*, #)
                                    if (/^[0-9*#]*$/.test(value)) {
                                      updateMenuOption(index, 'input_digits', value);
                                    }
                                  }}
                                  placeholder="1"
                                  maxLength={10}
                                  className={option.input_digits && !/^[0-9*#]+$/.test(option.input_digits) ? 'border-red-500' : ''}
                                />
                                {option.input_digits && !/^[0-9*#]+$/.test(option.input_digits) && (
                                  <p className="text-sm text-red-500 mt-1">
                                    Only digits (0-9), asterisk (*), and pound (#) are allowed
                                  </p>
                                )}
                              </div>
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
                                onValueChange={(value) => {
                                  // Update both destination_type and reset destination_id in a single state update
                                  const updatedOptions = [...formData.options];
                                  updatedOptions[index] = {
                                    ...updatedOptions[index],
                                    destination_type: value as IvrDestinationType,
                                    destination_id: ''
                                  };
                                  setFormData({ ...formData, options: updatedOptions });
                                }}
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
                                key={`destination-${index}-${option.destination_type}`}
                                value={option.destination_id?.toString() || ''}
                                onValueChange={(value) => updateMenuOption(index, 'destination_id', value)}
                                disabled={!option.destination_type}
                              >
                                <SelectTrigger>
                                  <SelectValue placeholder="Select destination" />
                                </SelectTrigger>
                                <SelectContent>
                                  {destinationsLoading ? (
                                    <div className="px-2 py-1 text-sm text-muted-foreground">
                                      Loading destinations...
                                    </div>
                                  ) : destinationsError ? (
                                    <div className="px-2 py-1 text-sm text-destructive">
                                      Error loading destinations
                                    </div>
                                  ) : (
                                    <>
                                      {option.destination_type === 'extension' && availableDestinations?.extensions?.map((ext) => (
                                        <SelectItem key={ext.id} value={ext.id}>
                                          {ext.label}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ring_group' && availableDestinations?.ring_groups?.map((rg) => (
                                        <SelectItem key={rg.id} value={rg.id}>
                                          {rg.label}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'conference_room' && availableDestinations?.conference_rooms?.map((cr) => (
                                        <SelectItem key={cr.id} value={cr.id}>
                                          {cr.label}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ivr_menu' && availableDestinations?.ivr_menus?.map((menu) => (
                                        <SelectItem key={menu.id} value={menu.id}>
                                          {menu.label}
                                        </SelectItem>
                                      ))}
                                      {(() => {
                                        const hasOptions = option.destination_type === 'extension' && availableDestinations?.extensions?.length > 0 ||
                                          option.destination_type === 'ring_group' && availableDestinations?.ring_groups?.length > 0 ||
                                          option.destination_type === 'conference_room' && availableDestinations?.conference_rooms?.length > 0 ||
                                          option.destination_type === 'ivr_menu' && availableDestinations?.ivr_menus?.length > 0;

                                        if (!hasOptions && !destinationsLoading && !destinationsError) {
                                          return (
                                            <div className="px-2 py-1 text-sm text-muted-foreground">
                                              No {option.destination_type?.replace('_', ' ')}s available
                                            </div>
                                          );
                                        }
                                        return null;
                                      })()}
                                    </>
                                  )}
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
                    key={`failover-${formData.failover_destination_type}`}
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

      {/* Edit Dialog - Full tabbed interface */}
      <Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen}>
        <DialogContent className="max-w-4xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit IVR Menu</DialogTitle>
            <DialogDescription>
              Update the IVR menu configuration
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
                  <Label htmlFor="edit-name">Name *</Label>
                  <Input
                    id="edit-name"
                    value={formData.name || ''}
                    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                    placeholder="e.g., Main Menu"
                  />
                </div>
                <div className="space-y-2">
                  <Label htmlFor="edit-status">Status</Label>
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
                <Label htmlFor="edit-description">Description</Label>
                <Textarea
                  id="edit-description"
                  value={formData.description || ''}
                  onChange={(e) => setFormData({ ...formData, description: e.target.value })}
                  placeholder="Optional description of the IVR menu"
                  rows={3}
                  maxLength={1000}
                />
                <p className="text-sm text-muted-foreground">
                  {(formData.description || '').length}/1000 characters
                  {(formData.description || '').length > 900 && (
                    <span className="text-amber-600 ml-2">Approaching limit</span>
                  )}
                </p>
              </div>
            </TabsContent>

            <TabsContent value="audio" className="space-y-4">
              <div className="space-y-4">
                <div className="flex items-center space-x-4">
                  <div className="flex items-center space-x-2">
                    <input
                      type="radio"
                      id="edit-audio-file"
                      name="edit-audio-type"
                      checked={!formData.useTTS}
                      onChange={() => setFormData({ ...formData, useTTS: false, tts_text: '', audio_file_path: formData.audio_file_path || '' })}
                    />
                    <Label htmlFor="edit-audio-file">Audio File</Label>
                  </div>
                  <div className="flex items-center space-x-2">
                    <input
                      type="radio"
                      id="edit-text-to-speech"
                      name="edit-audio-type"
                      checked={formData.useTTS}
                      onChange={() => setFormData({ ...formData, useTTS: true, audio_file_path: '', tts_text: formData.tts_text || '' })}
                    />
                    <Label htmlFor="edit-text-to-speech">Text-to-Speech</Label>
                  </div>
                </div>

                {!formData.useTTS ? (
                  <div className="space-y-4">
                    <div className="space-y-2">
                      <Label htmlFor="edit-audio-source">Audio Source</Label>
                      <Select
                        value={typeof formData.audio_file_path === 'string' && formData.audio_file_path.startsWith('http') ? 'remote' : 'recording'}
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

                    {typeof formData.audio_file_path === 'string' && formData.audio_file_path.startsWith('http') ? (
                      <div className="space-y-2">
                        <Label htmlFor="edit-audio-url">Remote Audio URL</Label>
                        <Input
                          id="edit-audio-url"
                          value={formData.audio_file_path || ''}
                          onChange={(e) => setFormData({ ...formData, audio_file_path: e.target.value })}
                          placeholder="https://example.com/audio/welcome.mp3"
                          maxLength={500}
                        />
                        <p className="text-sm text-muted-foreground">
                          Enter a full URL to an audio file (MP3, WAV, etc.) - {(formData.audio_file_path || '').length}/500 characters
                          {(formData.audio_file_path || '').length > 450 && (
                            <span className="text-amber-600 ml-2">Approaching limit</span>
                          )}
                        </p>
                      </div>
                    ) : (
                      <div className="space-y-2">
                        <Label htmlFor="edit-recording-select">Select Recording</Label>
                        <Select
                          value={formData.recording_id?.toString() || ''}
                          onValueChange={(value) => setFormData({ ...formData, recording_id: value ? parseInt(value) : undefined, audio_file_path: '' })}
                        >
                         <SelectTrigger>
                           <SelectValue placeholder="Choose a recording" />
                         </SelectTrigger>
                         <SelectContent>
                           {recordingsData?.data?.map((recording: any) => (
                             <SelectItem key={recording.id} value={recording.id.toString()}>
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
                      <VoiceSelector
                        value={formData.tts_voice || 'en-US-Neural2-A'}
                        onChange={(value) => setFormData({ ...formData, tts_voice: value })}
                        voices={voices}
                        filters={filters}
                        onRefresh={refreshVoices}
                        cloudonixSettings={cloudonixSettings}
                      />

                     <div className="space-y-2">
                       <Label htmlFor="edit-tts-text">Text to Speak</Label>
                      <Textarea
                        id="edit-tts-text"
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
                  <div className="flex items-center gap-2">
                    {(formData.options || []).length >= 20 && (
                      <span className="text-sm text-amber-600">Maximum 20 options</span>
                    )}
                    <Button
                      type="button"
                      onClick={addMenuOption}
                      size="sm"
                      disabled={(formData.options || []).length >= 20}
                    >
                      <Plus className="h-4 w-4 mr-2" />
                      Add Option
                    </Button>
                  </div>
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
                              <div>
                                <Input
                                  value={option.input_digits}
                                  onChange={(e) => {
                                    const value = e.target.value;
                                    // Only allow digits and some special characters (*, #)
                                    if (/^[0-9*#]*$/.test(value)) {
                                      updateMenuOption(index, 'input_digits', value);
                                    }
                                  }}
                                  placeholder="1"
                                  maxLength={10}
                                  className={option.input_digits && !/^[0-9*#]+$/.test(option.input_digits) ? 'border-red-500' : ''}
                                />
                                {option.input_digits && !/^[0-9*#]+$/.test(option.input_digits) && (
                                  <p className="text-sm text-red-500 mt-1">
                                    Only digits (0-9), asterisk (*), and pound (#) are allowed
                                  </p>
                                )}
                              </div>
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
                                onValueChange={(value) => {
                                  // Update both destination_type and reset destination_id in a single state update
                                  const updatedOptions = [...formData.options];
                                  updatedOptions[index] = {
                                    ...updatedOptions[index],
                                    destination_type: value as IvrDestinationType,
                                    destination_id: ''
                                  };
                                  setFormData({ ...formData, options: updatedOptions });
                                }}
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
                                key={`destination-${index}-${option.destination_type}`}
                                value={option.destination_id?.toString() || ''}
                                onValueChange={(value) => updateMenuOption(index, 'destination_id', value)}
                                disabled={!option.destination_type}
                              >
                                <SelectTrigger>
                                  <SelectValue placeholder="Select destination" />
                                </SelectTrigger>
                                <SelectContent>
                                  {destinationsLoading ? (
                                    <div className="px-2 py-1 text-sm text-muted-foreground">
                                      Loading destinations...
                                    </div>
                                  ) : destinationsError ? (
                                    <div className="px-2 py-1 text-sm text-destructive">
                                      Error loading destinations
                                    </div>
                                  ) : (
                                    <>
                                      {option.destination_type === 'extension' && availableDestinations?.extensions?.map((ext) => (
                                        <SelectItem key={ext.id} value={ext.id}>
                                          {ext.label}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ring_group' && availableDestinations?.ring_groups?.map((rg) => (
                                        <SelectItem key={rg.id} value={rg.id}>
                                          {rg.label}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'conference_room' && availableDestinations?.conference_rooms?.map((cr) => (
                                        <SelectItem key={cr.id} value={cr.id}>
                                          {cr.label}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ivr_menu' && availableDestinations?.ivr_menus?.map((menu) => (
                                        <SelectItem key={menu.id} value={menu.id}>
                                          {menu.label}
                                        </SelectItem>
                                      ))}
                                      {(() => {
                                        const hasOptions = option.destination_type === 'extension' && availableDestinations?.extensions?.length > 0 ||
                                          option.destination_type === 'ring_group' && availableDestinations?.ring_groups?.length > 0 ||
                                          option.destination_type === 'conference_room' && availableDestinations?.conference_rooms?.length > 0 ||
                                          option.destination_type === 'ivr_menu' && availableDestinations?.ivr_menus?.length > 0;

                                        if (!hasOptions && !destinationsLoading && !destinationsError) {
                                          return (
                                            <div className="px-2 py-1 text-sm text-muted-foreground">
                                              No {option.destination_type?.replace('_', ' ')}s available
                                            </div>
                                          );
                                        }
                                        return null;
                                      })()}
                                    </>
                                  )}
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
                  <Label htmlFor="edit-max-turns">Maximum Turns</Label>
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
                  <Label htmlFor="edit-failover-type">Failover Destination</Label>
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
                    key={`failover-${formData.failover_destination_type}`}
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
            <Button variant="outline" onClick={() => setIsEditDialogOpen(false)}>
              Cancel
            </Button>
            <Button onClick={handleUpdate} disabled={updateMutation.isPending}>
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