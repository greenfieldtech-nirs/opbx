/**
 * CallStatusBadge Component
 *
 * Displays call status with semantic color coding
 * Used in call logs, live calls, and dashboard
 *
 * @example
 * <CallStatusBadge status="answered" />
 * <CallStatusBadge status="ringing" size="lg" />
 */

import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import { colors } from '@/styles/tokens';

export type CallStatus =
  | 'initiated'
  | 'ringing'
  | 'answered'
  | 'completed'
  | 'failed'
  | 'busy'
  | 'no_answer';

interface CallStatusBadgeProps {
  status: CallStatus;
  size?: 'sm' | 'md' | 'lg';
  className?: string;
}

// Status display labels
const statusLabels: Record<CallStatus, string> = {
  initiated: 'Initiated',
  ringing: 'Ringing',
  answered: 'Answered',
  completed: 'Completed',
  failed: 'Failed',
  busy: 'Busy',
  no_answer: 'No Answer',
};

// Status color mappings (using design tokens)
const statusStyles: Record<CallStatus, string> = {
  initiated: 'bg-neutral-100 text-neutral-700 border-neutral-300',
  ringing: 'bg-warning-100 text-warning-700 border-warning-300',
  answered: 'bg-success-100 text-success-700 border-success-300',
  completed: 'bg-neutral-100 text-neutral-700 border-neutral-300',
  failed: 'bg-danger-100 text-danger-700 border-danger-300',
  busy: 'bg-danger-100 text-danger-700 border-danger-300',
  no_answer: 'bg-warning-100 text-warning-700 border-warning-300',
};

const sizeStyles = {
  sm: 'text-xs px-2 py-0.5',
  md: 'text-xs px-2.5 py-0.5',
  lg: 'text-sm px-3 py-1',
};

export function CallStatusBadge({
  status,
  size = 'md',
  className,
}: CallStatusBadgeProps) {
  return (
    <Badge
      className={cn(
        'inline-flex items-center font-medium border',
        statusStyles[status],
        sizeStyles[size],
        className
      )}
      variant="outline"
    >
      {/* Optional: Add animated dot for active statuses */}
      {(status === 'ringing' || status === 'answered') && (
        <span
          className={cn(
            'inline-block w-1.5 h-1.5 rounded-full mr-1.5',
            status === 'ringing' ? 'bg-warning-500 animate-pulse' : 'bg-success-500'
          )}
        />
      )}
      {statusLabels[status]}
    </Badge>
  );
}
