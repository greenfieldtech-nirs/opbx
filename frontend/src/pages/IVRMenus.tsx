/**
 * IVR Menus Management Page
 * Full CRUD operations with backend API integration
 */

import { useState } from 'react';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { toast } from 'sonner';
import { ivrMenusService } from '@/services/createResourceService';
import { extensionsService } from '@/services/extensions.service';
import { ringGroupsService } from '@/services/createResourceService';
import { conferenceRoomsService } from '@/services/createResourceService';
import { createResourceService } from '@/services/createResourceService';
import aiAssistantsService from '@/services/aiAssistants.service';
import { aiAssistantLoadBalancersService } from '@/services/createResourceService';
import { cloudonixService } from '@/services/cloudonix.service';
import { settingsService } from '@/services/settings.service';
import { useAuth } from '@/hooks/useAuth';
import api from '@/services/api';
import { cn } from '@/lib/utils';
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
import { Switch } from '@/components/ui/switch';
import { Combobox } from '@/components/ui/combobox';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
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
  ChevronDown,
  ArrowRight,
  Users,
  Menu,
  Bot,
  Scale,
  UserCheck,
} from 'lucide-react';
import {
  StandardDataTable,
  Column,
  EmptyState
} from '@/components/design-system';

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


  const [languageFilter, setLanguageFilter] = useState<string>('all');
  const [genderFilter, setGenderFilter] = useState<string>('all');
  const [providerFilter, setProviderFilter] = useState<string>('all');

  // Restrict to standard voices only for users with limited packages
  const isLimitedTier = !cloudonixSettings?.cloudonix_package ||
    cloudonixSettings?.cloudonix_package === 'Free Tier' ||
    (typeof cloudonixSettings?.cloudonix_package === 'boolean' && cloudonixSettings.cloudonix_package === false);
  const [pricingFilter, setPricingFilter] = useState<'all' | 'standard' | 'premium'>(isLimitedTier ? 'standard' : 'all');

  const filteredVoices = voices.filter((voice: any) => {
    const matchesLanguage = languageFilter === 'all' || voice.language === languageFilter;
    const matchesGender = genderFilter === 'all' || voice.gender === genderFilter;
    const matchesProvider = providerFilter === 'all' || voice.provider === providerFilter;
    const matchesPricing = pricingFilter === 'all' || voice.pricing === pricingFilter;

    // Filter out premium voices for limited tier users
    const matchesTier = !isLimitedTier || voice.premium === false || voice.premium === null;

    return matchesLanguage && matchesGender && matchesProvider && matchesPricing && matchesTier;
  });

  return (
    <div className="space-y-3">
      {/* Voice selection */}
      <div className="space-y-1">
        <Label className="text-xs text-muted-foreground">Voice</Label>
        <div className="flex gap-2">
          <div className="flex-1">
            <Combobox
              options={filteredVoices.map((voice: any) => {
                const languageProviderPricing = `${getLanguageName(voice.language)} / ${voice.provider} / ${voice.pricing}`;
                return {
                  value: voice.id,
                  label: `${voice.name} - ${languageProviderPricing} - ${voice.gender}`,
                };
              })}
              value={value}
              onValueChange={onChange}
              placeholder="Choose a voice"
              searchPlaceholder="Search voices..."
              emptyText={filteredVoices.length === 0 ? "Loading voices... Please wait..." : "No voices found."}
            />
          </div>
          {onRefresh && (
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={onRefresh}
              className="h-8 px-3 shrink-0"
            >
              <RefreshCw className="h-4 w-4 mr-2" />
              Refresh
            </Button>
          )}
        </div>
      </div>

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
                      className={`text-xs ${gender === 'female'
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

        {/* Pricing toggle */}
        <div className="space-y-1">
          <Label className="text-xs text-muted-foreground">Voice Tiers</Label>
          <div className="flex items-center space-x-2">
            <Switch
              checked={pricingFilter === 'all'}
              onCheckedChange={(checked: boolean) => {
                if (isLimitedTier && !checked) return; // Prevent switching to standard only for limited tier
                setPricingFilter(checked ? 'all' : 'standard');
              }}
              disabled={isLimitedTier}
            />
            <Label className="text-sm font-normal">
              {pricingFilter === 'all' ? 'All Voices' : 'Standard Only'}
            </Label>
          </div>

        </div>
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
  const [currentPage, setCurrentPage] = useState(1);
  const [perPage] = useState(25);

  // Dialog states
  const [isCreateDialogOpen, setIsCreateDialogOpen] = useState(false);
  const [isEditDialogOpen, setIsEditDialogOpen] = useState(false);
  const [isDeleteDialogOpen, setIsDeleteDialogOpen] = useState(false);
  const [selectedMenu, setSelectedMenu] = useState<IvrMenu | null>(null);
  const [isMenuSettingsOpen, setIsMenuSettingsOpen] = useState(false);

  // Form data
  const [formData, setFormData] = useState<{
    name: string;
    description?: string;
    audio_file_path?: string;
    recording_id?: number;
    tts_text?: string;
    tts_voice?: string;
    useTTS: boolean;
    max_timeout: number;
    inter_digit_timeout: number;
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
    audio_file_path: '',
    recording_id: undefined,
    tts_text: '',
    tts_voice: undefined,
    useTTS: false,
    max_timeout: 1,
    inter_digit_timeout: 1,
    max_turns: 1,
    failover_destination_type: 'hangup' as IvrDestinationType,
    status: 'active' as IvrMenuStatus,
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

  const { data: aiAssistantsData, isLoading: aiAssistantsLoading, error: aiAssistantsError } = useQuery({
    queryKey: ['ivr-ai-assistants'],
    queryFn: () => extensionsService.getAll({ type: 'ai_assistant', status: 'active', per_page: 100 }),
  });

  const { data: aiLoadBalancersData, isLoading: aiLoadBalancersLoading, error: aiLoadBalancersError } = useQuery({
    queryKey: ['ivr-ai-load-balancers'],
    queryFn: () => aiAssistantLoadBalancersService.getAll({ status: 'active', per_page: 100 }),
  });

  // Helper to get display label for an extension (matches Ring Groups format)
  const getExtensionDisplayLabel = (ext: any) => {
    if (ext.type === 'forward') {
      const forwardTo = ext.configuration?.forward_to;
      return forwardTo ? `Forward to ${forwardTo}` : 'Forward Extension';
    }
    return ext.user?.name || 'Unassigned User';
  };

  // Combine all destinations
  const availableDestinations = {
    // Only show 'user' and 'forward' type extensions (matching Ring Groups behavior)
    extensions: extensionsData?.data
      ?.filter(ext => ext.type === 'user' || ext.type === 'forward')
      ?.map(ext => ({
        id: String(ext.id),
        extension_number: ext.extension_number,
        type: ext.type,
        label: `Ext ${ext.extension_number} - ${getExtensionDisplayLabel(ext)}`,
        displayLabel: getExtensionDisplayLabel(ext)
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
    })) || [],
    ai_assistants: aiAssistantsData?.data?.filter(ext => ext.type === 'ai_assistant')?.map(ext => ({
      id: String(ext.id),
      extension_number: ext.extension_number,
      label: `Ext ${ext.extension_number} - ${ext.ai_assistant?.name || 'AI Assistant'}`
    })) || [],
    ai_load_balancers: aiLoadBalancersData?.data?.map(alb => ({
      id: String(alb.id),
      label: `${alb.name}`
    })) || []
  };

  const destinationsLoading = extensionsLoading || ringGroupsLoading || conferenceRoomsLoading || ivrMenusLoading || aiAssistantsLoading || aiLoadBalancersLoading;
  const destinationsError = extensionsError || ringGroupsError || conferenceRoomsError || ivrMenusError || aiAssistantsError || aiLoadBalancersError;

  // Helper to render destination badge (matches Extensions page format)
  const renderDestinationBadge = (type: string, label: string, extType?: string) => {
    const configs: Record<string, { color: string; icon: any }> = {
      user: { color: 'bg-blue-100 text-blue-800 border-blue-200', icon: Phone },
      forward: { color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: ArrowRight },
      ring_group: { color: 'bg-orange-100 text-orange-800 border-orange-200', icon: Phone },
      conference: { color: 'bg-purple-100 text-purple-800 border-purple-200', icon: Users },
      ivr_menu: { color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
      ai_assistant: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
      ai_load_balancer: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Scale },
    };

    // For extensions, use the extension type; otherwise use the destination type
    const configKey = type === 'extension' && extType ? extType : type === 'conference_room' ? 'conference' : type;
    const config = configs[configKey] || configs.user;
    const Icon = config.icon;

    return (
      <div className="flex items-center gap-1.5">
        <Badge variant="outline" className={cn('flex items-center gap-1 px-1.5 py-0.5 text-xs', config.color)}>
          <Icon className="h-3 w-3" />
          {type === 'extension' && extType === 'user' && 'User'}
          {type === 'extension' && extType === 'forward' && 'Forward'}
          {type === 'ring_group' && 'Ring Group'}
          {type === 'conference_room' && 'Conference'}
          {type === 'ivr_menu' && 'IVR'}
          {type === 'ai_assistant' && 'AI Assistant'}
          {type === 'ai_load_balancer' && 'AI Load Balancer'}
        </Badge>
        <span className="text-sm">{label}</span>
      </div>
    );
  };

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

  const ivrMenus = (ivrMenusData?.data || []).map(menu => ({
    ...menu,
    id: menu.id
  }));
  const totalPages = ivrMenusData?.meta?.last_page || 1;

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

  const toggleStatusMutation = useMutation({
    mutationFn: ({ id, status }: { id: string; status: IvrMenuStatus }) =>
      api.patch(`/ivr-menus/${id}/toggle-status`, { status }),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['ivr-menus'], exact: false });
      queryClient.invalidateQueries({ queryKey: ['ivr-menus-list'] });
      toast.success('IVR menu status updated');
    },
    onError: (error: any) => {
      toast.error(error.response?.data?.message || 'Failed to update IVR menu status');
    },
  });

  // Handle status toggle
  const handleToggleStatus = (menu: IvrMenu & { id: string | number }) => {
    if (toggleStatusMutation.isPending) return; // Prevent multiple simultaneous toggles
    const newStatus = menu.status === 'active' ? 'inactive' : 'active';
    toggleStatusMutation.mutate({ id: String(menu.id), status: newStatus });
  };

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
      audio_file_path: '',
      recording_id: undefined,
      tts_text: '',
      tts_voice: undefined,
      useTTS: false,
      max_timeout: 1,
      inter_digit_timeout: 1,
      max_turns: 1,
      failover_destination_type: 'hangup' as IvrDestinationType,
      status: 'active' as IvrMenuStatus,
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

    if (formData.audio_file_path && formData.audio_file_path.length > 500) {
      toast.error('Audio file path must be 500 characters or less');
      return;
    }

    const requestData: CreateIvrMenuRequest = {
      name: formData.name,
      audio_file_path: formData.useTTS ? undefined : (formData.recording_id ? undefined : formData.audio_file_path),
      recording_id: formData.useTTS ? undefined : formData.recording_id,
      tts_text: formData.useTTS ? formData.tts_text : undefined,
      tts_voice: formData.useTTS ? formData.tts_voice : undefined,
      max_timeout: formData.max_timeout,
      inter_digit_timeout: formData.inter_digit_timeout,
      max_turns: formData.max_turns,
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

    if (formData.audio_file_path && formData.audio_file_path.length > 500) {
      toast.error('Audio file path must be 500 characters or less');
      return;
    }

    const requestData: UpdateIvrMenuRequest = {
      name: formData.name,
      audio_file_path: formData.useTTS ? undefined : (formData.recording_id ? undefined : formData.audio_file_path),
      recording_id: formData.useTTS ? undefined : formData.recording_id,
      tts_text: formData.useTTS ? formData.tts_text : undefined,
      tts_voice: formData.useTTS ? formData.tts_voice : undefined,
      max_timeout: formData.max_timeout,
      inter_digit_timeout: formData.inter_digit_timeout,
      max_turns: formData.max_turns,
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
    // For extensions, destination_id is the extension number (string)
    // For other types, destination_id is the model ID (converted to number)
    const processedValue = field === 'destination_id' && value !== '' &&
      updatedOptions[index].destination_type !== 'extension' ? parseInt(value, 10) : value;
    updatedOptions[index] = { ...updatedOptions[index], [field]: processedValue };
    setFormData({ ...formData, options: updatedOptions });
  };

  // Open edit dialog
  const openEditDialog = (menu: IvrMenu) => {
    setSelectedMenu(menu);
    setFormData({
      name: menu.name,
      audio_file_path: menu.audio_file_path,
      recording_id: undefined,
      tts_text: menu.tts_text,
      tts_voice: menu.tts_voice,
      useTTS: !!menu.tts_text,
      max_timeout: menu.max_timeout,
      inter_digit_timeout: menu.inter_digit_timeout,
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

      <Card>
        <CardContent className="pt-6">
          <StandardDataTable<IvrMenu & { id: string | number }>
            data={ivrMenus as (IvrMenu & { id: string | number })[]}
            isLoading={isLoading}
            onRowClick={openEditDialog}
            identityIcon={Phone}
            identityIconBg="bg-blue-100"
            identityIconColor="text-blue-600"
            getIdentityPrimary={(menu) => menu.name}
            getIdentitySecondary={(menu) => menu.description || 'No description'}
            onIdentityClick={openEditDialog}
            sortField={sortField}
            sortDirection={sortDirection}
            onSort={toggleSort}
            onView={openEditDialog}
            onEdit={openEditDialog}
            onDelete={openDeleteDialog}
            columns={[
              {
                header: 'Options',
                cell: (menu) => (
                  <Badge variant="secondary" className="font-mono">
                    {(menu as any).options_count || menu.options?.length || 0} items
                  </Badge>
                )
              },
              {
                header: 'Max Turns',
                accessorKey: 'max_turns' as any,
              },
              {
                header: 'Created',
                accessorKey: 'created_at' as any,
                cell: (menu) => new Date(menu.created_at).toLocaleDateString()
              },
              {
                header: 'Status',
                accessorKey: 'status' as any,
                cell: (menu) => (
                  <Badge
                    className={cn(
                      'capitalize transition-all',
                      toggleStatusMutation.isPending && toggleStatusMutation.variables?.id === String(menu.id)
                        ? 'opacity-50 cursor-wait'
                        : 'cursor-pointer hover:scale-105',
                      menu.status === 'active'
                        ? 'bg-green-100 text-green-800 border-green-200 hover:bg-green-200'
                        : 'bg-gray-100 text-gray-800 border-gray-200 hover:bg-gray-200'
                    )}
                    variant="outline"
                    onClick={(e) => {
                      e.stopPropagation();
                      if (!toggleStatusMutation.isPending) {
                        handleToggleStatus(menu);
                      }
                    }}
                  >
                    {toggleStatusMutation.isPending && toggleStatusMutation.variables?.id === String(menu.id) ? (
                      <span className="flex items-center gap-1">
                        <RefreshCw className="h-3 w-3 animate-spin" />
                        {menu.status}
                      </span>
                    ) : (
                      menu.status
                    )}
                  </Badge>
                )
              }
            ]}
            emptyState={
              <EmptyState
                icon={Phone}
                title="No IVR menus found"
                description={searchQuery || statusFilter !== 'all' ? 'Try adjusting your filters' : 'Create your first IVR menu to get started'}
                action={!searchQuery && statusFilter === 'all' ? {
                  label: 'Create IVR Menu',
                  onClick: () => setIsCreateDialogOpen(true)
                } : undefined}
              />
            }
          />

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-between mt-4">
              <div className="text-sm text-muted-foreground">
                Page {currentPage} of {totalPages}
              </div>
              <div className="flex gap-2">
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.max(1, p - 1))}
                  disabled={currentPage === 1}
                >
                  Previous
                </Button>
                <Button
                  variant="outline"
                  size="sm"
                  onClick={() => setCurrentPage((p) => Math.min(totalPages, p + 1))}
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
      < Dialog
        open={isCreateDialogOpen}
        onOpenChange={(open) => {
          setIsCreateDialogOpen(open);
          if (open) {
            resetForm();
          }
        }
        }
      >
        <DialogContent className="max-w-6xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Create IVR Menu</DialogTitle>
            <DialogDescription>
              Configure a new interactive voice response menu
            </DialogDescription>
          </DialogHeader>

          {/* Name field above tabs */}
          <div className="mb-6">
            <div className="space-y-2">
              <Label htmlFor="name">Name *</Label>
              <Input
                id="name"
                value={formData.name || ''}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="e.g., Main Menu"
              />
            </div>
          </div>

          <Tabs defaultValue="audio" className="w-full">
            <TabsList className="grid w-full grid-cols-2">
              <TabsTrigger value="audio">Audio</TabsTrigger>
              <TabsTrigger value="options">Menu Options</TabsTrigger>
            </TabsList>

            {/* Debug: Show current form data */}
            {/* <div className="mb-4 p-2 bg-gray-100 text-xs">
              <strong>Debug - Form Data:</strong>
              <pre>{JSON.stringify(formData, null, 2)}</pre>
            </div> */}

            <TabsContent value="audio" className="space-y-4">
              <div className="space-y-4">

                <div className="space-y-2">
                  <Label htmlFor="audio-resource">Audio Resource</Label>
                  <Select
                    value={formData.useTTS ? 'text-to-speech' : 'audio-file'}
                    onValueChange={(value) => {
                      if (value === 'text-to-speech') {
                        setFormData({ ...formData, useTTS: true, audio_file_path: '', tts_text: formData.tts_text || '' });
                      } else {
                        setFormData({ ...formData, useTTS: false, tts_text: '', audio_file_path: formData.audio_file_path || '' });
                      }
                    }}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="audio-file">Audio File</SelectItem>
                      <SelectItem value="text-to-speech">Text-to-Speech</SelectItem>
                    </SelectContent>
                  </Select>
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
                          onChange={(e: React.ChangeEvent<HTMLInputElement>) => setFormData({ ...formData, audio_file_path: e.target.value })}
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
                      value={formData.tts_voice}
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
                          <div className="grid grid-cols-12 gap-4 items-center">
                            <div className="col-span-1">
                              <Label>Digits *</Label>
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
                                <SelectItem value="ai_assistant">AI Assistant</SelectItem>
                                  <SelectItem value="ai_load_balancer">AI Load Balancer</SelectItem>
                                </SelectContent>
                              </Select>
                            </div>
                            <div className="col-span-8">
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
                                        <SelectItem key={ext.id} value={ext.extension_number}>
                                          {renderDestinationBadge('extension', `Ext ${ext.extension_number} - ${ext.displayLabel}`, ext.type)}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ring_group' && availableDestinations?.ring_groups?.map((rg) => (
                                        <SelectItem key={rg.id} value={rg.id}>
                                          {renderDestinationBadge('ring_group', rg.label.replace('Ring Group: ', ''))}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'conference_room' && availableDestinations?.conference_rooms?.map((cr) => (
                                        <SelectItem key={cr.id} value={cr.id}>
                                          {renderDestinationBadge('conference_room', cr.label.replace('Conference: ', ''))}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ivr_menu' && availableDestinations?.ivr_menus?.map((menu) => (
                                        <SelectItem key={menu.id} value={menu.id}>
                                          {renderDestinationBadge('ivr_menu', menu.label.replace('IVR Menu: ', ''))}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ai_assistant' && availableDestinations?.ai_assistants?.map((assistant) => (
                                        <SelectItem key={assistant.id} value={assistant.extension_number}>
                                          {renderDestinationBadge('ai_assistant', assistant.label)}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ai_load_balancer' && availableDestinations?.ai_load_balancers?.map((alb) => (
                                        <SelectItem key={alb.id} value={alb.id}>
                                          {renderDestinationBadge('ai_load_balancer', alb.label)}
                                        </SelectItem>
                                      ))}
                                      {(() => {
                                        const hasOptions = option.destination_type === 'extension' && availableDestinations?.extensions?.length > 0 ||
                                          option.destination_type === 'ring_group' && availableDestinations?.ring_groups?.length > 0 ||
                                          option.destination_type === 'conference_room' && availableDestinations?.conference_rooms?.length > 0 ||
                                          option.destination_type === 'ivr_menu' && availableDestinations?.ivr_menus?.length > 0 ||
                                          option.destination_type === 'ai_assistant' && availableDestinations?.ai_assistants?.length > 0 ||
                                          option.destination_type === 'ai_load_balancer' && availableDestinations?.ai_load_balancers?.length > 0;

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
                            <div className="col-span-1 flex items-center justify-center">
                              <button
                                type="button"
                                onClick={() => removeMenuOption(index)}
                                className="text-muted-foreground hover:text-destructive transition-colors"
                                aria-label="Delete option"
                              >
                                <Trash2 className="h-4 w-4" />
                              </button>
                            </div>
                          </div>
                        </CardContent>
                      </Card>
                    ))}
                  </div>
                )}

                {/* Menu Settings - Collapsible */}
                <Collapsible open={isMenuSettingsOpen} onOpenChange={setIsMenuSettingsOpen}>
                  <CollapsibleTrigger asChild>
                    <Button variant="outline" className="flex w-full justify-between p-4 font-medium">
                      Menu Settings
                      <ChevronDown className={`h-4 w-4 transition-transform duration-200 ${isMenuSettingsOpen ? 'rotate-180' : ''}`} />
                    </Button>
                  </CollapsibleTrigger>
                  <CollapsibleContent className="space-y-4 pt-4">
                    <div className="grid grid-cols-2 gap-4">
                      <div className="space-y-2">
                        <Label htmlFor="max-timeout">Max Timeout (seconds)</Label>
                        <Select
                          value={String(formData.max_timeout || 3)}
                          onValueChange={(value) => setFormData({ ...formData, max_timeout: parseInt(value) })}
                        >
                          <SelectTrigger>
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => (
                              <SelectItem key={num} value={String(num)}>
                                {num}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                          How long to wait for the user to first input speech or DTMF
                        </p>
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="inter-digit-timeout">Inter-digit Timeout (seconds)</Label>
                        <Select
                          value={String(formData.inter_digit_timeout || 2)}
                          onValueChange={(value) => setFormData({ ...formData, inter_digit_timeout: parseInt(value) })}
                        >
                          <SelectTrigger>
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => (
                              <SelectItem key={num} value={String(num)}>
                                {num}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                          How long to wait between DTMF digits
                        </p>
                      </div>

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
                          <SelectItem value="ai_assistant">AI Assistant</SelectItem>
                                  <SelectItem value="ai_load_balancer">AI Load Balancer</SelectItem>
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
                                  {renderDestinationBadge('extension', `Ext ${ext.extension_number} - ${ext.displayLabel}`, ext.type)}
                                </SelectItem>
                              ))}
                            {formData.failover_destination_type === 'ring_group' &&
                              availableDestinations?.ring_groups?.map((rg) => (
                                <SelectItem key={rg.id} value={rg.id}>
                                  {renderDestinationBadge('ring_group', rg.label.replace('Ring Group: ', ''))}
                                </SelectItem>
                              ))}
                            {formData.failover_destination_type === 'conference_room' &&
                              availableDestinations?.conference_rooms?.map((cr) => (
                                <SelectItem key={cr.id} value={cr.id}>
                                  {renderDestinationBadge('conference_room', cr.label.replace('Conference: ', ''))}
                                </SelectItem>
                              ))}
                            {formData.failover_destination_type === 'ivr_menu' &&
                              availableDestinations?.ivr_menus?.map((menu) => (
                                <SelectItem key={menu.id} value={menu.id}>
                                  {renderDestinationBadge('ivr_menu', menu.label.replace('IVR Menu: ', ''))}
                                </SelectItem>
                              ))}
                          </SelectContent>
                        </Select>
                      </div>
                    )}
                  </CollapsibleContent>
                </Collapsible>
              </div>
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
      </Dialog >

      {/* Edit Dialog - Full tabbed interface */}
      < Dialog open={isEditDialogOpen} onOpenChange={setIsEditDialogOpen} >
        <DialogContent className="max-w-6xl max-h-[90vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>Edit IVR Menu</DialogTitle>
            <DialogDescription>
              Update the IVR menu configuration
            </DialogDescription>
          </DialogHeader>

          {/* Name field above tabs */}
          <div className="mb-6">
            <div className="space-y-2">
              <Label htmlFor="edit-name">Name *</Label>
              <Input
                id="edit-name"
                value={formData.name || ''}
                onChange={(e) => setFormData({ ...formData, name: e.target.value })}
                placeholder="e.g., Main Menu"
              />
            </div>
          </div>

          <Tabs defaultValue="audio" className="w-full">
            <TabsList className="grid w-full grid-cols-2">
              <TabsTrigger value="audio">Audio</TabsTrigger>
              <TabsTrigger value="options">Menu Options</TabsTrigger>
            </TabsList>

            <TabsContent value="audio" className="space-y-4">
              <div className="space-y-4">

                <div className="space-y-2">
                  <Label htmlFor="edit-audio-resource">Audio Resource</Label>
                  <Select
                    value={formData.useTTS ? 'text-to-speech' : 'audio-file'}
                    onValueChange={(value) => {
                      if (value === 'text-to-speech') {
                        setFormData({ ...formData, useTTS: true, audio_file_path: '', tts_text: formData.tts_text || '' });
                      } else {
                        setFormData({ ...formData, useTTS: false, tts_text: '', audio_file_path: formData.audio_file_path || '' });
                      }
                    }}
                  >
                    <SelectTrigger>
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="audio-file">Audio File</SelectItem>
                      <SelectItem value="text-to-speech">Text-to-Speech</SelectItem>
                    </SelectContent>
                  </Select>
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
                          <div className="grid grid-cols-12 gap-4 items-center">
                            <div className="col-span-1">
                              <Label>Digits *</Label>
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
                                <SelectItem value="ai_assistant">AI Assistant</SelectItem>
                                  <SelectItem value="ai_load_balancer">AI Load Balancer</SelectItem>
                                </SelectContent>
                              </Select>
                            </div>
                            <div className="col-span-8">
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
                                        <SelectItem key={ext.id} value={ext.extension_number}>
                                          {renderDestinationBadge('extension', `Ext ${ext.extension_number} - ${ext.displayLabel}`, ext.type)}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ring_group' && availableDestinations?.ring_groups?.map((rg) => (
                                        <SelectItem key={rg.id} value={rg.id}>
                                          {renderDestinationBadge('ring_group', rg.label.replace('Ring Group: ', ''))}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'conference_room' && availableDestinations?.conference_rooms?.map((cr) => (
                                        <SelectItem key={cr.id} value={cr.id}>
                                          {renderDestinationBadge('conference_room', cr.label.replace('Conference: ', ''))}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ivr_menu' && availableDestinations?.ivr_menus?.map((menu) => (
                                        <SelectItem key={menu.id} value={menu.id}>
                                          {renderDestinationBadge('ivr_menu', menu.label.replace('IVR Menu: ', ''))}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ai_assistant' && availableDestinations?.ai_assistants?.map((assistant) => (
                                        <SelectItem key={assistant.id} value={assistant.extension_number}>
                                          {renderDestinationBadge('ai_assistant', assistant.label)}
                                        </SelectItem>
                                      ))}
                                      {option.destination_type === 'ai_load_balancer' && availableDestinations?.ai_load_balancers?.map((alb) => (
                                        <SelectItem key={alb.id} value={alb.id}>
                                          {renderDestinationBadge('ai_load_balancer', alb.label)}
                                        </SelectItem>
                                      ))}
                                      {(() => {
                                        const hasOptions = option.destination_type === 'extension' && availableDestinations?.extensions?.length > 0 ||
                                          option.destination_type === 'ring_group' && availableDestinations?.ring_groups?.length > 0 ||
                                          option.destination_type === 'conference_room' && availableDestinations?.conference_rooms?.length > 0 ||
                                          option.destination_type === 'ivr_menu' && availableDestinations?.ivr_menus?.length > 0 ||
                                          option.destination_type === 'ai_assistant' && availableDestinations?.ai_assistants?.length > 0 ||
                                          option.destination_type === 'ai_load_balancer' && availableDestinations?.ai_load_balancers?.length > 0;

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
                            <div className="col-span-1 flex items-center justify-center">
                              <button
                                type="button"
                                onClick={() => removeMenuOption(index)}
                                className="text-muted-foreground hover:text-destructive transition-colors"
                                aria-label="Delete option"
                              >
                                <Trash2 className="h-4 w-4" />
                              </button>
                            </div>
                          </div>
                        </CardContent>
                      </Card>
                    ))}
                  </div>
                )}

                {/* Menu Settings - Collapsible */}
                <Collapsible open={isMenuSettingsOpen} onOpenChange={setIsMenuSettingsOpen}>
                  <CollapsibleTrigger asChild>
                    <Button variant="outline" className="flex w-full justify-between p-4 font-medium">
                      Menu Settings
                      <ChevronDown className={`h-4 w-4 transition-transform duration-200 ${isMenuSettingsOpen ? 'rotate-180' : ''}`} />
                    </Button>
                  </CollapsibleTrigger>
                  <CollapsibleContent className="space-y-4 pt-4">
                    <div className="grid grid-cols-2 gap-4">
                      <div className="space-y-2">
                        <Label htmlFor="edit-max-timeout">Max Timeout (seconds)</Label>
                        <Select
                          value={String(formData.max_timeout || 3)}
                          onValueChange={(value) => setFormData({ ...formData, max_timeout: parseInt(value) })}
                        >
                          <SelectTrigger>
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => (
                              <SelectItem key={num} value={String(num)}>
                                {num}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                          How long to wait for the user to first input speech or DTMF
                        </p>
                      </div>

                      <div className="space-y-2">
                        <Label htmlFor="edit-inter-digit-timeout">Inter-digit Timeout (seconds)</Label>
                        <Select
                          value={String(formData.inter_digit_timeout || 2)}
                          onValueChange={(value) => setFormData({ ...formData, inter_digit_timeout: parseInt(value) })}
                        >
                          <SelectTrigger>
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => (
                              <SelectItem key={num} value={String(num)}>
                                {num}
                              </SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                        <p className="text-sm text-muted-foreground">
                          How long to wait between DTMF digits
                        </p>
                      </div>

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
                          <SelectItem value="ai_assistant">AI Assistant</SelectItem>
                                  <SelectItem value="ai_load_balancer">AI Load Balancer</SelectItem>
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
                                  {renderDestinationBadge('extension', `Ext ${ext.extension_number} - ${ext.displayLabel}`, ext.type)}
                                </SelectItem>
                              ))}
                            {formData.failover_destination_type === 'ring_group' &&
                              availableDestinations?.ring_groups?.map((rg) => (
                                <SelectItem key={rg.id} value={rg.id}>
                                  {renderDestinationBadge('ring_group', rg.label.replace('Ring Group: ', ''))}
                                </SelectItem>
                              ))}
                            {formData.failover_destination_type === 'conference_room' &&
                              availableDestinations?.conference_rooms?.map((cr) => (
                                <SelectItem key={cr.id} value={cr.id}>
                                  {renderDestinationBadge('conference_room', cr.label.replace('Conference: ', ''))}
                                </SelectItem>
                              ))}
                            {formData.failover_destination_type === 'ivr_menu' &&
                              availableDestinations?.ivr_menus?.map((menu) => (
                                <SelectItem key={menu.id} value={menu.id}>
                                  {renderDestinationBadge('ivr_menu', menu.label.replace('IVR Menu: ', ''))}
                                </SelectItem>
                              ))}
                          </SelectContent>
                        </Select>
                      </div>
                    )}
                  </CollapsibleContent>
                </Collapsible>
              </div>
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
      </Dialog >

      {/* Delete Dialog */}
      < Dialog open={isDeleteDialogOpen} onOpenChange={setIsDeleteDialogOpen} >
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
      </Dialog >
    </div >
  );
}