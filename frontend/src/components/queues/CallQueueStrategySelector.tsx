/**
 * CallQueueStrategySelector Component
 *
 * Visual selector for call queue agent-selection strategies.
 * Same card-based design as RingGroupStrategySelector.
 *
 * Strategies:
 * - Ring All: ring all available agents at once
 * - Round Robin: rotate across agents
 * - Least Talk Time: offer to the agent with the least talk time
 * - Fewest Calls: offer to the agent with the fewest handled calls
 */

import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Users, RotateCw, Timer, BarChart3 } from 'lucide-react';
import { cn } from '@/lib/utils';

export type CallQueueStrategy = 'ring_all' | 'round_robin' | 'least_talk_time' | 'fewest_calls';

interface CallQueueStrategySelectorProps {
  value: CallQueueStrategy;
  onChange: (value: CallQueueStrategy) => void;
  disabled?: boolean;
  className?: string;
}

interface StrategyOption {
  value: CallQueueStrategy;
  label: string;
  description: string;
  icon: typeof Users;
  color: string;
}

const strategies: StrategyOption[] = [
  {
    value: 'ring_all',
    label: 'Ring All',
    description: 'Ring all available agents at the same time until one answers',
    icon: Users,
    color: 'primary',
  },
  {
    value: 'round_robin',
    label: 'Round Robin',
    description: 'Rotate through agents, distributing calls evenly',
    icon: RotateCw,
    color: 'success',
  },
  {
    value: 'least_talk_time',
    label: 'Least Talk Time',
    description: 'Offer the call to the agent with the least talk time',
    icon: Timer,
    color: 'warning',
  },
  {
    value: 'fewest_calls',
    label: 'Fewest Calls',
    description: 'Offer the call to the agent with the fewest handled calls',
    icon: BarChart3,
    color: 'primary',
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

export function CallQueueStrategySelector({
  value,
  onChange,
  disabled = false,
  className,
}: CallQueueStrategySelectorProps) {
  return (
    <div className={cn('space-y-2', className)}>
      <Label>Agent Selection Strategy</Label>
      <div className="grid grid-cols-2 md:grid-cols-4 gap-4">
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
                <div
                  className={cn(
                    'h-12 w-12 rounded-lg flex items-center justify-center',
                    isSelected ? colors.bg : 'bg-neutral-50'
                  )}
                >
                  <StrategyIcon
                    className={cn('h-6 w-6', isSelected ? colors.icon : 'text-neutral-400')}
                  />
                </div>
                <div>
                  <h3
                    className={cn(
                      'font-semibold text-sm',
                      isSelected ? 'text-neutral-900' : 'text-neutral-700'
                    )}
                  >
                    {strategy.label}
                  </h3>
                  <p className="text-xs text-neutral-500 mt-1">{strategy.description}</p>
                </div>
              </div>
            </Card>
          );
        })}
      </div>
    </div>
  );
}
