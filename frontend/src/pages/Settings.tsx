/**
 * Settings Page
 *
 * Manages Cloudonix integration settings
 * Only accessible to organization owners
 */

import { useState, useEffect } from 'react';
import { useForm, Controller } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { toast } from 'sonner';
import { settingsService } from '@/services/settings.service';
import { getApiErrorMessage } from '@/services/api';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import {
  Settings as SettingsIcon,
  Globe,
  Key,
  RefreshCw,
  Clock,
  Mic,
  Link as LinkIcon,
  Copy,
  Eye,
  EyeOff,
  Loader2,
  CheckCircle2,
  AlertCircle,
  Info,
  XCircle,
  FileText,
} from 'lucide-react';
import type {
  CloudonixSettings,
  UpdateCloudonixSettingsRequest,
} from '@/types';

// Settings form validation schema
const settingsSchema = z.object({
  domain_uuid: z.string().uuid('Invalid UUID format').optional().or(z.literal('')),
  domain_name: z.string().optional().or(z.literal('')),
  domain_api_key: z.string().min(1, 'API Key is required').optional().or(z.literal('')),
  domain_requests_api_key: z.string().optional().or(z.literal('')),
  webhook_base_url: z.string().url('Invalid URL format').optional().or(z.literal('')),
  no_answer_timeout: z.number().min(5, 'Minimum 5 seconds').max(120, 'Maximum 120 seconds'),
  recording_format: z.enum(['wav', 'mp3']),
});

type SettingsFormData = z.infer<typeof settingsSchema>;

