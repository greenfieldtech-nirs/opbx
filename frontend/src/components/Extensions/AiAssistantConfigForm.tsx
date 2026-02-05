import React, { useEffect, useMemo } from 'react';
import { useQuery } from '@tanstack/react-query';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { Loader2, Phone, Wifi } from 'lucide-react';
import aiAssistantProvidersService from '@/services/aiAssistantProviders.service';
import type { ProviderDefinition } from '@/types/aiAssistant';

interface AiAssistantConfigFormProps {
  formData: {
    ai_provider: string;
    ai_phone_number: string;
    ai_bot_id: string;
    ai_auth_token: string;
    ai_api_key: string;
    ai_assistant_id: string;
    ai_session_id: string;
  } & Record<string, any>;
  setFormData: (data: any) => void;
  formErrors: Record<string, string | undefined>;
}

export const AiAssistantConfigForm: React.FC<AiAssistantConfigFormProps> = ({
  formData,
  setFormData,
  formErrors,
}) => {
  // Fetch providers from API
  const { data: providersResponse, isLoading, error } = useQuery({
    queryKey: ['aiAssistantProviders'],
    queryFn: () => aiAssistantProvidersService.getAll(),
  });

  const providers = providersResponse?.data?.providers || [];
  const groupedProviders = providersResponse?.data?.grouped || { sip: [], websocket: [] };

  // Find selected provider definition
  const selectedProvider = useMemo(() => {
    if (!formData.ai_provider) return null;
    return providers.find((p: ProviderDefinition) => p.key === formData.ai_provider);
  }, [formData.ai_provider, providers]);

  // Clear non-applicable fields when provider changes
  useEffect(() => {
    if (selectedProvider) {
      const updates: any = {};
      
      // Clear fields not applicable to current protocol
      if (selectedProvider.protocol === 'websocket') {
        updates.ai_phone_number = '';
      } else if (selectedProvider.protocol === 'sip') {
        updates.ai_bot_id = '';
        updates.ai_auth_token = '';
        updates.ai_api_key = '';
        updates.ai_assistant_id = '';
        updates.ai_session_id = '';
      }

      // Only update if there are changes
      if (Object.keys(updates).length > 0) {
        setFormData({ ...formData, ...updates });
      }
    }
  }, [selectedProvider?.protocol]);

  // Render loading state
  if (isLoading) {
    return (
      <div className="flex items-center justify-center py-8">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
        <span className="ml-2 text-sm text-muted-foreground">Loading AI providers...</span>
      </div>
    );
  }

  // Render error state
  if (error) {
    return (
      <div className="rounded-md border border-destructive bg-destructive/10 p-4">
        <p className="text-sm text-destructive">
          Failed to load AI providers. Please try again.
        </p>
      </div>
    );
  }

  // Render dynamic field based on provider configuration
  const renderField = (field: any) => {
    const fieldKey = `ai_${field.key}`;
    const fieldValue = formData[fieldKey] || '';
    const fieldError = formErrors[fieldKey];

    return (
      <div key={field.key} className="space-y-2">
        <Label htmlFor={fieldKey}>
          {field.label} {field.required && <span className="text-destructive">*</span>}
        </Label>
        <Input
          id={fieldKey}
          type={field.type === 'password' ? 'password' : 'text'}
          value={fieldValue}
          onChange={(e) => setFormData({ ...formData, [fieldKey]: e.target.value })}
          placeholder={field.placeholder || ''}
          autoComplete="off"
        />
        {field.description && (
          <p className="text-xs text-muted-foreground">{field.description}</p>
        )}
        {fieldError && (
          <p className="text-sm text-destructive">{fieldError}</p>
        )}
      </div>
    );
  };

  return (
    <>
      {/* Provider Selection */}
      <div className="space-y-2">
        <Label htmlFor="ai_provider">
          AI Service Provider <span className="text-destructive">*</span>
        </Label>
        <Select
          value={formData.ai_provider}
          onValueChange={(value) => setFormData({ ...formData, ai_provider: value })}
        >
          <SelectTrigger id="ai_provider">
            <SelectValue placeholder="Select AI Provider" />
          </SelectTrigger>
          <SelectContent>
            {/* SIP Providers Group */}
            {groupedProviders.sip && groupedProviders.sip.length > 0 && (
              <SelectGroup>
                <SelectLabel className="flex items-center gap-2">
                  <Phone className="h-3 w-3" />
                  SIP Providers
                </SelectLabel>
                {groupedProviders.sip.map((provider: ProviderDefinition) => (
                  <SelectItem key={provider.key} value={provider.key}>
                    {provider.name}
                  </SelectItem>
                ))}
              </SelectGroup>
            )}

            {/* WebSocket Providers Group */}
            {groupedProviders.websocket && groupedProviders.websocket.length > 0 && (
              <SelectGroup>
                <SelectLabel className="flex items-center gap-2">
                  <Wifi className="h-3 w-3" />
                  WebSocket Providers
                </SelectLabel>
                {groupedProviders.websocket.map((provider: ProviderDefinition) => (
                  <SelectItem key={provider.key} value={provider.key}>
                    {provider.name}
                  </SelectItem>
                ))}
              </SelectGroup>
            )}
          </SelectContent>
        </Select>
        {formErrors.ai_provider && (
          <p className="text-sm text-destructive">{formErrors.ai_provider}</p>
        )}
      </div>

      {/* Show provider info and protocol badge when provider is selected */}
      {selectedProvider && (
        <div className="rounded-md border bg-muted/50 p-3 space-y-2">
          <div className="flex items-center gap-2">
            <Badge variant={selectedProvider.protocol === 'websocket' ? 'default' : 'secondary'}>
              {selectedProvider.protocol === 'websocket' ? (
                <>
                  <Wifi className="h-3 w-3 mr-1" />
                  WebSocket
                </>
              ) : (
                <>
                  <Phone className="h-3 w-3 mr-1" />
                  SIP
                </>
              )}
            </Badge>
            <span className="text-sm font-medium">{selectedProvider.name}</span>
          </div>
          {selectedProvider.description && (
            <p className="text-xs text-muted-foreground">{selectedProvider.description}</p>
          )}
        </div>
      )}

      {/* Dynamic fields based on selected provider */}
      {selectedProvider && selectedProvider.config_fields && selectedProvider.config_fields.length > 0 && (
        <>
          {selectedProvider.config_fields.map((field) => renderField(field))}
        </>
      )}

      {/* Show help text if no provider selected */}
      {!selectedProvider && (
        <div className="rounded-md border border-dashed p-4">
          <p className="text-sm text-muted-foreground text-center">
            Select an AI provider to configure connection settings
          </p>
        </div>
      )}
    </>
  );
};
