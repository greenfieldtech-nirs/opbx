/**
 * StatCard Component
 *
 * Displays statistical information with icon and optional trend
 * Used on Dashboard for key metrics
 *
 * @example
 * <StatCard
 *   title="Active Calls"
 *   value={5}
 *   icon={Activity}
 *   color="success"
 *   trend={{ value: 12, direction: 'up' }}
 * />
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ArrowUp, ArrowDown, LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

export type StatCardColor = 'primary' | 'success' | 'warning' | 'danger' | 'neutral';

interface StatCardProps {
  title: string;
  value: number | string;
  icon: LucideIcon;
  color?: StatCardColor;
  trend?: {
    value: number;
    direction: 'up' | 'down';
  };
  description?: string;
  className?: string;
  loading?: boolean;
}

// Color mappings for icon backgrounds
const colorStyles: Record<StatCardColor, { bg: string; icon: string }> = {
  primary: {
    bg: 'bg-primary-100',
    icon: 'text-primary-600',
  },
  success: {
    bg: 'bg-success-100',
    icon: 'text-success-600',
  },
  warning: {
    bg: 'bg-warning-100',
    icon: 'text-warning-600',
  },
  danger: {
    bg: 'bg-danger-100',
    icon: 'text-danger-600',
  },
  neutral: {
    bg: 'bg-neutral-100',
    icon: 'text-neutral-600',
  },
};

const trendStyles = {
  up: 'text-success-600',
  down: 'text-danger-600',
};

export function StatCard({
  title,
  value,
  icon: Icon,
  color = 'primary',
  trend,
  description,
  className,
  loading = false,
}: StatCardProps) {
  if (loading) {
    return (
      <Card className={cn('animate-pulse', className)}>
        <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
          <div className="h-4 w-24 bg-neutral-200 rounded" />
          <div className="h-10 w-10 bg-neutral-200 rounded-lg" />
        </CardHeader>
        <CardContent>
          <div className="h-8 w-16 bg-neutral-200 rounded mb-1" />
          {description && <div className="h-3 w-32 bg-neutral-200 rounded" />}
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className={cn('hover:shadow-md transition-shadow', className)}>
      <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
        <CardTitle className="text-sm font-medium text-neutral-600">
          {title}
        </CardTitle>
        <div className={cn('p-2 rounded-lg', colorStyles[color].bg)}>
          <Icon className={cn('h-5 w-5', colorStyles[color].icon)} />
        </div>
      </CardHeader>
      <CardContent>
        <div className="flex items-baseline gap-2">
          <div className="text-2xl font-bold text-neutral-900">
            {value}
          </div>
          {trend && (
            <div
              className={cn(
                'flex items-center text-xs font-medium',
                trendStyles[trend.direction]
              )}
            >
              {trend.direction === 'up' ? (
                <ArrowUp className="h-3 w-3 mr-0.5" />
              ) : (
                <ArrowDown className="h-3 w-3 mr-0.5" />
              )}
              {trend.value}%
            </div>
          )}
        </div>
        {description && (
          <p className="text-xs text-neutral-500 mt-1">{description}</p>
        )}
      </CardContent>
    </Card>
  );
}
