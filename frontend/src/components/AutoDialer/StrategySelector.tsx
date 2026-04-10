/**
 * StrategySelector Component
 *
 * Visual selector for Caller ID pool strategies
 * Displays three strategy options with icons and descriptions
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
import { ListOrdered, Shuffle, Clock, Check } from 'lucide-react';
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
    <div className={cn('space-y-3', className)}>
      <Label>Distribution Strategy</Label>
      <div
        className="grid grid-cols-1 md:grid-cols-3 gap-4"
        role="radiogroup"
        aria-label="Select Caller ID distribution strategy"
      >
        {strategies.map((strategy) => {
          const isSelected = value === strategy.value;
          const StrategyIcon = strategy.icon;

          return (
            <Card
              key={strategy.value}
              role="radio"
              aria-checked={isSelected}
              aria-label={`Select ${strategy.label} strategy`}
              tabIndex={disabled ? -1 : 0}
              className={cn(
                'cursor-pointer transition-all',
                'border-2',
                isSelected
                  ? 'border-primary ring-2 ring-primary ring-offset-2 shadow-md'
                  : 'border-border hover:border-primary/50',
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
              <div className="p-4 space-y-3">
                {/* Icon and Selection Indicator */}
                <div className="flex items-start justify-between">
                  <div
                    className={cn(
                      'h-10 w-10 rounded-lg flex items-center justify-center',
                      isSelected ? 'bg-primary/10' : 'bg-muted'
                    )}
                  >
                    <StrategyIcon
                      className={cn(
                        'h-5 w-5',
                        isSelected ? 'text-primary' : 'text-muted-foreground'
                      )}
                    />
                  </div>
                  {isSelected && (
                    <div className="h-5 w-5 rounded-full bg-primary flex items-center justify-center">
                      <Check className="h-3 w-3 text-primary-foreground" />
                    </div>
                  )}
                </div>

                {/* Label and Description */}
                <div>
                  <h3
                    className={cn(
                      'font-semibold text-sm',
                      isSelected ? 'text-foreground' : 'text-foreground/80'
                    )}
                  >
                    {strategy.label}
                  </h3>
                  <p className="text-xs text-muted-foreground mt-1 leading-relaxed">
                    {strategy.description}
                  </p>
                </div>
              </div>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
