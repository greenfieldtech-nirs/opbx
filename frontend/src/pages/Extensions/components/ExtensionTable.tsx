import React from 'react';
import { ChevronUp, ChevronDown, Phone, Users, Menu, Bot, ArrowRight, Scale, Eye, EyeOff, Copy } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { Extension, ExtensionType } from '@/types';
import { cn } from '@/lib/utils';
import { getStatusColor } from '@/utils/formatters';

interface ExtensionTableProps {
  extensions: Extension[];
  isLoading: boolean;
  visiblePasswords: Set<string>;
  tempPasswords: Map<string, string>;
  sortField: 'extension_number' | 'type' | 'status' | 'created_at';
  sortDirection: 'asc' | 'desc' | null;
  isReadOnly: boolean;
  canCreate: boolean;
  conferenceRooms: any[];
  ringGroups: any[];
  ivrMenus: any[];
  aiAssistants: any[];
  aiLoadBalancers: any[];
  onSort: (field: 'extension_number' | 'type' | 'status' | 'created_at') => void;
  onRowClick: (extension: Extension) => void;
  onDelete: (extension: Extension) => void;
  onUpdateStatus: (id: string, status: string) => void;
  onTogglePasswordVisibility: (id: string) => void;
  onCopyPassword: (password: string, extensionNumber: string) => void;
}

