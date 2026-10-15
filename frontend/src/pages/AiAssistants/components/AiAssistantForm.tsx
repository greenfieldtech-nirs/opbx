/**
 * AI Assistant Form Component
 *
 * Shared form component for creating and editing AI Assistants
 */

import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Wifi, Phone, Bot } from 'lucide-react';
import { useEffect } from 'react';
import type { ProviderConfigField, ProviderDefinition } from '@/types/aiAssistant';

interface FormData {
  name: string;
  provider: string;
  // String field values, plus the boolean enable_non_e164 flag of a SIP number.
  configuration: Record<string, string | boolean>;
}

interface AiAssistantFormProps {
  formData: FormData;
  onChange: (data: FormData) => void;
  providers: ProviderDefinition[];
  disabled?: boolean;
  mode: 'create' | 'edit';
}

export function AiAssistantForm({
  formData,
  onChange,
  providers,
  disabled = false,
  mode,
}: AiAssistantFormProps) {
  const selectedProvider = formData.provider
    ? providers.find((p) => p.key === formData.provider)
    : null;

  const handleProviderChange = (providerKey: string) => {
    const provider = providers.find((p) => p.key === providerKey);
    if (!provider) return;

    // Reset configuration when provider changes
    onChange({
      name: formData.name,
      provider: providerKey,
      configuration: {},
    });
  };

  const handleConfigChange = (fieldName: string, value: string | boolean) => {
    onChange({
      ...formData,
      configuration: {
        ...formData.configuration,
        [fieldName]: value,
      },
    });
  };

  // Populate read-only provider configuration fields with their default values
  // whenever the selected provider changes (including initial render in edit mode).
  useEffect(() => {
    if (!selectedProvider) return;

    const readOnlyDefaults = selectedProvider.config_fields.reduce<Record<string, string>>(
      (acc, field) => {
        if (field.read_only && field.default_value !== undefined) {
          acc[field.name] = field.default_value;
        }
        return acc;
      },
      {}
    );

    const needsUpdate = Object.entries(readOnlyDefaults).some(
      ([name, value]) => formData.configuration[name] !== value
    );

    if (!needsUpdate) return;

    onChange({
      ...formData,
      configuration: {
        ...formData.configuration,
        ...readOnlyDefaults,
      },
    });
    // Run only when the selected provider key changes.
  }, [selectedProvider?.key]);

  // Group providers by protocol
  const sipProviders = providers.filter((p) => p.protocol === 'sip');
  const websocketProviders = providers.filter((p) => p.protocol === 'websocket');
  const dummyProviders = providers.filter((p) => p.protocol === 'dummy');

  return (
    <div className="space-y-4">
      {/* Name */}
      <div className="space-y-2">
        <Label htmlFor={`${mode}-name`}>Name *</Label>
        <Input
          id={`${mode}-name`}
          value={formData.name}
          onChange={(e) => onChange({ ...formData, name: e.target.value })}
          placeholder="Customer Service Bot"
          disabled={disabled}
        />
      </div>

      {/* Provider Selection */}
      <div className="space-y-2">
        <Label htmlFor={`${mode}-provider`}>AI Service Provider *</Label>
        <Select
          value={formData.provider}
          onValueChange={handleProviderChange}
          disabled={disabled}
        >
          <SelectTrigger id={`${mode}-provider`}>
            <SelectValue placeholder="Select Provider" />
          </SelectTrigger>
          <SelectContent>
            {sipProviders.length > 0 && (
              <>
                <div className="px-2 py-1.5 text-sm font-semibold flex items-center gap-2">
                  <Phone className="h-3 w-3" />
                  SIP Providers
                </div>
                {sipProviders.map((provider) => (
                  <SelectItem key={provider.key} value={provider.key}>
                    {provider.name}
                  </SelectItem>
                ))}
              </>
            )}
            {websocketProviders.length > 0 && (
              <>
                <div className="px-2 py-1.5 text-sm font-semibold flex items-center gap-2 mt-2">
                  <Wifi className="h-3 w-3" />
                  WebSocket Providers
                </div>
                {websocketProviders.map((provider) => (
                  <SelectItem key={provider.key} value={provider.key}>
                    {provider.name}
                  </SelectItem>
                ))}
              </>
            )}
            {dummyProviders.length > 0 && (
              <>
                <div className="px-2 py-1.5 text-sm font-semibold flex items-center gap-2 mt-2">
                  <Bot className="h-3 w-3" />
                  Test Providers
                </div>
                {dummyProviders.map((provider) => (
                  <SelectItem key={provider.key} value={provider.key}>
                    {provider.name}
                  </SelectItem>
                ))}
              </>
            )}
          </SelectContent>
        </Select>
      </div>

      {/* Dynamic Configuration Fields */}
      {selectedProvider?.config_fields && selectedProvider.config_fields.length > 0 && (
        <div className="space-y-4 pt-4 border-t">
          {selectedProvider.config_fields.map((field) => (
            <div key={field.name} className="space-y-2">
              <Label htmlFor={`${mode}-${field.name}`}>
                {field.label} {field.required && <span className="text-destructive">*</span>}
              </Label>
              <Input
                id={`${mode}-${field.name}`}
                type={field.type === 'password' ? 'password' : 'text'}
                value={(formData.configuration[field.name] as string) || ''}
                onChange={(e) => handleConfigChange(field.name, e.target.value)}
                placeholder={field.placeholder || ''}
                disabled={disabled || field.read_only}
                maxLength={maxLengthOf(field)}
              />
              {field.type === 'tel' && (
                <div className="flex items-center space-x-2">
                  <Checkbox
                    id={`${mode}-enable_non_e164`}
                    checked={formData.configuration.enable_non_e164 === true}
                    onCheckedChange={(checked) => handleConfigChange('enable_non_e164', checked === true)}
                    disabled={disabled}
                  />
                  <Label htmlFor={`${mode}-enable_non_e164`} className="text-sm font-normal cursor-pointer">
                    Enable non-E.164 Phone Number
                  </Label>
                </div>
              )}
              {field.type === 'tel' ? (
                <p className="text-xs text-muted-foreground">
                  {formData.configuration.enable_non_e164 === true
                    ? 'Enter the number using digits, +, and # characters'
                    : 'Enter in E.164 format: +[country][number]'}
                </p>
              ) : field.description && (
                <p className="text-xs text-muted-foreground">{field.description}</p>
              )}
            </div>
          ))}
        </div>
      )}
    </div>
  );
}

/** The provider field's `max:N` validation rule (enforced by the API) as an input maxLength. */
function maxLengthOf(field: ProviderConfigField): number | undefined {
  const rule = field.validation_rules?.find((r) => /^max:\d+$/.test(r));
  return rule ? Number(rule.slice(4)) : undefined;
}

export default AiAssistantForm;
