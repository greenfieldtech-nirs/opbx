/**
 * DestinationSelector Component
 */

import { Label } from '@/components/ui/label';
import { Badge } from '@/components/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import type { DestinationSelectorProps } from './types/destination.types';
import { useDestinationOptions } from './hooks/useDestinations';
import { getEmptyMessage } from './utils/destination-config';
import { cn } from '@/lib/utils';

export function DestinationSelector({
  type,
  value,
  onChange,
  label = 'Destination',
  placeholder = 'Select destination',
  disabled = false,
  extensionTypes,
  showBadges = true,
  emptyMessage,
  loadingMessage = 'Loading destinations...',
  className,
}: DestinationSelectorProps) {
  // Get destination options for the selected type
  const { options, isLoading } = useDestinationOptions(type, extensionTypes);

  // Handle selection change
  const handleChange = (selectedValue: string) => {
    const option = options.find((o) => o.id === selectedValue);
    if (option) {
      onChange(selectedValue, option);
    }
  };

  const isDisabled = disabled || !type || isLoading;
  const emptyText = emptyMessage || (type ? getEmptyMessage(type) : 'No destinations available');

  return (
    <div className={cn("min-w-0", className)}>
      {label && <Label className="mb-2 block">{label}</Label>}
      <Select
        value={value}
        onValueChange={handleChange}
        disabled={isDisabled}
      >
        <SelectTrigger className="w-full">
          <SelectValue placeholder={isLoading ? loadingMessage : placeholder} />
        </SelectTrigger>
        <SelectContent className="min-w-[300px]">
          {isLoading ? (
            <div className="px-2 py-4 text-center text-sm text-muted-foreground">
              {loadingMessage}
            </div>
          ) : options.length === 0 ? (
            <div className="px-2 py-4 text-center text-sm text-muted-foreground">
              {emptyText}
            </div>
          ) : (
            options.map((option) => (
              <SelectItem
                key={`${option.type}-${option.id}`}
                value={option.id}
                className="cursor-pointer"
              >
                <div className="flex items-center gap-2 py-1">
                  {showBadges && option.badge && (
                    <Badge
                      variant="outline"
                      className={cn(
                        'px-1.5 py-0 text-xs shrink-0',
                        option.badge.color
                      )}
                    >
                      {option.badge.text}
                    </Badge>
                  )}
                  <div className="flex flex-col min-w-0">
                    <span className="truncate">{option.label}</span>
                    {option.subLabel && (
                      <span className="text-xs text-muted-foreground truncate">
                        {option.subLabel}
                      </span>
                    )}
                  </div>
                </div>
              </SelectItem>
            ))
          )}
        </SelectContent>
      </Select>
    </div>
  );
}

export default DestinationSelector;
