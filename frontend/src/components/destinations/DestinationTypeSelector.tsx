/**
 * DestinationTypeSelector Component
 *
 * A dropdown component for selecting destination types (Extension, Ring Group, etc.)
 * Includes icons, descriptions, and proper categorization.
 */

import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { DestinationTypeSelectorProps } from './types/destination.types';
import {
  getAllDestinationTypeConfigs,
  sortDestinationTypes,
} from './utils/destination-config';

/**
 * Destination Type Selector Component
 *
 * Renders a dropdown with all available destination types, including:
 * - Icons for each type
 * - Labels and descriptions
 * - Optional filtering by allowed types
 * - Hangup option for failover scenarios
 *
 * @example
 * ```tsx
 * <DestinationTypeSelector
 *   value={destinationType}
 *   onChange={(type, metadata) => setDestinationType(type)}
 *   label="Destination Type"
 *   allowedTypes={['extension', 'ring_group', 'ivr_menu']}
 * />
 * ```
 */
export function DestinationTypeSelector({
  value,
  onChange,
  label = 'Type',
  placeholder = 'Select type',
  disabled = false,
  allowedTypes,
  includeHangup = false,
  showDescriptions = false,
  className,
}: DestinationTypeSelectorProps) {
  // Get type configurations
  const typeConfigs = sortDestinationTypes(
    getAllDestinationTypeConfigs(allowedTypes, includeHangup)
  );

  // Handle selection change
  const handleChange = (selectedValue: string) => {
    const config = typeConfigs.find((t) => t.value === selectedValue);
    if (config) {
      onChange(selectedValue as DestinationTypeSelectorProps['value'], config);
    }
  };

  return (
    <div className={className}>
      {label && <Label className="mb-2 block">{label}</Label>}
      <Select
        value={value || ''}
        onValueChange={handleChange}
        disabled={disabled || typeConfigs.length === 0}
      >
        <SelectTrigger className="w-full">
          <SelectValue placeholder={placeholder} />
        </SelectTrigger>
        <SelectContent>
          {typeConfigs.map((config) => {
            const Icon = config.icon;
            return (
              <SelectItem
                key={config.value}
                value={config.value}
                className="cursor-pointer"
              >
                <div className="flex items-start gap-3 py-1">
                  <div className="mt-0.5 flex h-5 w-5 items-center justify-center rounded-md bg-muted">
                    <Icon className="h-3.5 w-3.5" />
                  </div>
                  <div className="flex flex-col">
                    <span className="font-medium">{config.label}</span>
                    {showDescriptions && (
                      <span className="text-xs text-muted-foreground">
                        {config.description}
                      </span>
                    )}
                  </div>
                </div>
              </SelectItem>
            );
          })}
        </SelectContent>
      </Select>
    </div>
  );
}

export default DestinationTypeSelector;
