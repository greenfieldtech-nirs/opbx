/**
 * Shared IVR menu form body (name + Audio / Menu Options tabs) used by the
 * Create and Edit dialogs on the IVR Menus page.
 */

import { useState } from 'react';
import { IVR_LIMITS } from '@/schemas/ivrMenu';
import type {
  IvrMenuStatus,
  IvrDestinationType,
  IvrFailoverDestinationType,
} from '@/types/api.types';
import type {
  CloudonixSettings,
  CloudonixVoice,
  CloudonixVoiceFilters,
  CloudonixVoiceLanguage,
  Recording,
} from '@/types';
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
import { Plus, Phone, Trash2, RefreshCw, ChevronDown } from 'lucide-react';

export interface IvrMenuFormData {
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
  failover_destination_type: IvrFailoverDestinationType;
  failover_destination_id?: string;
  status: IvrMenuStatus;
  options: Array<{
    input_digits: string;
    description?: string;
    destination_type: IvrDestinationType;
    destination_id: string;
  }>;
}

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
  formData: IvrMenuFormData;
  setFormData: (data: IvrMenuFormData) => void;
  /** True when the edited menu's audio is a recording URL. */
  isRecordingUrl?: boolean;
  recordings?: Recording[];
  voices: CloudonixVoice[];
  voiceFilters: CloudonixVoiceFilters;
  onRefreshVoices: () => void;
  cloudonixSettings?: CloudonixSettings;
}

export function IvrMenuForm({
  mode,
  formData,
  setFormData,
  isRecordingUrl,
  recordings,
  voices,
  voiceFilters: filters,
  onRefreshVoices,
  cloudonixSettings,
}: IvrMenuFormProps) {
  const isEdit = mode === 'edit';
  const [isMenuSettingsOpen, setIsMenuSettingsOpen] = useState(false);

  const addMenuOption = () => {
    // Next digit = highest existing numeric digit + 1
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

  const removeMenuOption = (index: number) => {
    setFormData({
      ...formData,
      options: (formData.options || []).filter((_, i) => i !== index),
    });
  };

  const updateMenuOption = (index: number, field: keyof IvrMenuFormData['options'][0], value: string | number | boolean) => {
    const updatedOptions = [...formData.options];
    updatedOptions[index] = { ...updatedOptions[index], [field]: value };
    setFormData({ ...formData, options: updatedOptions });
  };

  return (
    <>
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
                  value={formData.recording_id ? 'recording' : (formData.audio_file_path?.startsWith('http') ? 'remote' : 'recording')}
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

              {isRecordingUrl || (!formData.audio_file_path?.startsWith('http') && !formData.recording_id) ? (
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
                </div>
              ) : (
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
              )}
            </div>
          ) : (
            <div className="space-y-4">
              <VoiceSelector
                value={isEdit ? (formData.tts_voice || 'en-US-Neural2-A') : formData.tts_voice}
                onChange={(value) => setFormData({ ...formData, tts_voice: value })}
                voices={voices}
                filters={filters}
                onRefresh={onRefreshVoices}
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
                  maxLength={IVR_LIMITS.ttsText}
                />
                <p className="text-sm text-muted-foreground">
                  {(formData.tts_text || '').length}/{IVR_LIMITS.ttsText} characters. Use SSML tags for advanced formatting.
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
                <Card key={index} className="w-full">
                  <CardContent className="p-4">
                    <div className="flex flex-col md:flex-row gap-6 items-start md:items-end w-full">
                      <div className="w-full md:w-20 shrink-0 space-y-2">
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
                      <div className="flex-1 min-w-0 w-full">
                        <DestinationTypeAndSelector
                          allowDisconnect={isEdit}
                          typeValue={option.destination_type}
                          destinationValue={option.destination_id}
                          onChange={(type, destId) => {
                            const updatedOptions = [...formData.options];
                            updatedOptions[index] = {
                              ...updatedOptions[index],
                              destination_type: type as IvrDestinationType,
                              destination_id: destId
                            };
                            setFormData({ ...formData, options: updatedOptions });
                          }}
                          layout="horizontal"
                          typeClassName="w-full md:w-[220px] flex-none"
                          destinationClassName="flex-1 min-w-0"
                          allowedTypes={['extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'business_hours', 'call_queue']}
                          extensionTypes={option.destination_type === 'ai_assistant' ? ['ai_assistant'] : ['user', 'forward']}
                          typeLabel="Type"
                          destinationLabel="Destination"
                        />
                      </div>
                      <div className="w-full md:w-10 shrink-0">
                        <button
                          type="button"
                          onClick={() => removeMenuOption(index)}
                          className="h-10 w-full flex items-center justify-center text-muted-foreground hover:text-destructive transition-colors border rounded-md"
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
              </div>

              <DestinationTypeAndSelector
                allowDisconnect={isEdit}
                typeValue={formData.failover_destination_type}
                destinationValue={formData.failover_destination_id || ''}
                onChange={(type, destId) => {
                  setFormData({
                    ...formData,
                    failover_destination_type: type as IvrFailoverDestinationType,
                    failover_destination_id: destId || undefined
                  });
                }}
                layout="vertical"
                includeHangup={true}
                allowedTypes={['extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant', 'ai_load_balancer', 'business_hours', 'call_queue']}
                extensionTypes={formData.failover_destination_type === 'ai_assistant' ? ['ai_assistant'] : ['user', 'forward']}
                typeLabel="Failover Action"
                destinationLabel="Failover Destination"
              />
            </CollapsibleContent>
          </Collapsible>
        </div>
      </TabsContent>
    </Tabs>
    </>
  );
}
