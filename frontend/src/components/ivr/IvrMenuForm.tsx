/**
 * Shared IVR menu form body (name + Audio / Menu Options tabs) used by the
 * Create and Edit dialogs on the IVR Menus page.
 */

import { useState } from 'react';
import { useFieldArray, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import {
  ivrMenuFormSchema,
  IVR_LIMITS,
  type IvrMenuFormValues,
} from '@/schemas/ivrMenu';
import type { CreateIvrMenuRequest, IvrDestinationType, IvrMenu } from '@/types/api.types';
import type {
  CloudonixSettings,
  CloudonixVoice,
  CloudonixVoiceFilters,
  CloudonixVoiceLanguage,
  Recording,
} from '@/types';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
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
import { Badge } from '@/components/ui/badge';
import { Switch } from '@/components/ui/switch';
import { DestinationTypeAndSelector } from '@/components/destinations';
import { Combobox } from '@/components/ui/combobox';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from '@/components/ui/form';
import { DialogFooter } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { Plus, Phone, Trash2, RefreshCw, ChevronDown } from 'lucide-react';

// Voice selector component with search and advanced filters
const VoiceSelector: React.FC<{
  value: string;
  onChange: (voiceId: string) => void;
  voices: CloudonixVoice[];
  filters: CloudonixVoiceFilters;
  onRefresh?: () => void;
  cloudonixSettings?: CloudonixSettings;
}> = ({ value, onChange, voices, filters, onRefresh, cloudonixSettings }) => {
  // Helper function to get language name from code
  const getLanguageName = (languageCode: string): string => {
    const language = filters?.languages?.find((lang: CloudonixVoiceLanguage) => lang.code === languageCode);
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

  const filteredVoices = voices.filter((voice: CloudonixVoice) => {
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
              options={filteredVoices.map((voice: CloudonixVoice) => {
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
              {filters?.languages?.map((lang: CloudonixVoiceLanguage) => (
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

export interface IvrMenuFormProps {
  /** Edit mode offers "Disconnect" on destination pickers and keeps the stored default voice shown. */
  mode: 'create' | 'edit';
  defaultValues: IvrMenuFormValues;
  onSubmit: (payload: CreateIvrMenuRequest) => void;
  onCancel: () => void;
  isPending?: boolean;
  submitLabel: string;
  /** True when the edited menu's audio is a recording URL. */
  isRecordingUrl?: boolean;
  recordings?: Recording[];
  voices: CloudonixVoice[];
  voiceFilters: CloudonixVoiceFilters;
  onRefreshVoices: () => void;
  cloudonixSettings?: CloudonixSettings;
}

export const EMPTY_IVR_FORM: IvrMenuFormValues = {
  name: '',
  useTTS: false,
  audio_file_path: '',
  recording_id: undefined,
  tts_text: '',
  tts_voice: undefined,
  max_timeout: 1,
  inter_digit_timeout: 1,
  max_turns: 1,
  failover_destination_type: 'hangup',
  failover_destination_id: undefined,
  status: 'active',
  options: [],
};

export const ivrMenuToFormValues = (menu: IvrMenu): IvrMenuFormValues => ({
  name: menu.name,
  useTTS: !!menu.tts_text,
  audio_file_path: (menu.recording_id ? '' : menu.audio_file_path) ?? '',
  recording_id: menu.recording_id ?? undefined,
  tts_text: menu.tts_text ?? '',
  tts_voice: menu.tts_voice ?? undefined,
  max_timeout: menu.max_timeout,
  inter_digit_timeout: menu.inter_digit_timeout,
  max_turns: menu.max_turns,
  failover_destination_type: menu.failover_destination_type,
  failover_destination_id: menu.failover_destination_id ? String(menu.failover_destination_id) : undefined,
  status: menu.status,
  options: (menu.options || []).map((o) => ({
    input_digits: o.input_digits,
    description: o.description ?? '',
    destination_type: o.destination_type,
    destination_id: String(o.destination_id),
  })),
});

const OPTION_TYPES = ['extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'business_hours', 'call_queue'] as const;

export function IvrMenuForm({
  mode,
  defaultValues,
  onSubmit,
  onCancel,
  isPending,
  submitLabel,
  isRecordingUrl,
  recordings,
  voices,
  voiceFilters: filters,
  onRefreshVoices,
  cloudonixSettings,
}: IvrMenuFormProps) {
  const isEdit = mode === 'edit';
  const [isMenuSettingsOpen, setIsMenuSettingsOpen] = useState(false);
  const [tab, setTab] = useState('audio');

  const form = useForm<IvrMenuFormValues>({
    // The schema validates the API payload derived from the form values, so
    // handleSubmit receives that payload (not form values): see submit below.
    resolver: zodResolver(ivrMenuFormSchema) as never,
    defaultValues,
    mode: 'onChange',
  });
  const { control, setValue, trigger, watch, formState: { errors } } = form;
  const { fields, append, remove } = useFieldArray({ control, name: 'options' });

  const useTTS = watch('useTTS');
  const audioFilePath = watch('audio_file_path') ?? '';
  const recordingId = watch('recording_id');
  const ttsText = watch('tts_text') ?? '';
  const options = watch('options');
  const failoverType = watch('failover_destination_type');

  // Audio source errors land on tts_text (see ivrMenuSchema); re-check it when any audio field changes.
  const setAudio = (
    name: 'audio_file_path' | 'recording_id' | 'tts_text' | 'tts_voice' | 'useTTS',
    value: string | number | boolean | undefined,
  ) => {
    setValue(name, value as never, { shouldDirty: true, shouldValidate: true });
    void trigger('tts_text');
  };

  const audioError = errors.tts_text?.message;
  const optionsError = errors.options?.root?.message ?? errors.options?.message;
  const audioTabInvalid = !!(errors.tts_text || errors.audio_file_path || errors.recording_id || errors.tts_voice);
  const optionsTabInvalid = !!(errors.options || errors.failover_destination_id || errors.max_timeout || errors.inter_digit_timeout || errors.max_turns);

  const addMenuOption = () => {
    // Next digit = highest existing numeric digit + 1
    const existing = (options ?? []).map((o) => parseInt(o.input_digits)).filter((d) => !isNaN(d));
    append({
      input_digits: String(existing.length > 0 ? Math.max(...existing) + 1 : 1),
      description: '',
      destination_type: 'extension' as IvrDestinationType,
      destination_id: '',
    });
  };

  // Jump to the first tab that holds an error (the errors argument is fresh; the render-time ones are not).
  const onInvalid = (e: typeof errors) => {
    if (e.tts_text || e.audio_file_path || e.recording_id || e.tts_voice) setTab('audio');
    else if (e.options || e.failover_destination_id || e.max_timeout || e.inter_digit_timeout || e.max_turns) setTab('options');
    // Menu Settings is collapsed by default; open it so its errors are visible.
    if (e.failover_destination_id || e.max_timeout || e.inter_digit_timeout || e.max_turns) setIsMenuSettingsOpen(true);
  };

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit((parsed) => onSubmit(parsed as unknown as CreateIvrMenuRequest), onInvalid)} noValidate>
        <FormField
          control={control}
          name="name"
          render={({ field }) => (
            <FormItem className="mb-6">
              <FormLabel>Name *</FormLabel>
              <FormControl>
                <Input {...field} placeholder="e.g., Main Menu" />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <Tabs value={tab} onValueChange={setTab} className="w-full">
          <TabsList className="grid w-full grid-cols-2">
            <TabsTrigger value="audio" className={cn(audioTabInvalid && 'text-destructive')}>
              Audio{audioTabInvalid && ' (!)'}
            </TabsTrigger>
            <TabsTrigger value="options" className={cn(optionsTabInvalid && 'text-destructive')}>
              Menu Options{optionsTabInvalid && ' (!)'}
            </TabsTrigger>
          </TabsList>

          <TabsContent value="audio" className="space-y-4">
            <div className="space-y-4">
              <div className="space-y-2">
                <Label htmlFor="audio-resource">Audio Resource</Label>
                <Select
                  value={useTTS ? 'text-to-speech' : 'audio-file'}
                  onValueChange={(value) => {
                    if (value === 'text-to-speech') {
                      setValue('audio_file_path', '');
                      setAudio('useTTS', true);
                    } else {
                      setValue('tts_text', '');
                      setAudio('useTTS', false);
                    }
                  }}
                >
                  <SelectTrigger id="audio-resource">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value="audio-file">Audio File</SelectItem>
                    <SelectItem value="text-to-speech">Text-to-Speech</SelectItem>
                  </SelectContent>
                </Select>
              </div>

              {!useTTS ? (
                <div className="space-y-4">
                  <div className="space-y-2">
                    <Label htmlFor="audio-source">Audio Source</Label>
                    <Select
                      value={recordingId ? 'recording' : (audioFilePath.startsWith('http') ? 'remote' : 'recording')}
                      onValueChange={(value) => {
                        setValue('recording_id', undefined);
                        setAudio('audio_file_path', value === 'remote' ? 'https://' : '');
                      }}
                    >
                      <SelectTrigger id="audio-source">
                        <SelectValue />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="recording">From Recordings</SelectItem>
                        <SelectItem value="remote">Remote URL</SelectItem>
                      </SelectContent>
                    </Select>
                  </div>

                  {isRecordingUrl || (!audioFilePath.startsWith('http') && !recordingId) ? (
                    <div className="space-y-2">
                      <Label htmlFor="recording-select">Select Recording</Label>
                      <Select
                        value={recordingId?.toString() || ''}
                        onValueChange={(value) => {
                          setValue('audio_file_path', '');
                          setAudio('recording_id', value ? parseInt(value) : undefined);
                        }}
                      >
                        <SelectTrigger id="recording-select">
                          <SelectValue placeholder="Choose a recording" />
                        </SelectTrigger>
                        <SelectContent>
                          {recordings?.map((recording: Recording) => (
                            <SelectItem key={recording.id} value={recording.id.toString()}>
                              {recording.name || `Recording ${recording.id}`}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                      <p className="text-sm text-muted-foreground">
                        Select from uploaded recordings or upload new ones in the Recordings page
                      </p>
                      {audioError && <p role="alert" className="text-sm font-medium text-destructive">{audioError}</p>}
                    </div>
                  ) : (
                    <FormField
                      control={control}
                      name="audio_file_path"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Remote Audio URL</FormLabel>
                          <FormControl>
                            <Input
                              {...field}
                              onChange={(e) => { field.onChange(e); void trigger('tts_text'); }}
                              placeholder="https://example.com/audio/welcome.mp3"
                            />
                          </FormControl>
                          <p className="text-sm text-muted-foreground">
                            Enter a full URL to an audio file (MP3, WAV, etc.) - {audioFilePath.length}/{IVR_LIMITS.audioFilePath} characters
                            {audioFilePath.length > 450 && (
                              <span className="text-amber-600 ml-2">Approaching limit</span>
                            )}
                          </p>
                          <FormMessage />
                          {audioError && <p role="alert" className="text-sm font-medium text-destructive">{audioError}</p>}
                        </FormItem>
                      )}
                    />
                  )}
                </div>
              ) : (
                <div className="space-y-4">
                  <FormField
                    control={control}
                    name="tts_voice"
                    render={({ field }) => (
                      <FormItem>
                        <VoiceSelector
                          value={isEdit ? (field.value || 'en-US-Neural2-A') : (field.value as string)}
                          onChange={field.onChange}
                          voices={voices}
                          filters={filters}
                          onRefresh={onRefreshVoices}
                          cloudonixSettings={cloudonixSettings}
                        />
                        <FormMessage />
                      </FormItem>
                    )}
                  />

                  <FormField
                    control={control}
                    name="tts_text"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>Text to Speak</FormLabel>
                        <FormControl>
                          <Textarea
                            {...field}
                            placeholder="Enter the text that will be converted to speech"
                            rows={4}
                          />
                        </FormControl>
                        <p className={cn('text-sm text-muted-foreground', ttsText.length > IVR_LIMITS.ttsText && 'text-destructive font-medium')}>
                          {ttsText.length}/{IVR_LIMITS.ttsText} characters. Use SSML tags for advanced formatting.
                        </p>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>
              )}
            </div>
          </TabsContent>

          <TabsContent value="options" className="space-y-4">
            <div className="space-y-4">
              <div className="flex justify-between items-center">
                <Label className="text-base font-medium">Menu Options</Label>
                <div className="flex items-center gap-2">
                  {fields.length >= IVR_LIMITS.options.max && (
                    <span className="text-sm text-amber-600">Maximum {IVR_LIMITS.options.max} options</span>
                  )}
                  <Button
                    type="button"
                    onClick={addMenuOption}
                    size="sm"
                    disabled={fields.length >= IVR_LIMITS.options.max}
                  >
                    <Plus className="h-4 w-4 mr-2" />
                    Add Option
                  </Button>
                </div>
              </div>
              {optionsError && <p role="alert" className="text-sm font-medium text-destructive">{optionsError}</p>}

              {fields.length === 0 ? (
                <div className="text-center py-8 text-muted-foreground">
                  <Phone className="h-12 w-12 mx-auto mb-4 opacity-50" />
                  <p>No menu options configured</p>
                  <p className="text-sm">Add options below to define how callers navigate your IVR menu</p>
                </div>
              ) : (
                // One bordered table, edited inline; one row per option.
                <div className="rounded-md border">
                  <Table>
                    <TableHeader>
                      <TableRow>
                        <TableHead className="w-24">Digits</TableHead>
                        <TableHead>Destination</TableHead>
                        <TableHead className="w-12"><span className="sr-only">Actions</span></TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {fields.map((item, index) => {
                        const option = options?.[index];
                        return (
                          <TableRow key={item.id} className="hover:bg-transparent">
                            <TableCell className="py-2 align-top">
                              <FormField
                                control={control}
                                name={`options.${index}.input_digits`}
                                render={({ field }) => (
                                  <FormItem className="space-y-1">
                                    <FormControl>
                                      <Input
                                        {...field}
                                        aria-label={`Option ${index + 1} digits`}
                                        onChange={(e) => {
                                          // Only digits, * and # are allowed
                                          if (/^[0-9*#]*$/.test(e.target.value)) field.onChange(e);
                                        }}
                                        placeholder="1"
                                        maxLength={IVR_LIMITS.inputDigits}
                                      />
                                    </FormControl>
                                    <FormMessage />
                                  </FormItem>
                                )}
                              />
                            </TableCell>
                            <TableCell className="py-2 align-top">
                              <DestinationTypeAndSelector
                                typeValue={option?.destination_type}
                                destinationValue={option?.destination_id ?? ''}
                                onChange={(type, destId) => {
                                  setValue(`options.${index}.destination_type`, type as IvrDestinationType, { shouldDirty: true });
                                  setValue(`options.${index}.destination_id`, destId, { shouldDirty: true, shouldValidate: true });
                                }}
                                layout="horizontal"
                                className="gap-2"
                                typeClassName="w-[260px] flex-none"
                                destinationClassName="flex-1 min-w-0"
                                allowedTypes={[...OPTION_TYPES]}
                                extensionTypes={option?.destination_type === 'ai_assistant' ? ['ai_assistant'] : ['user', 'forward']}
                                allowDisconnect={isEdit}
                                typeLabel=""
                                destinationLabel=""
                              />
                              <FormField
                                control={control}
                                name={`options.${index}.destination_id`}
                                render={() => <FormMessage className="mt-1" />}
                              />
                            </TableCell>
                            <TableCell className="py-2 align-top text-right">
                              <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                onClick={() => remove(index)}
                                className="text-muted-foreground hover:text-destructive"
                                aria-label={`Delete option ${index + 1}`}
                              >
                                <Trash2 className="h-4 w-4" />
                              </Button>
                            </TableCell>
                          </TableRow>
                        );
                      })}
                    </TableBody>
                  </Table>
                </div>
              )}

              {/* Menu Settings - Collapsible */}
              <Collapsible open={isMenuSettingsOpen} onOpenChange={setIsMenuSettingsOpen}>
                <CollapsibleTrigger asChild>
                  <Button type="button" variant="outline" className="flex w-full justify-between p-4 font-medium">
                    Menu Settings
                    <ChevronDown className={`h-4 w-4 transition-transform duration-200 ${isMenuSettingsOpen ? 'rotate-180' : ''}`} />
                  </Button>
                </CollapsibleTrigger>
                <CollapsibleContent className="space-y-4 pt-4">
                  <div className="grid grid-cols-2 gap-4">
                    <FormField
                      control={control}
                      name="max_timeout"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Max Timeout (seconds)</FormLabel>
                          <Select value={String(field.value || 3)} onValueChange={(v) => field.onChange(parseInt(v))}>
                            <FormControl>
                              <SelectTrigger><SelectValue /></SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => (
                                <SelectItem key={num} value={String(num)}>{num}</SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <p className="text-sm text-muted-foreground">
                            How long to wait for the user to first input speech or DTMF
                          </p>
                          <FormMessage />
                        </FormItem>
                      )}
                    />

                    <FormField
                      control={control}
                      name="inter_digit_timeout"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Inter-digit Timeout (seconds)</FormLabel>
                          <Select value={String(field.value || 2)} onValueChange={(v) => field.onChange(parseInt(v))}>
                            <FormControl>
                              <SelectTrigger><SelectValue /></SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              {Array.from({ length: 30 }, (_, i) => i + 1).map((num) => (
                                <SelectItem key={num} value={String(num)}>{num}</SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <p className="text-sm text-muted-foreground">
                            How long to wait between DTMF digits
                          </p>
                          <FormMessage />
                        </FormItem>
                      )}
                    />

                    <FormField
                      control={control}
                      name="max_turns"
                      render={({ field }) => (
                        <FormItem>
                          <FormLabel>Maximum Turns</FormLabel>
                          <Select value={String(field.value || 3)} onValueChange={(v) => field.onChange(parseInt(v))}>
                            <FormControl>
                              <SelectTrigger><SelectValue /></SelectTrigger>
                            </FormControl>
                            <SelectContent>
                              {[1, 2, 3, 4, 5, 6, 7, 8, 9].map((num) => (
                                <SelectItem key={num} value={String(num)}>{num}</SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                          <p className="text-sm text-muted-foreground">
                            How many times to replay the menu on invalid input
                          </p>
                          <FormMessage />
                        </FormItem>
                      )}
                    />
                  </div>

                  <DestinationTypeAndSelector
                    typeValue={failoverType}
                    destinationValue={watch('failover_destination_id') || ''}
                    onChange={(type, destId) => {
                      setValue('failover_destination_type', type as IvrMenuFormValues['failover_destination_type'], { shouldDirty: true });
                      setValue('failover_destination_id', destId || undefined, { shouldDirty: true, shouldValidate: true });
                    }}
                    layout="vertical"
                    includeHangup={true}
                    allowDisconnect={isEdit}
                    allowedTypes={[...OPTION_TYPES]}
                    extensionTypes={failoverType === 'ai_assistant' ? ['ai_assistant'] : ['user', 'forward']}
                    typeLabel="Failover Action"
                    destinationLabel="Failover Destination"
                  />
                  <FormField
                    control={control}
                    name="failover_destination_id"
                    render={() => <FormMessage />}
                  />
                </CollapsibleContent>
              </Collapsible>
            </div>
          </TabsContent>
        </Tabs>

        <DialogFooter className="mt-6">
          <Button type="button" variant="outline" onClick={onCancel}>
            Cancel
          </Button>
          <Button type="submit" disabled={isPending}>
            {submitLabel}
          </Button>
        </DialogFooter>
      </form>
    </Form>
  );
}
