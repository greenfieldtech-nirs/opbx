/**
 * DestinationTypeAndSelector Component
 *
 * Combined component that renders both Type and Destination selectors
 * with various layout options.
 */

import { useEffect } from 'react';
import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { DestinationTypeAndSelectorProps } from './types/destination.types';
import { DestinationTypeSelector } from './DestinationTypeSelector';
import { DestinationSelector } from './DestinationSelector';
import { requiresDestination } from './utils/destination-config';

/**
 * Destination Type And Selector Component
 *
 * A combined component that renders both type and destination selectors
 * with proper state synchronization.
 *
 * Layout options:
 * - horizontal: Side-by-side (Type | Destination)
 * - vertical: Stacked (Type above Destination)
 * - grid: Custom grid columns
 *
 * @example
 * ```tsx
 * // Horizontal layout (default)
 * <DestinationTypeAndSelector
 *   typeValue={type}
 *   destinationValue={destination}
 *   onChange={(t, d) => { setType(t); setDestination(d); }}
 * />
 *
 * // Grid layout
 * <DestinationTypeAndSelector
 *   typeValue={type}
 *   destinationValue={destination}
 *   onChange={(t, d) => { setType(t); setDestination(d); }}
 *   layout="grid"
 *   gridColumns={{ type: 3, destination: 9 }}
 * />
 * ```
 */
export function DestinationTypeAndSelector({
  typeValue,
  destinationValue,
  onChange,
  typeLabel = 'Type',
  destinationLabel = 'Destination',
  disabled = false,
  allowedTypes,
  includeHangup = false,
  layout = 'grid',
  gridColumns = { type: 3, destination: 8 },
  extensionTypes,
  showDescriptions = false,
  className,
}: DestinationTypeAndSelectorProps) {
  // Handle type change - clear destination if type changes
  const handleTypeChange = (newType: typeof typeValue) => {
    if (newType) {
      // Check if the new type requires a destination
      if (requiresDestination(newType)) {
        // Clear destination when type changes
        onChange(newType, '');
      } else {
        // For types that don't require destination (e.g., hangup)
        onChange(newType, '');
      }
    }
  };

  // Handle destination change
  const handleDestinationChange = (newDestination: string) => {
    if (typeValue) {
      onChange(typeValue, newDestination);
    }
  };

  // Layout classes
  const layoutClasses = {
    horizontal: 'flex flex-row gap-4',
    vertical: 'flex flex-col gap-4',
    grid: 'grid gap-4',
  };

  // Grid column classes
  const gridClass = layout === 'grid'
    ? `grid-cols-12`
    : '';

  return (
    <div className={cn(layoutClasses[layout], gridClass, className)}>
      {/* Type Selector */}
      <div className={layout === 'grid' ? `col-span-${gridColumns.type}` : 'flex-1'}>
        <DestinationTypeSelector
          value={typeValue}
          onChange={(type) => handleTypeChange(type)}
          label={typeLabel}
          disabled={disabled}
          allowedTypes={allowedTypes}
          includeHangup={includeHangup}
          showDescriptions={showDescriptions}
        />
      </div>

      {/* Destination Selector - only show if type requires destination */}
      {typeValue && requiresDestination(typeValue) && (
        <div className={layout === 'grid' ? `col-span-${gridColumns.destination}` : 'flex-[3]'}>
          <DestinationSelector
            type={typeValue}
            value={destinationValue}
            onChange={(_, option) => handleDestinationChange(option.id)}
            label={destinationLabel}
            disabled={disabled}
            extensionTypes={extensionTypes}
            showBadges
          />
        </div>
      )}

      {/* Hangup label (when hangup is selected) */}
      {typeValue === 'hangup' && (
        <div className={layout === 'grid' ? `col-span-${gridColumns.destination}` : 'flex-[3]'}>
          <Label className="mb-2 block">{destinationLabel}</Label>
          <div className="h-10 flex items-center px-3 rounded-md border border-input bg-muted/50 text-muted-foreground text-sm">
            Call will end
          </div>
        </div>
      )}
    </div>
  );
}

export default DestinationTypeAndSelector;
