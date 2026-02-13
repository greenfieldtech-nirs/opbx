import { Label } from '@/components/ui/label';
import { cn } from '@/lib/utils';
import type { DestinationTypeAndSelectorProps } from './types/destination.types';
import { DestinationTypeSelector } from './DestinationTypeSelector';
import { DestinationSelector } from './DestinationSelector';
import { requiresDestination } from './utils/destination-config';

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
  const handleTypeChange = (newType: typeof typeValue) => {
    if (newType) {
      onChange(newType, '');
    }
  };

  const handleDestinationChange = (newDestination: string) => {
    if (typeValue) {
      onChange(typeValue, newDestination);
    }
  };

  const layoutClasses = {
    horizontal: 'flex flex-row gap-4',
    vertical: 'flex flex-col gap-4',
    grid: 'grid gap-4',
  };

  const gridClass = layout === 'grid' ? 'grid-cols-12' : '';

  return (
    <div className={cn(layoutClasses[layout], gridClass, className)}>
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

      {typeValue && requiresDestination(typeValue) && (
        <div className={layout === 'grid' ? `col-span-${gridColumns.destination}` : 'flex-[3] min-w-0'}>
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
