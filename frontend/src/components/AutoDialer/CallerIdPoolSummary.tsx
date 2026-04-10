/**
 * CallerIdPoolSummary Component
 *
 * Displays a summary of the selected Caller ID pool including
 * distribution percentages based on weights.
 *
 * @example
 * <CallerIdPoolSummary
 *   pool={[
 *     { did_id: 1, phone_number: "+1234567890", weight: 2 },
 *     { did_id: 2, phone_number: "+1234567891", weight: 1 }
 *   ]}
 * />
 */

import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Phone, Hash, Percent } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { CallerIdPoolItem } from './CallerIdPoolSelector';

interface CallerIdPoolSummaryProps {
  pool: CallerIdPoolItem[];
  className?: string;
}

export function CallerIdPoolSummary({ pool, className }: CallerIdPoolSummaryProps) {
  // Don't render if pool is empty
  if (pool.length === 0) {
    return null;
  }

  const totalWeight = pool.reduce((sum, item) => sum + item.weight, 0);
  const totalNumbers = pool.length;

  // Calculate distribution percentages
  const poolWithPercentage = pool.map((item) => ({
    ...item,
    percentage: totalWeight > 0 ? (item.weight / totalWeight) * 100 : 0,
  }));

  return (
    <Card className={cn('bg-muted/50', className)}>
      <CardHeader className="pb-3">
        <div className="flex items-center justify-between">
          <CardTitle className="text-sm font-medium flex items-center gap-2">
            <Hash className="h-4 w-4 text-muted-foreground" />
            Pool Summary
          </CardTitle>
          <Badge variant="secondary" className="text-xs">
            {totalNumbers} number{totalNumbers !== 1 ? 's' : ''}
          </Badge>
        </div>
      </CardHeader>
      <CardContent className="pt-0">
        <div className="space-y-3">
          {poolWithPercentage.map((item) => (
            <div key={item.did_id} className="space-y-1.5">
              <div className="flex items-center justify-between text-sm">
                <div className="flex items-center gap-2 min-w-0">
                  <Phone className="h-3.5 w-3.5 text-muted-foreground shrink-0" />
                  <span className="font-medium truncate">
                    {item.friendly_name || item.phone_number}
                  </span>
                  {item.friendly_name && (
                    <span className="text-muted-foreground text-xs truncate hidden sm:inline">
                      ({item.phone_number})
                    </span>
                  )}
                </div>
                <div className="flex items-center gap-3 shrink-0">
                  <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Percent className="h-3 w-3" />
                    <span className="tabular-nums">
                      {item.percentage.toFixed(1)}%
                    </span>
                  </div>
                  <Badge variant="outline" className="text-xs font-normal">
                    weight: {item.weight}
                  </Badge>
                </div>
              </div>
              <Progress
                value={item.percentage}
                className="h-1.5"
                aria-label={`${item.phone_number} distribution: ${item.percentage.toFixed(1)}%`}
              />
            </div>
          ))}
        </div>

        {/* Total weight summary */}
        <div className="mt-4 pt-3 border-t border-border/50">
          <div className="flex items-center justify-between text-xs text-muted-foreground">
            <span>Total Weight</span>
            <span className="font-medium tabular-nums">{totalWeight}</span>
          </div>
        </div>
      </CardContent>
    </Card>
  );
}
