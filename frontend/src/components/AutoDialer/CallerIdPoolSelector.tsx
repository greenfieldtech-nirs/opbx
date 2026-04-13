/**
 * CallerIdPoolSelector Component
 *
 * Multi-select DID picker with search functionality for Caller ID pooling.
 * Allows selecting multiple phone numbers for Caller ID cycling.
 *
 * Features:
 * - Searchable dropdown with checkbox-based selection
 * - Selected items display with remove functionality
 * - Validation for max 100 items
 * - Empty state with "Add Caller IDs" button
 * - Loading and error states
 *
 * @example
 * <CallerIdPoolSelector
 *   selected={[{ did_id: 1, phone_number: "+1234567890" }]}
 *   onChange={(pool) => setPool(pool)}
 *   maxSelection={100}
 * />
 */

import { useState, useMemo } from 'react';
import { Card } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@/components/ui/badge';
import { Skeleton } from '@/components/ui/skeleton';
import { Alert, AlertTitle, AlertDescription } from '@/components/ui/alert';
import {
  Popover,
  PopoverContent,
  PopoverTrigger,
} from '@/components/ui/popover';
import {
  Command,
  CommandInput,
  CommandList,
  CommandEmpty,
  CommandGroup,
  CommandItem,
} from '@/components/ui/command';
import {
  Phone,
  Plus,
  X,
  AlertCircle,
  Check,
  ChevronDown,
} from 'lucide-react';
import { cn } from '@/lib/utils';
import { useAvailableCallerIds } from '@/hooks/useCallerIdPool';

export interface CallerIdPoolItem {
  did_id: number;
  phone_number: string;
  friendly_name?: string | null;
  weight?: number;
}

interface CallerIdPoolSelectorProps {
  selected: CallerIdPoolItem[];
  onChange: (pool: CallerIdPoolItem[]) => void;
  maxSelection?: number;
  disabled?: boolean;
}

