/**
 * DestinationBadge Component
 *
 * Display-only component for showing destination information
 * with appropriate icons, colors, and badges.
 */

import { Badge } from '@/components/ui/badge';
import { Phone, Users, Menu, Bot, Scale, Clock, PhoneOff, ArrowRight } from 'lucide-react';
import { cn } from '@/lib/utils';
import type { DestinationBadgeProps } from './types/destination.types';
import { getBadgeConfig } from './utils/destination-config';

// Icon mapping for destination types
const ICON_MAP = {
  user: Phone,
  forward: ArrowRight,
  ring_group: Users,
  conference_room: Users,
  ivr_menu: Menu,
  business_hours: Clock,
  ai_assistant: Bot,
  ai_load_balancer: Scale,
  hangup: PhoneOff,
};

/**
 * Destination Badge Component
 *
 * Renders a badge showing destination type with icon and label.
 * Used for displaying selected destinations in a compact format.
 *
 * @example
 * ```tsx
 * <DestinationBadge
 *   type="extension"
 *   label="Ext 1001 - John Doe"
 *   subType="user"
 *   size="md"
 * />
 * ```
 */
export function DestinationBadge({
  type,
  label,
  subType,
  size = 'md',
  showIcon = true,
  className,
}: DestinationBadgeProps) {
  // Get badge configuration
  const badgeConfig = getBadgeConfig(type, subType);

  // Get icon component
  const Icon = subType
    ? ICON_MAP[subType as keyof typeof ICON_MAP] || Phone
    : ICON_MAP[type as keyof typeof ICON_MAP] || Phone;

  // Size variants
  const sizeClasses = {
    sm: 'text-xs px-1.5 py-0.5 gap-1',
    md: 'text-sm px-2 py-1 gap-1.5',
    lg: 'text-base px-3 py-1.5 gap-2',
  };

  const iconSizes = {
    sm: 'h-3 w-3',
    md: 'h-3.5 w-3.5',
    lg: 'h-4 w-4',
  };

  return (
    <div className={cn('flex items-center gap-2', className)}>
      <Badge
        variant="outline"
        className={cn(
          'flex items-center font-normal whitespace-nowrap',
          badgeConfig.color,
          sizeClasses[size]
        )}
      >
        {showIcon && <Icon className={cn('shrink-0', iconSizes[size])} />}
        <span className="truncate">{badgeConfig.text}</span>
      </Badge>
      <span className={cn(
        'text-foreground truncate',
        size === 'sm' && 'text-xs',
        size === 'md' && 'text-sm',
        size === 'lg' && 'text-base'
      )}>
        {label}
      </span>
    </div>
  );
}

export default DestinationBadge;
