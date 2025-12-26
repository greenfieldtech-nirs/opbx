/**
 * ExtensionCard Component
 *
 * Displays extension information in a card format
 * Used in Extensions page grid view
 *
 * Features:
 * - Large extension number
 * - User avatar/initials
 * - Type badge (User/Virtual/Queue)
 * - Status indicator
 * - Actions menu
 *
 * @example
 * <ExtensionCard
 *   extension={{
 *     number: '101',
 *     user: { name: 'John Doe', avatar: null },
 *     type: 'user',
 *     status: 'active',
 *   }}
 *   onEdit={() => {}}
 *   onDelete={() => {}}
 * />
 */

import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Hash, MoreVertical, Pencil, Trash2, User, Users, Inbox } from 'lucide-react';
import { cn } from '@/lib/utils';

export type ExtensionType = 'user' | 'virtual' | 'queue';
export type ExtensionStatus = 'active' | 'inactive';

export interface ExtensionData {
  id: string;
  number: string;
  user?: {
    id: string;
    name: string;
    avatar?: string | null;
  } | null;
  type: ExtensionType;
  status: ExtensionStatus;
  description?: string;
}

interface ExtensionCardProps {
  extension: ExtensionData;
  onEdit: () => void;
  onDelete: () => void;
  className?: string;
}

// Type display configurations
const typeConfig: Record<
  ExtensionType,
  { label: string; icon: typeof User; color: string }
> = {
  user: {
    label: 'User',
    icon: User,
    color: 'bg-primary-100 text-primary-700 border-primary-300',
  },
  virtual: {
    label: 'Virtual',
    icon: Hash,
    color: 'bg-purple-100 text-purple-700 border-purple-300',
  },
  queue: {
    label: 'Queue',
    icon: Inbox,
    color: 'bg-orange-100 text-orange-700 border-orange-300',
  },
};

// Get user initials for avatar
function getInitials(name: string): string {
  return name
    .split(' ')
    .map((n) => n[0])
    .join('')
    .toUpperCase()
    .slice(0, 2);
}

export function ExtensionCard({
  extension,
  onEdit,
  onDelete,
  className,
}: ExtensionCardProps) {
  const typeInfo = typeConfig[extension.type];
  const TypeIcon = typeInfo.icon;

  return (
    <Card
      className={cn(
        'hover:shadow-md transition-all',
        extension.status === 'inactive' && 'opacity-60',
        className
      )}
    >
      <CardHeader className="pb-3">
        <div className="flex items-start justify-between">
          {/* Extension Number */}
          <div className="flex items-center gap-3">
            <div className="h-12 w-12 rounded-lg bg-primary-50 flex items-center justify-center">
              <Hash className="h-6 w-6 text-primary-600" />
            </div>
            <div>
              <div className="text-2xl font-bold text-neutral-900">
                {extension.number}
              </div>
              {/* Status Indicator */}
              <div className="flex items-center gap-1.5 mt-1">
                <span
                  className={cn(
                    'inline-block w-2 h-2 rounded-full',
                    extension.status === 'active' ? 'bg-success-500' : 'bg-neutral-400'
                  )}
                />
                <span className="text-xs text-neutral-500 capitalize">
                  {extension.status}
                </span>
              </div>
            </div>
          </div>

          {/* Actions Menu */}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" className="h-8 w-8">
                <MoreVertical className="h-4 w-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem onClick={onEdit}>
                <Pencil className="h-4 w-4 mr-2" />
                Edit
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={onDelete} className="text-danger-600">
                <Trash2 className="h-4 w-4 mr-2" />
                Delete
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </CardHeader>

      <CardContent className="space-y-3">
        {/* Type Badge */}
        <Badge className={cn('inline-flex items-center gap-1 border', typeInfo.color)}>
          <TypeIcon className="h-3 w-3" />
          {typeInfo.label}
        </Badge>

        {/* User Info or Unassigned */}
        {extension.user ? (
          <div className="flex items-center gap-2">
            {/* User Avatar */}
            {extension.user.avatar ? (
              <img
                src={extension.user.avatar}
                alt={extension.user.name}
                className="h-8 w-8 rounded-full object-cover"
              />
            ) : (
              <div className="h-8 w-8 rounded-full bg-neutral-200 flex items-center justify-center">
                <span className="text-xs font-medium text-neutral-600">
                  {getInitials(extension.user.name)}
                </span>
              </div>
            )}
            <div>
              <p className="text-sm font-medium text-neutral-900">
                {extension.user.name}
              </p>
            </div>
          </div>
        ) : (
          <div className="flex items-center gap-2 text-neutral-500">
            <User className="h-4 w-4" />
            <span className="text-sm">Unassigned</span>
          </div>
        )}

        {/* Optional Description */}
        {extension.description && (
          <p className="text-xs text-neutral-500 line-clamp-2">
            {extension.description}
          </p>
        )}
      </CardContent>
    </Card>
  );
}
