/**
 * AlbsStrategySelector Component
 *
 * Visual selector for AI Load Balancer strategies
 * Displays three strategy options with icons and descriptions
 *
 * Strategies:
 * - Round Robin: Distribute calls evenly in sequential order
 * - Priority Based: Route to highest priority AI assistant first
 * - Percentage Based: Route based on configured weight percentages
 *
 * @example
 * <AlbsStrategySelector
 *   value="round_robin"
 *   onChange={(strategy) => setStrategy(strategy)}
 * />
 */

import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { RotateCw, Target, Scale } from 'lucide-react';
import { cn } from '@/lib/utils';

export type AlbsStrategy = 'round_robin' | 'priority' | 'percentage';

interface AlbsStrategySelectorProps {
  value: AlbsStrategy;
  onChange: (value: AlbsStrategy) => void;
  disabled?: boolean;
  className?: string;
}

interface StrategyOption {
  value: AlbsStrategy;
  label: string;
  description: string;
  icon: typeof RotateCw;
  color: string;
}

const strategies: StrategyOption[] = [
  {
    value: 'round_robin',
    label: 'Round Robin',
    description: 'Distribute calls evenly across AI assistants in sequential order',
    icon: RotateCw,
    color: 'primary',
  },
  {
    value: 'priority',
    label: 'Priority Based',
    description: 'Route calls to AI assistants in priority order using drag and drop',
    icon: Target,
    color: 'success',
  },
  {
    value: 'percentage',
    label: 'Percentage Based',
    description: 'Route calls based on configured weight percentages',
    icon: Scale,
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

export function AlbsStrategySelector({
  value,
  onChange,
  disabled = false,
  className,
}: AlbsStrategySelectorProps) {
  return (
    <div className={cn('space-y-2', className)}>
      <Label>Distribution Strategy <span className="text-red-500">*</span></Label>
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
