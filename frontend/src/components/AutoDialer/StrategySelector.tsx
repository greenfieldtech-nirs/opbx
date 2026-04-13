/**
 * StrategySelector Component
 *
 * Visual selector for Caller ID pool strategies
 * Displays three strategy options with icons and tooltips
 *
 * Strategies:
 * - Round Robin: Cycle through Caller IDs sequentially
 * - Random: Select Caller IDs randomly for each call
 * - Least Recently Used: Select the Caller ID used longest ago
 *
 * @example
 * <StrategySelector
 *   value="round_robin"
 *   onChange={(strategy) => setStrategy(strategy)}
 * />
 */

import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import {
  Tooltip,
  TooltipContent,
  TooltipProvider,
  TooltipTrigger,
} from '@/components/ui/tooltip';
import { ListOrdered, Shuffle, Clock, Check, Info } from 'lucide-react';
import { cn } from '@/lib/utils';

export type CallerIdStrategy = 'round_robin' | 'random' | 'least_recently_used';

interface StrategySelectorProps {
  value: CallerIdStrategy;
  onChange: (value: CallerIdStrategy) => void;
  disabled?: boolean;
  className?: string;
}

interface StrategyOption {
  value: CallerIdStrategy;
  label: string;
  description: string;
  icon: typeof ListOrdered;
}

const strategies: StrategyOption[] = [
  {
    value: 'round_robin',
    label: 'Round Robin',
    description: 'Cycle through Caller IDs sequentially (1, 2, 3, 1, 2, 3...)',
    icon: ListOrdered,
  },
  {
    value: 'random',
    label: 'Random',
    description: 'Select Caller IDs randomly for each call',
    icon: Shuffle,
  },
  {
    value: 'least_recently_used',
    label: 'Least Recently Used',
    description: 'Select the Caller ID used longest ago',
    icon: Clock,
  },
];

export function StrategySelector({
  value,
  onChange,
  disabled = false,
  className,
}: StrategySelectorProps) {
  return (
    <TooltipProvider>
      <div className={cn('space-y-3', className)}>
        <Label className="text-sm font-medium">Strategy</Label>
        <div
          className="flex flex-col gap-2"
          role="radiogroup"
          aria-label="Select Caller ID distribution strategy"
        >
          {strategies.map((strategy) => {
            const isSelected = value === strategy.value;
            const StrategyIcon = strategy.icon;

            return (
              <Tooltip key={strategy.value} delayDuration={100}>
                <TooltipTrigger asChild>
                  <Card
                    role="radio"
                    aria-checked={isSelected}
                    aria-label={`Select ${strategy.label} strategy`}
                    tabIndex={disabled ? -1 : 0}
                    className={cn(
                      'cursor-pointer transition-all flex items-center gap-3 p-3',
                      'border',
                      isSelected
                        ? 'border-primary bg-primary/5 ring-1 ring-primary'
                        : 'border-border hover:border-primary/50 hover:bg-muted/50',
                      disabled && 'opacity-50 cursor-not-allowed pointer-events-none'
                    )}
                    onClick={() => !disabled && onChange(strategy.value)}
                    onKeyDown={(e) => {
                      if (!disabled && (e.key === 'Enter' || e.key === ' ')) {
                        e.preventDefault();
                        onChange(strategy.value);
                      }
                    }}
                  >
                    {/* Icon */}
                    <div
                      className={cn(
                        'h-8 w-8 rounded-md flex items-center justify-center shrink-0',
                        isSelected ? 'bg-primary/10' : 'bg-muted'
                      )}
                    >
                      <StrategyIcon
                        className={cn(
                          'h-4 w-4',
                          isSelected ? 'text-primary' : 'text-muted-foreground'
                        )}
                      />
                    </div>

                    {/* Label */}
                    <span
                      className={cn(
                        'flex-1 text-sm font-medium',
                        isSelected ? 'text-foreground' : 'text-foreground/80'
                      )}
                    >
                      {strategy.label}
                    </span>

                    {/* Selection Indicator */}
                    {isSelected ? (
                      <div className="h-5 w-5 rounded-full bg-primary flex items-center justify-center shrink-0">
                        <Check className="h-3 w-3 text-primary-foreground" />
                      </div>
                    ) : (
                      <Info className="h-4 w-4 text-muted-foreground shrink-0" />
                    )}
                  </Card>
                </TooltipTrigger>
                <TooltipContent side="right" className="max-w-xs">
                  <p className="text-sm">{strategy.description}</p>
                </TooltipContent>
              </Tooltip>
            );
          })}
        </div>
      </div>
    </TooltipProvider>
  );
}