export default function Settings() {
  const [isLoading, setIsLoading] = useState(true);
  const [isValidating, setIsValidating] = useState(false);
  const [isGeneratingKey, setIsGeneratingKey] = useState(false);
  const [settingsData, setSettingsData] = useState<CloudonixSettings | null>(null);
  const [showDomainApiKey, setShowDomainApiKey] = useState(false);
  const [showRequestsApiKey, setShowRequestsApiKey] = useState(false);
  const [generatedKey, setGeneratedKey] = useState<string | null>(null);
  const [validationStatus, setValidationStatus] = useState<'valid' | 'invalid' | null>(null);

  // Settings form
  const {
    register,
    handleSubmit,
    formState: { errors },
    reset,
    control,
    watch,
    setValue,
  } = useForm<SettingsFormData>({
    resolver: zodResolver(settingsSchema),
    defaultValues: {
      domain_uuid: '',
      domain_name: '',
      domain_api_key: '',
      domain_requests_api_key: '',
      webhook_base_url: '',
      no_answer_timeout: 60,
      recording_format: 'mp3',
    },
  });

  const domainUuid = watch('domain_uuid');
  const domainApiKey = watch('domain_api_key');

  // Load settings on mount
  useEffect(() => {
    const loadSettings = async () => {
      try {
        const data = await settingsService.getCloudonixSettings();
        setSettingsData(data);

        // Reset form with loaded data
        reset({
          domain_uuid: data.domain_uuid || '',
          domain_name: data.domain_name || '',
          domain_api_key: data.domain_api_key || '',
          domain_requests_api_key: data.domain_requests_api_key || '',
          webhook_base_url: data.webhook_base_url || '',
          no_answer_timeout: data.no_answer_timeout,
          recording_format: data.recording_format,
        });

        // Note: validation status is handled by the validation effect below
      } catch (error) {
        toast.error('Failed to load settings');
        console.error('Settings load error:', error);
      } finally {
        setIsLoading(false);
      }
    };

    loadSettings();
  }, [reset]);

  // Update validation status when credentials change
  useEffect(() => {
    // Skip if no settings data loaded yet
    if (!settingsData) return;

    // If credentials are configured and loaded, show as valid initially
    const hasStoredCredentials = settingsData.domain_uuid && settingsData.domain_api_key;

    if (hasStoredCredentials && settingsData.is_configured) {
      setValidationStatus('valid');
    } else {
      setValidationStatus(null);
    }
  }, [settingsData]);

  /**
   * Validate credentials and save all settings
   */
  const handleValidateAndSave = async () => {
    if (!domainUuid || !domainApiKey) {
      toast.error('Please enter both Domain UUID and API Key');
      return;
    }

    setIsValidating(true);
    setValidationStatus(null);

    try {
      // Step 1: Validate credentials
      const result = await settingsService.validateCloudonixCredentials({
        domain_uuid: domainUuid,
        domain_api_key: domainApiKey,
      });

      if (result.valid) {
        setValidationStatus('valid');

        // Step 2: Populate form fields with Cloudonix profile settings if available
        const currentFormValues = watch();
        if (result.profile_settings) {
          if (result.profile_settings.domain_name !== undefined) {
            setValue('domain_name', result.profile_settings.domain_name);
            currentFormValues.domain_name = result.profile_settings.domain_name;
          }
          if (result.profile_settings.no_answer_timeout !== undefined) {
            setValue('no_answer_timeout', result.profile_settings.no_answer_timeout);
            currentFormValues.no_answer_timeout = result.profile_settings.no_answer_timeout;
          }
          if (result.profile_settings.recording_format !== undefined) {
            setValue('recording_format', result.profile_settings.recording_format);
            currentFormValues.recording_format = result.profile_settings.recording_format;
          }
        }

        // Step 3: Save all settings to local DB and sync to Cloudonix
        try {
          // Prepare update data with all fields
          const updateData: UpdateCloudonixSettingsRequest = {
            domain_uuid: currentFormValues.domain_uuid || undefined,
            domain_name: currentFormValues.domain_name || undefined,
            domain_api_key: currentFormValues.domain_api_key || undefined,
            domain_requests_api_key: currentFormValues.domain_requests_api_key || undefined,
            webhook_base_url: currentFormValues.webhook_base_url || undefined,
            no_answer_timeout: currentFormValues.no_answer_timeout,
            recording_format: currentFormValues.recording_format,
          };

          const savedSettings = await settingsService.updateCloudonixSettings(updateData);
          setSettingsData(savedSettings);

          const settingsApplied = result.profile_settings &&
            (result.profile_settings.no_answer_timeout !== undefined ||
             result.profile_settings.recording_format !== undefined);

          toast.success('Settings validated and saved successfully', {
            description: settingsApplied
              ? 'Settings from your Cloudonix domain have been applied and synced.'
              : 'Your Cloudonix integration settings have been saved and synced.',
          });

          // Refresh settings to verify persistence
          try {
            const refreshedSettings = await settingsService.getCloudonixSettings();
            setSettingsData(refreshedSettings);

            // Update form with refreshed data
            reset({
              domain_uuid: refreshedSettings.domain_uuid || '',
              domain_name: refreshedSettings.domain_name || '',
              domain_api_key: refreshedSettings.domain_api_key || '',
              domain_requests_api_key: refreshedSettings.domain_requests_api_key || '',
              webhook_base_url: refreshedSettings.webhook_base_url || '',
              no_answer_timeout: refreshedSettings.no_answer_timeout,
              recording_format: refreshedSettings.recording_format,
            });
          } catch (refreshError) {
            console.warn('Failed to refresh settings after save:', refreshError);
          }
        } catch (saveError) {
          const message = getApiErrorMessage(saveError);
          toast.error('Failed to save settings', {
            description: message || 'Settings validated but could not be saved.',
          });
        }
      } else {
        setValidationStatus('invalid');
        toast.error('Invalid credentials', {
          description: result.message || 'Please check your Domain UUID and API Key.',
        });
      }
    } catch (error) {
      setValidationStatus('invalid');
      const message = getApiErrorMessage(error);
      toast.error('Validation failed', {
        description: message || 'Could not validate credentials.',
      });
    } finally {
      setIsValidating(false);
    }
  };

  /**
   * Generate new requests API key
   */
  const handleGenerateRequestsKey = async () => {
    setIsGeneratingKey(true);

    try {
      const result = await settingsService.generateRequestsApiKey();

      setGeneratedKey(result.api_key);
      setValue('domain_requests_api_key', result.api_key);

      toast.success('API key generated successfully', {
        description: result.message || 'Copy this key now - it will not be shown again.',
      });
    } catch (error) {
      const message = getApiErrorMessage(error);
      toast.error('Failed to generate API key', {
        description: message || 'Please try again.',
      });
    } finally {
      setIsGeneratingKey(false);
    }
  };

  /**
   * Copy text to clipboard
   */
  const handleCopyToClipboard = async (text: string, label: string) => {
    try {
      await navigator.clipboard.writeText(text);
      toast.success(`${label} copied to clipboard`);
    } catch (error) {
      toast.error(`Failed to copy ${label}`);
    }
  };


  // Show loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <div className="text-center">
          <Loader2 className="h-12 w-12 animate-spin text-primary mx-auto" />
          <p className="mt-4 text-muted-foreground">Loading settings...</p>
        </div>
      </div>
    );
  }

  return (
    <TooltipProvider>
      <div className="container mx-auto py-8 px-4 max-w-4xl">
        <div className="mb-8">
          <h1 className="text-3xl font-bold text-gray-900 flex items-center gap-2">
            <SettingsIcon className="h-8 w-8" />
            Settings
          </h1>
          <p className="mt-2 text-gray-600">Configure your OPBX integration with Cloudonix</p>
        </div>

        <Card>
          <CardHeader>
            <CardTitle className="flex items-center gap-2">
              <Globe className="h-5 w-5" />
              Cloudonix Integration
            </CardTitle>
            <CardDescription>
              Configure your Cloudonix domain credentials and PBX settings
            </CardDescription>
          </CardHeader>
          <CardContent>
            <form className="space-y-6">
              {/* Domain UUID and API Key - Side by Side */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* Domain UUID */}
                <div className="space-y-2">
                  <Label htmlFor="domain_uuid" className="flex items-center gap-2">
                    <Globe className="h-4 w-4" />
                    Cloudonix Domain UUID
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Info className="h-4 w-4 text-muted-foreground cursor-help" />
                      </TooltipTrigger>
                      <TooltipContent className="max-w-xs">
                        <p>
                          Your unique Cloudonix domain identifier. Find this in your Cloudonix
                          dashboard under Domain Settings.
                        </p>
                      </TooltipContent>
                    </Tooltip>
                  </Label>
                  <Input
                    id="domain_uuid"
                    type="text"
                    placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                    disabled={isValidating}
                    {...register('domain_uuid')}
                  />
                  {errors.domain_uuid && (
                    <p className="text-sm text-destructive flex items-center gap-1">
                      <XCircle className="h-3 w-3" />
                      {errors.domain_uuid.message}
                    </p>
                  )}
                  {settingsData?.domain_name && (
                    <p className="text-xs text-muted-foreground">
                      Cloudonix Domain: <span className="font-mono">{settingsData.domain_name}</span>
                    </p>
                  )}
                </div>

                {/* Domain API Key */}
                <div className="space-y-2">
                  <Label htmlFor="domain_api_key" className="flex items-center gap-2">
                    <Key className="h-4 w-4" />
                    Cloudonix Domain API Key
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Info className="h-4 w-4 text-muted-foreground cursor-help" />
                      </TooltipTrigger>
                      <TooltipContent className="max-w-xs">
                        <p>
                          Your Cloudonix API key for authenticating domain API requests. Keep this
                          secure and do not share it.
                        </p>
                      </TooltipContent>
                    </Tooltip>
                  </Label>
                  <div className="relative">
                    <Input
                      id="domain_api_key"
                      type={showDomainApiKey ? 'text' : 'password'}
                      placeholder="Enter your Cloudonix API key"
                      disabled={isValidating}
                      {...register('domain_api_key')}
                    />
                    <button
                      type="button"
                      onClick={() => setShowDomainApiKey(!showDomainApiKey)}
                      className="absolute right-3 top-3 text-muted-foreground hover:text-foreground"
                    >
                      {showDomainApiKey ? (
                        <EyeOff className="h-4 w-4" />
                      ) : (
                        <Eye className="h-4 w-4" />
                      )}
                    </button>
                  </div>
                  {errors.domain_api_key && (
                    <p className="text-sm text-destructive flex items-center gap-1">
                      <XCircle className="h-3 w-3" />
                      {errors.domain_api_key.message}
                    </p>
                  )}
                </div>
              </div>

              {/* Validate and Save Button */}
              <div className="flex items-center gap-3">
                <Button
                  type="button"
                  onClick={handleValidateAndSave}
                  disabled={isValidating || !domainUuid || !domainApiKey}
                >
                  {isValidating ? (
                    <>
                      <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                      Validating and Saving...
                    </>
                  ) : (
                    <>
                      <CheckCircle2 className="mr-2 h-4 w-4" />
                      Validate and Save Settings
                    </>
                  )}
                </Button>

                {validationStatus === 'valid' && (
                  <Badge className="bg-green-100 text-green-800 border-green-200">
                    <CheckCircle2 className="mr-1 h-3 w-3" />
                    Saved
                  </Badge>
                )}

                {validationStatus === 'invalid' && (
                  <Badge className="bg-red-100 text-red-800 border-red-200">
                    <XCircle className="mr-1 h-3 w-3" />
                    Invalid
                  </Badge>
                )}
              </div>

              {/* Divider */}
              <div className="border-t pt-6" />

              {/* Domain Requests API Key */}
              <div className="space-y-2">
                <Label htmlFor="domain_requests_api_key" className="flex items-center gap-2">
                  <Key className="h-4 w-4" />
                  Cloudonix Domain Requests API Key
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Info className="h-4 w-4 text-muted-foreground cursor-help" />
                    </TooltipTrigger>
                    <TooltipContent className="max-w-xs">
                      <p>
                        Optional key for domain-specific API requests. Generate a new key if you
                        need to reset or create one for the first time.
                      </p>
                    </TooltipContent>
                  </Tooltip>
                </Label>
                <div className="flex gap-2">
                  <div className="relative flex-1">
                    <Input
                      id="domain_requests_api_key"
                      type={showRequestsApiKey ? 'text' : 'password'}
                      placeholder="Optional - Generate a key"
                      disabled={isValidating}
                      {...register('domain_requests_api_key')}
                    />
                    <button
                      type="button"
                      onClick={() => setShowRequestsApiKey(!showRequestsApiKey)}
                      className="absolute right-3 top-3 text-muted-foreground hover:text-foreground"
                    >
                      {showRequestsApiKey ? (
                        <EyeOff className="h-4 w-4" />
                      ) : (
                        <Eye className="h-4 w-4" />
                      )}
                    </button>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    onClick={handleGenerateRequestsKey}
                    disabled={isGeneratingKey || isValidating}
                  >
                    {isGeneratingKey ? (
                      <>
                        <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                        Generating...
                      </>
                    ) : (
                      <>
                        <RefreshCw className="mr-2 h-4 w-4" />
                        Generate
                      </>
                    )}
                  </Button>
                </div>
                {errors.domain_requests_api_key && (
                  <p className="text-sm text-destructive flex items-center gap-1">
                    <XCircle className="h-3 w-3" />
                    {errors.domain_requests_api_key.message}
                  </p>
                )}
              </div>

              {/* Generated Key Display */}
              {generatedKey && (
                <div className="p-4 bg-amber-50 border border-amber-200 rounded-md space-y-2">
                  <div className="flex items-center justify-between gap-2">
                    <div className="flex-1">
                      <p className="text-sm font-medium text-amber-900 mb-1">
                        New API Key Generated
                      </p>
                      <div className="font-mono text-xs break-all text-amber-800 bg-white p-2 rounded">
                        {generatedKey}
                      </div>
                    </div>
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      onClick={() => handleCopyToClipboard(generatedKey, 'API Key')}
                      className="flex-shrink-0"
                    >
                      <Copy className="h-4 w-4" />
                    </Button>
                  </div>
                  <p className="text-xs text-amber-800 flex items-start gap-1">
                    <AlertCircle className="h-3 w-3 flex-shrink-0 mt-0.5" />
                    Save this key now - it will not be shown again after you save settings.
                  </p>
                </div>
              )}

              {/* Divider */}
              <div className="border-t pt-6" />

              {/* Webhook Base URL */}
              <div className="space-y-2">
                <Label htmlFor="webhook_base_url" className="flex items-center gap-2">
                  <Globe className="h-4 w-4" />
                  Webhook Base URL
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <Info className="h-4 w-4 text-muted-foreground cursor-help" />
                    </TooltipTrigger>
                    <TooltipContent className="max-w-xs">
                      <p>
                        Custom base URL for webhook endpoints (CDR and Session Update Callback).
                        If not specified, the default application URL will be used.
                      </p>
                    </TooltipContent>
                  </Tooltip>
                </Label>
                <Input
                  id="webhook_base_url"
                  type="url"
                  placeholder="https://example.com (optional)"
                  disabled={isValidating}
                  {...register('webhook_base_url')}
                />
                {errors.webhook_base_url && (
                  <p className="text-sm text-destructive flex items-center gap-1">
                    <XCircle className="h-3 w-3" />
                    {errors.webhook_base_url.message}
                  </p>
                )}
                <p className="text-xs text-muted-foreground">
                  Optional: Custom base URL for webhook endpoints. Leave empty to use the default application URL.
                </p>
              </div>

              {/* Divider */}
              <div className="border-t pt-6" />

              {/* No Answer Timeout and Recording Format - Side by Side */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* No Answer Timeout */}
                <div className="space-y-2">
                  <Label htmlFor="no_answer_timeout" className="flex items-center gap-2">
                    <Clock className="h-4 w-4" />
                    No Answer Timeout (seconds)
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Info className="h-4 w-4 text-muted-foreground cursor-help" />
                      </TooltipTrigger>
                      <TooltipContent className="max-w-xs">
                        <p>
                          How long to wait (in seconds) before considering a call unanswered. Range:
                          5-120 seconds.
                        </p>
                      </TooltipContent>
                    </Tooltip>
                  </Label>
                  <Input
                    id="no_answer_timeout"
                    type="number"
                    min={5}
                    max={120}
                    disabled={isValidating}
                    {...register('no_answer_timeout', { valueAsNumber: true })}
                  />
                  {errors.no_answer_timeout && (
                    <p className="text-sm text-destructive flex items-center gap-1">
                      <XCircle className="h-3 w-3" />
                      {errors.no_answer_timeout.message}
                    </p>
                  )}
                  <p className="text-xs text-muted-foreground">
                    Time to wait before considering call unanswered (5-120 seconds)
                  </p>
                </div>

                {/* Recording Format */}
                <div className="space-y-2">
                  <Label htmlFor="recording_format" className="flex items-center gap-2">
                    <Mic className="h-4 w-4" />
                    Recording Media File Format
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Info className="h-4 w-4 text-muted-foreground cursor-help" />
                      </TooltipTrigger>
                      <TooltipContent className="max-w-xs">
                        <p>
                          Choose the file format for call recordings. WAV provides higher quality,
                          MP3 provides smaller file sizes.
                        </p>
                      </TooltipContent>
                    </Tooltip>
                  </Label>
                  <Controller
                    name="recording_format"
                    control={control}
                    render={({ field }) => (
                      <Select
                        value={field.value}
                        onValueChange={field.onChange}
                        disabled={isValidating}
                      >
                        <SelectTrigger id="recording_format">
                          <SelectValue placeholder="Select format" />
                        </SelectTrigger>
                        <SelectContent>
                          <SelectItem value="wav">WAV</SelectItem>
                          <SelectItem value="mp3">MP3</SelectItem>
                        </SelectContent>
                      </Select>
                    )}
                  />
                  {errors.recording_format && (
                    <p className="text-sm text-destructive flex items-center gap-1">
                      <XCircle className="h-3 w-3" />
                      {errors.recording_format.message}
                    </p>
                  )}
                  <p className="text-xs text-muted-foreground">
                    Format for call recording files
                  </p>
                </div>
              </div>

              {/* Domain CDR Endpoint and Session Update Callback URL - Side by Side */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                {/* Domain CDR Endpoint (Read-only) */}
                <div className="space-y-2">
                  <Label htmlFor="cdr_url" className="flex items-center gap-2">
                    <FileText className="h-4 w-4" />
                    Domain CDR Endpoint
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Info className="h-4 w-4 text-muted-foreground cursor-help" />
                      </TooltipTrigger>
                      <TooltipContent className="max-w-xs">
                        <p>
                          Webhook URL for receiving Call Detail Records (CDR) from Cloudonix. This endpoint
                          will receive CDR data for local storage and processing.
                        </p>
                      </TooltipContent>
                    </Tooltip>
                  </Label>
                  <div className="flex gap-2">
                    <Input
                      id="cdr_url"
                      type="text"
                      value={settingsData?.cdr_url || '(Not yet generated)'}
                      readOnly
                      disabled
                      className="flex-1"
                    />
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() =>
                        settingsData?.cdr_url &&
                        handleCopyToClipboard(settingsData.cdr_url, 'CDR Endpoint URL')
                      }
                      disabled={!settingsData?.cdr_url}
                    >
                      <Copy className="h-4 w-4" />
                    </Button>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Auto-configured in Cloudonix for CDR events
                  </p>
                </div>

                {/* Callback URL (Read-only) */}
                <div className="space-y-2">
                  <Label htmlFor="callback_url" className="flex items-center gap-2">
                    <LinkIcon className="h-4 w-4" />
                    Domain Session Update Callback URL
                    <Tooltip>
                      <TooltipTrigger asChild>
                        <Info className="h-4 w-4 text-muted-foreground cursor-help" />
                      </TooltipTrigger>
                      <TooltipContent className="max-w-xs">
                        <p>
                          This is your webhook URL for receiving call events from Cloudonix.
                        </p>
                      </TooltipContent>
                    </Tooltip>
                  </Label>
                  <div className="flex gap-2">
                    <Input
                      id="callback_url"
                      type="text"
                      value={settingsData?.callback_url || '(Not yet generated)'}
                      readOnly
                      disabled
                      className="flex-1"
                    />
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() =>
                        settingsData?.callback_url &&
                        handleCopyToClipboard(settingsData.callback_url, 'Callback URL')
                      }
                      disabled={!settingsData?.callback_url}
                    >
                      <Copy className="h-4 w-4" />
                    </Button>
                  </div>
                  <p className="text-xs text-muted-foreground">
                    Auto-configured in Cloudonix for session updates
                  </p>
                </div>
              </div>

            </form>
          </CardContent>
        </Card>
      </div>
    </TooltipProvider>
  );
}
