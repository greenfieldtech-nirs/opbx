/**
 * OrganizationStatusBadge Component
 *
 * Displays organization status with appropriate styling.
 */

import { Building2, PauseCircle, Trash2 } from 'lucide-react';
import { cn } from '@/lib/utils';

type OrganizationStatus = 'active' | 'suspended' | 'deleted';

interface OrganizationStatusBadgeProps {
  status: OrganizationStatus;
  className?: string;
}

const statusConfig: Record<OrganizationStatus, { 
  label: string; 
  icon: React.ElementType;
  classes: string;
}> = {
  active: {
    label: 'Active',
    icon: Building2,
    classes: 'bg-green-100 text-green-800 border-green-200',
  },
  suspended: {
    label: 'Suspended',
    icon: PauseCircle,
    classes: 'bg-yellow-100 text-yellow-800 border-yellow-200',
  },
  deleted: {
    label: 'Deleted',
    icon: Trash2,
    classes: 'bg-red-100 text-red-800 border-red-200',
  },
};

export function OrganizationStatusBadge({ status, className }: OrganizationStatusBadgeProps) {
  const config = statusConfig[status];
  const Icon = config.icon;

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-xs font-medium border',
        config.classes,
        className
      )}
    >
      <Icon className="h-3.5 w-3.5" />
      {config.label}
    </span>
  );
}