const getTypeBadge = (type: ExtensionType) => {
  const configs = {
    user: { label: 'PBX User', color: 'bg-blue-100 text-blue-800 border-blue-200', icon: Phone },
    conference: { label: 'Conference', color: 'bg-purple-100 text-purple-800 border-purple-200', icon: Users },
    ring_group: { label: 'Ring Group', color: 'bg-orange-100 text-orange-800 border-orange-200', icon: Phone },
    ivr: { label: 'IVR Menu', color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
    ai_assistant: { label: 'AI Assistant', color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
    ai_load_balancer: { label: 'AI Load Balancer', color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Scale },
    forward: { label: 'Forward', color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: ArrowRight },
  };

  const config = configs[type] || configs.user;
  const Icon = config.icon;

  return (
    <Badge variant="outline" className={cn('flex items-center gap-1.5 w-fit', config.color)}>
      <Icon className="h-3.5 w-3.5" />
      {config.label}
    </Badge>
  );
};

const getDetailsBadge = (extension: Extension, conferenceRooms: any[], ringGroups: any[], ivrMenus: any[], aiAssistants: any[], aiLoadBalancers: any[]) => {
  const getBadgeConfig = (type: ExtensionType) => {
    const configs = {
      user: { color: 'bg-blue-100 text-blue-800 border-blue-200', icon: Phone },
      conference: { color: 'bg-purple-100 text-purple-800 border-purple-200', icon: Users },
      ring_group: { color: 'bg-orange-100 text-orange-800 border-orange-200', icon: Phone },
      ivr: { color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
      ai_assistant: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
      ai_load_balancer: { color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Scale },
      forward: { color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: ArrowRight },
    };
    return configs[type] || configs.user;
  };

  const getBadgeContent = (extension: Extension) => {
    switch (extension.type) {
      case 'user':
        return extension.user ? extension.user.name : 'Unassigned';
      case 'conference': {
        const conferenceRoomId = extension.configuration?.conference_room_id;
        if (conferenceRoomId) {
          const conferenceRoom = conferenceRooms.find(room => room.id == conferenceRoomId);
          return conferenceRoom ? conferenceRoom.name : `ID ${conferenceRoomId}`;
        }
        return 'Not configured';
      }
      case 'ring_group': {
        const ringGroupId = extension.configuration?.ring_group_id;
        if (ringGroupId) {
          const ringGroup = ringGroups.find(group => group.id == ringGroupId);
          return ringGroup ? ringGroup.name : `ID ${ringGroupId}`;
        }
        return 'Not configured';
      }
      case 'ivr': {
        let ivrId: string | null = null;
        if (typeof extension.configuration === 'object' && extension.configuration) {
          ivrId = extension.configuration.ivr_id || extension.configuration.ivr_menu_id;
        } else {
          ivrId = String(extension.configuration);
        }
        if (ivrId) {
          const ivrMenu = ivrMenus.find(menu => menu.id == ivrId);
          return ivrMenu ? ivrMenu.name : `ID ${ivrId}`;
        }
        return 'Not configured';
      }
      case 'ai_assistant': {
        if (extension.ai_assistant) {
          return extension.ai_assistant.name;
        }
        const assistantId = extension.configuration?.ai_assistant_id || extension.ai_assistant_id;
        return assistantId ? `AI Assistant #${assistantId}` : 'Not configured';
      }
      case 'ai_load_balancer': {
        if (extension.ai_load_balancer) {
          return extension.ai_load_balancer.name;
        }
        const loadBalancerId = extension.configuration?.ai_load_balancer_id;
        return loadBalancerId ? `AI Load Balancer #${loadBalancerId}` : 'Not configured';
      }
      case 'forward': {
        return extension.configuration?.forward_to || 'Not configured';
      }
      default:
        return 'Unknown';
    }
  };

  const config = getBadgeConfig(extension.type);
  const Icon = config.icon;
  const content = getBadgeContent(extension);

  return (
    <Badge variant="outline" className={cn('flex items-center gap-1.5 w-fit', config.color)}>
      <Icon className="h-3.5 w-3.5" />
      {content}
    </Badge>
  );
};

export const ExtensionTable: React.FC<ExtensionTableProps> = ({
  extensions,
  isLoading,
  visiblePasswords,
  tempPasswords,
  sortField,
  sortDirection,
  isReadOnly,
  canCreate,
  conferenceRooms,
  ringGroups,
  ivrMenus,
  aiAssistants,
  aiLoadBalancers,
  onSort,
  onRowClick,
  onDelete,
  onUpdateStatus,
  onTogglePasswordVisibility,
  onCopyPassword,
}) => {
  const getSortIcon = (field: typeof sortField) => {
    if (sortField !== field) return null;
    return sortDirection === 'asc' ? <ChevronUp className="h-4 w-4" /> : <ChevronDown className="h-4 w-4" />;
  };

  if (isLoading) {
    return (
      <div className="space-y-4">
        {[...Array(5)].map((_, i) => (
          <div key={i} className="h-16 bg-muted/50 rounded animate-pulse" />
        ))}
      </div>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full">
        <thead>
          <tr className="border-b">
            <th className="text-left p-3 font-medium text-sm">
              <button
                className="flex items-center gap-1 hover:text-primary"
                onClick={() => onSort('extension_number')}
              >
                Extension {getSortIcon('extension_number')}
              </button>
            </th>
            {extensions.some(ext => ext.type === 'user') && !isReadOnly && (
              <th className="text-left p-3 font-medium text-sm">Password</th>
            )}
            <th className="text-left p-3 font-medium text-sm">
              <button
                className="flex items-center gap-1 hover:text-primary"
                onClick={() => onSort('type')}
              >
                Type {getSortIcon('type')}
              </button>
            </th>
            <th className="text-left p-3 font-medium text-sm">Linked To</th>
            <th className="text-left p-3 font-medium text-sm">
              <button
                className="flex items-center gap-1 hover:text-primary"
                onClick={() => onSort('created_at')}
              >
                Created {getSortIcon('created_at')}
              </button>
            </th>
            <th className="text-left p-3 font-medium text-sm">
              <button
                className="flex items-center gap-1 hover:text-primary"
                onClick={() => onSort('status')}
              >
                Status {getSortIcon('status')}
              </button>
            </th>
            {canCreate && <th className="text-right p-3 font-medium text-sm">Actions</th>}
          </tr>
        </thead>
        <tbody>
          {extensions.map((extension) => (
            <tr
              key={extension.id}
              className="border-b hover:bg-muted/50 cursor-pointer"
              onClick={() => onRowClick(extension)}
            >
              <td className="p-3">
                <div className="flex items-center gap-3">
                  <div className="h-10 w-10 rounded-full bg-blue-100 flex items-center justify-center">
                    <Phone className="h-5 w-5 text-blue-600" />
                  </div>
                  <div>
                    <p className="font-mono font-medium">{extension.extension_number}</p>
                    <p className="text-xs text-muted-foreground capitalize">{extension.type.replace('_', ' ')} Extension</p>
                  </div>
                </div>
              </td>
              {extensions.some(ext => ext.type === 'user') && !isReadOnly && (
                <td className="p-3">
                  {extension.type === 'user' ? (
                    <div className="flex items-center gap-2">
                      <span className="font-mono text-sm">
                        {visiblePasswords.has(extension.id) ? (tempPasswords.get(extension.id) || extension.sip_config?.password || 'Not set') : '••••••••••••••••'}
                      </span>
                      <div className="flex items-center gap-1">
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 p-0"
                          onClick={(e) => {
                            e.stopPropagation();
                            onTogglePasswordVisibility(extension.id);
                          }}
                        >
                          {visiblePasswords.has(extension.id) ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                        </Button>
                        <Button
                          variant="ghost"
                          size="sm"
                          className="h-7 w-7 p-0"
                          onClick={(e) => {
                            e.stopPropagation();
                            onCopyPassword(tempPasswords.get(extension.id) || extension.sip_config?.password || 'Not set', extension.extension_number);
                          }}
                        >
                          <Copy className="h-4 w-4" />
                        </Button>
                      </div>
                    </div>
                  ) : (
                    <span className="text-muted-foreground">-</span>
                  )}
                </td>
              )}
              <td className="p-3">{getTypeBadge(extension.type)}</td>
              <td className="p-3">{getDetailsBadge(extension, conferenceRooms, ringGroups, ivrMenus, aiAssistants, aiLoadBalancers)}</td>
              <td className="p-3">
                <span className="text-sm text-muted-foreground">
                  {new Date(extension.created_at).toLocaleDateString()}
                </span>
              </td>
              <td className="p-3">
                <Badge
                  className={cn(
                    getStatusColor(extension.status),
                    "text-xs",
                    !isReadOnly && "cursor-pointer hover:opacity-80 transition-opacity"
                  )}
                  onClick={(e) => {
                    e.stopPropagation();
                    if (!isReadOnly) {
                      onUpdateStatus(extension.id, extension.status === 'active' ? 'inactive' : 'active');
                    }
                  }}
                >
                  {extension.status}
                </Badge>
              </td>
              {canCreate && (
                <td className="p-3 text-right">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={(e) => {
                      e.stopPropagation();
                      onDelete(extension);
                    }}
                  >
                    Delete
                  </Button>
                </td>
              )}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
};