export function CallerIdPoolSelector({
  selected,
  onChange,
  maxSelection = 100,
  disabled = false,
}: CallerIdPoolSelectorProps) {
  const [open, setOpen] = useState(false);
  const [searchQuery, setSearchQuery] = useState('');

  const {
    data: availableDids,
    isLoading,
    isError,
    error,
  } = useAvailableCallerIds();

  // Filter out already selected DIDs from available options
  const availableOptions = useMemo(() => {
    if (!availableDids) return [];
    const selectedIds = new Set(selected.map((item) => item.did_id));
    return availableDids.filter((did) => !selectedIds.has(did.id));
  }, [availableDids, selected]);

  // Filter options based on search query
  const filteredOptions = useMemo(() => {
    if (!searchQuery) return availableOptions;
    const query = searchQuery.toLowerCase();
    return availableOptions.filter(
      (did) =>
        did.phone_number.toLowerCase().includes(query) ||
        did.friendly_name?.toLowerCase().includes(query)
    );
  }, [availableOptions, searchQuery]);

  const handleSelect = (did: { id: number; phone_number: string; friendly_name?: string }) => {
    if (selected.length >= maxSelection) return;

    const newItem: CallerIdPoolItem = {
      did_id: did.id,
      phone_number: did.phone_number,
      friendly_name: did.friendly_name,
    };

    onChange([...selected, newItem]);
    setSearchQuery('');
  };

  const handleRemove = (didId: number) => {
    onChange(selected.filter((item) => item.did_id !== didId));
  };

  const isMaxReached = selected.length >= maxSelection;

  // Loading state
  if (isLoading) {
    return (
      <Card className="p-4">
        <div className="space-y-3">
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
          <Skeleton className="h-10 w-full" />
        </div>
      </Card>
    );
  }

  // Error state
  if (isError) {
    return (
      <Alert variant="destructive">
        <AlertCircle className="h-4 w-4" />
        <AlertTitle>Error loading available phone numbers</AlertTitle>
        <AlertDescription>
          {error instanceof Error
            ? error.message
            : 'Please try again or contact support if the problem persists.'}
        </AlertDescription>
      </Alert>
    );
  }

  // Empty state - no items selected
  if (selected.length === 0) {
    return (
      <Card className="border-dashed">
        <div className="text-center py-8 px-4">
          <div className="h-12 w-12 rounded-full bg-muted flex items-center justify-center mx-auto mb-4">
            <Phone className="h-6 w-6 text-muted-foreground" />
          </div>
          <h3 className="text-lg font-semibold mb-2">No Caller IDs Selected</h3>
          <p className="text-sm text-muted-foreground mb-4 max-w-sm mx-auto">
            Select phone numbers from your organization&apos;s DIDs to use as Caller IDs for
            outbound calls
          </p>
          <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
              <Button disabled={disabled || isMaxReached}>
                <Plus className="h-4 w-4 mr-2" />
                Add Caller IDs
              </Button>
            </PopoverTrigger>
            <PopoverContent className="w-[400px] p-0" align="start">
              <Command>
                <CommandInput
                  placeholder="Search phone numbers..."
                  value={searchQuery}
                  onValueChange={setSearchQuery}
                />
                <CommandList className="max-h-[300px]">
                  <CommandEmpty>No phone numbers found</CommandEmpty>
                  <CommandGroup heading="Available Phone Numbers">
                    {filteredOptions.map((did) => (
                      <CommandItem
                        key={did.id}
                        onSelect={() => handleSelect(did)}
                        disabled={isMaxReached}
                      >
                        <Phone className="mr-2 h-4 w-4 text-muted-foreground" />
                        <div className="flex-1 min-w-0">
                          <div className="font-medium">{did.phone_number}</div>
                          {did.friendly_name && (
                            <div className="text-xs text-muted-foreground truncate">
                              {did.friendly_name}
                            </div>
                          )}
                        </div>
                        <Plus className="h-4 w-4 ml-2" />
                      </CommandItem>
                    ))}
                  </CommandGroup>
                </CommandList>
              </Command>
            </PopoverContent>
          </Popover>
        </div>
      </Card>
    );
  }

  // Selected items display
  return (
    <div className="space-y-4">
      {/* Header with count and add button */}
      <div className="flex items-center justify-between">
        <div className="flex items-center gap-2">
          <h3 className="font-medium">Selected Caller IDs</h3>
          <Badge variant={isMaxReached ? 'destructive' : 'secondary'}>
            {selected.length}/{maxSelection}
          </Badge>
        </div>
        <Popover open={open} onOpenChange={setOpen}>
          <PopoverTrigger asChild>
            <Button
              variant="outline"
              size="sm"
              disabled={disabled || isMaxReached}
            >
              <Plus className="h-4 w-4 mr-2" />
              Add
              <ChevronDown className="h-3 w-3 ml-2" />
            </Button>
          </PopoverTrigger>
          <PopoverContent className="w-[400px] p-0" align="end">
            <Command>
              <CommandInput
                placeholder="Search phone numbers..."
                value={searchQuery}
                onValueChange={setSearchQuery}
              />
              <CommandList className="max-h-[300px]">
                <CommandEmpty>No phone numbers found</CommandEmpty>
                <CommandGroup heading="Available Phone Numbers">
                  {filteredOptions.map((did) => (
                    <CommandItem
                      key={did.id}
                      onSelect={() => {
                        handleSelect(did);
                        if (selected.length + 1 >= maxSelection) {
                          setOpen(false);
                        }
                      }}
                      disabled={isMaxReached}
                    >
                      <Phone className="mr-2 h-4 w-4 text-muted-foreground" />
                      <div className="flex-1 min-w-0">
                        <div className="font-medium">{did.phone_number}</div>
                        {did.friendly_name && (
                          <div className="text-xs text-muted-foreground truncate">
                            {did.friendly_name}
                          </div>
                        )}
                      </div>
                      <Plus className="h-4 w-4 ml-2" />
                    </CommandItem>
                  ))}
                </CommandGroup>
              </CommandList>
            </Command>
          </PopoverContent>
        </Popover>
      </div>

      {/* Max selection warning */}
      {isMaxReached && (
        <Alert variant="destructive" className="py-2">
          <AlertCircle className="h-4 w-4" />
          <AlertDescription className="text-xs">
            Maximum {maxSelection} Caller IDs allowed
          </AlertDescription>
        </Alert>
      )}

      {/* Selected items list */}
      <Card className="divide-y">
        {selected.map((item, index) => (
          <div
            key={item.did_id}
            className={cn(
              'flex items-center gap-3 p-3',
              disabled && 'opacity-50'
            )}
          >
            {/* Phone number info */}
            <div className="flex-1 min-w-0">
              <div className="flex items-center gap-2">
                <Phone className="h-4 w-4 text-muted-foreground shrink-0" />
                <span className="font-medium truncate">{item.phone_number}</span>
              </div>
              {item.friendly_name && (
                <div className="text-xs text-muted-foreground ml-6 truncate">
                  {item.friendly_name}
                </div>
              )}
            </div>

            {/* Remove button */}
            <Button
              variant="ghost"
              size="icon"
              className="h-8 w-8 shrink-0"
              onClick={() => handleRemove(item.did_id)}
              disabled={disabled}
              aria-label={`Remove ${item.phone_number}`}
            >
              <X className="h-4 w-4" />
            </Button>
          </div>
        ))}
      </Card>

      {/* Helper text */}
      <p className="text-xs text-muted-foreground">
        Selected Caller IDs will be cycled through based on the chosen strategy.
      </p>
    </div>
  );
}
