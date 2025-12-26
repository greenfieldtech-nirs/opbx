/**
 * EmptyState Component
 *
 * Displays a helpful empty state with icon, message, and optional action
 * Used across pages when no data is available
 *
 * @example
 * <EmptyState
 *   icon={PhoneCall}
 *   title="No active calls"
 *   description="When calls come in, they'll appear here"
 * />
 *
 * <EmptyState
 *   icon={Users}
 *   title="No users yet"
 *   description="Get started by adding your first user"
 *   action={{ label: "Add User", onClick: () => {} }}
 * />
 */

import { Button } from '@/components/ui/button';
import { LucideIcon } from 'lucide-react';
import { cn } from '@/lib/utils';

interface EmptyStateProps {
  icon: LucideIcon;
  title: string;
  description?: string;
  action?: {
    label: string;
    onClick: () => void;
    variant?: 'default' | 'outline';
  };
  className?: string;
}

export function EmptyState({
  icon: Icon,
  title,
  description,
  action,
  className,
}: EmptyStateProps) {
  return (
    <div
      className={cn(
        'flex flex-col items-center justify-center p-12 text-center',
        className
      )}
    >
      {/* Icon */}
      <div className="h-16 w-16 rounded-full bg-neutral-100 flex items-center justify-center mb-4">
        <Icon className="h-8 w-8 text-neutral-400" />
      </div>

      {/* Title */}
      <h3 className="text-lg font-semibold text-neutral-900 mb-2">{title}</h3>

      {/* Description */}
      {description && (
        <p className="text-sm text-neutral-500 max-w-sm mb-6">{description}</p>
      )}

      {/* Action Button */}
      {action && (
        <Button
          onClick={action.onClick}
          variant={action.variant || 'default'}
          size="lg"
        >
          {action.label}
        </Button>
      )}
    </div>
  );
}
