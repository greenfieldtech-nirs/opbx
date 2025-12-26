/**
 * RingGroupStrategySelector Component
 *
 * Visual selector for ring group strategies
 * Displays three strategy options with icons and descriptions
 *
 * Strategies:
 * - Simultaneous: All members ring at once
 * - Round-Robin: Ring members in rotation
 * - Sequential: Ring members in order until answered
 *
 * @example
 * <RingGroupStrategySelector
 *   value="simultaneous"
 *   onChange={(strategy) => setStrategy(strategy)}
 * />
 */

import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Users, RotateCw, List } from 'lucide-react';
import { cn } from '@/lib/utils';

export type RingGroupStrategy = 'simultaneous' | 'round_robin' | 'sequential';

interface RingGroupStrategySelectorProps {
  value: RingGroupStrategy;
  onChange: (value: RingGroupStrategy) => void;
  disabled?: boolean;
  className?: string;
}

interface StrategyOption {
  value: RingGroupStrategy;
  label: string;
  description: string;
  icon: typeof Users;
  color: string;
}

const strategies: StrategyOption[] = [
  {
    value: 'simultaneous',
    label: 'Simultaneous',
    description: 'Ring all members at the same time until one answers',
    icon: Users,
    color: 'primary',
  },
  {
    value: 'round_robin',
    label: 'Round Robin',
    description: 'Rotate through members, distributing calls evenly',
    icon: RotateCw,
    color: 'success',
  },
  {
    value: 'sequential',
    label: 'Sequential',
    description: 'Ring members one by one in order until answered',
    icon: List,
    color: 'warning',
  },
];

const colorStyles: Record<string, { border: string; bg: string; icon: string }> = {
  primary: {
    border: 'border-primary-500',
    bg: 'bg-primary-50',
    icon: 'text-primary-600',
  },
  success: {
    border: 'border-success-500',
    bg: 'bg-success-50',
    icon: 'text-success-600',
  },
  warning: {
    border: 'border-warning-500',
    bg: 'bg-warning-50',
    icon: 'text-warning-600',
  },
};

export function RingGroupStrategySelector({
  value,
  onChange,
  disabled = false,
  className,
}: RingGroupStrategySelectorProps) {
  return (
    <div className={cn('space-y-2', className)}>
      <Label>Ring Strategy</Label>
      <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
        {strategies.map((strategy) => {
          const isSelected = value === strategy.value;
          const StrategyIcon = strategy.icon;
          const colors = colorStyles[strategy.color];

          return (
            <Card
              key={strategy.value}
              className={cn(
                'cursor-pointer transition-all hover:shadow-md',
                'border-2',
                isSelected
                  ? cn('ring-2 ring-offset-2', colors.border, 'shadow-md')
                  : 'border-neutral-200 hover:border-neutral-300',
                disabled && 'opacity-50 cursor-not-allowed'
              )}
              onClick={() => !disabled && onChange(strategy.value)}
            >
              <div className="p-4 space-y-3">
                {/* Icon */}
                <div
                  className={cn(
                    'h-12 w-12 rounded-lg flex items-center justify-center',
                    isSelected ? colors.bg : 'bg-neutral-50'
                  )}
                >
                  <StrategyIcon
                    className={cn(
                      'h-6 w-6',
                      isSelected ? colors.icon : 'text-neutral-400'
                    )}
                  />
                </div>

                {/* Label */}
                <div>
                  <h3
                    className={cn(
                      'font-semibold',
                      isSelected ? 'text-neutral-900' : 'text-neutral-700'
                    )}
                  >
                    {strategy.label}
                  </h3>
                  <p className="text-sm text-neutral-500 mt-1">
                    {strategy.description}
                  </p>
                </div>

                {/* Selection Indicator */}
                {isSelected && (
                  <div className="flex items-center gap-2 text-xs font-medium text-primary-600">
                    <div className="w-2 h-2 rounded-full bg-primary-600" />
                    Selected
                  </div>
                )}
              </div>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
