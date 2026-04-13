/**
 * Action Selector Component
 *
 * Form component for selecting routing actions (extension, ring_group, ivr_menu)
 * for business hours open/closed actions.
 */

import React from 'react';
import { Phone, Menu, Users, Bot, ArrowRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { BusinessHoursAction, BusinessHoursActionType } from '@/types';
import type { Extension, RingGroup, IvrMenu, ConferenceRoom } from '@/types';

interface ActionSelectorProps {
  label: string;
  value: BusinessHoursAction | null;
  onChange: (action: BusinessHoursAction) => void;
  error?: string;
  extensions: Extension[];
  ringGroups: RingGroup[];
  ivrMenus: IvrMenu[];
  conferenceRooms: ConferenceRoom[];
}

const actionTypes = [
  { value: 'extension', label: 'Extension', icon: Phone },
  { value: 'ivr_menu', label: 'IVR Menu', icon: Menu },
  { value: 'ring_group', label: 'Ring Group', icon: Users },
];

const getTypeConfig = (type: string) => {
  const configs = {
    user: { label: 'PBX User', color: 'bg-blue-100 text-blue-800 border-blue-200', icon: Phone },
    conference: { label: 'Conference', color: 'bg-purple-100 text-purple-800 border-purple-200', icon: Users },
    ring_group: { label: 'Ring Group', color: 'bg-orange-100 text-orange-800 border-orange-200', icon: Phone },
    ivr: { label: 'IVR Menu', color: 'bg-green-100 text-green-800 border-green-200', icon: Menu },
    ai_assistant: { label: 'AI Assistant', color: 'bg-cyan-100 text-cyan-800 border-cyan-200', icon: Bot },
    forward: { label: 'Forward', color: 'bg-indigo-100 text-indigo-800 border-indigo-200', icon: ArrowRight },
  };
  return configs[type as keyof typeof configs] || configs.user;
};

const extractNumericId = (targetId: string): string => {
  if (targetId.startsWith('ext-')) return targetId.substring(4);
  if (targetId.startsWith('rg-')) return targetId.substring(3);
  if (targetId.startsWith('ivr-')) return targetId.substring(4);
  return targetId;
};

export const ActionSelector: React.FC<ActionSelectorProps> = ({
  label,
  value,
  onChange,
  error,
  extensions,
  ringGroups,
  ivrMenus,
}) => {
  const handleTypeChange = (type: BusinessHoursActionType) => {
    onChange({ type, target_id: '' });
  };

  const handleTargetChange = (targetId: string) => {
    if (value) {
      let prefixedTargetId = targetId;

      switch (value.type) {
        case 'extension':
          prefixedTargetId = `ext-${targetId}`;
          break;
        case 'ring_group':
          prefixedTargetId = `rg-${targetId}`;
          break;
        case 'ivr_menu':
          prefixedTargetId = `ivr-${targetId}`;
          break;
      }

      onChange({ ...value, target_id: prefixedTargetId });
    }
  };

  const getTargetOptions = () => {
    switch (value?.type) {
      case 'extension':
        return extensions;
      case 'ring_group':
        return ringGroups;
      case 'ivr_menu':
        return ivrMenus;
      default:
        return [];
    }
  };

  const getTargetPlaceholder = () => {
    switch (value?.type) {
      case 'extension':
        return 'Select extension';
      case 'ring_group':
        return 'Select ring group';
      case 'ivr_menu':
        return 'Select IVR menu';
      default:
        return 'Select target';
    }
  };

  const getCurrentTargetValue = () => {
    if (!value?.target_id) return '';
    return extractNumericId(value.target_id);
  };

  const getDisplayName = (ext: Extension) => {
    switch (ext.type) {
      case 'user':
        return ext.user?.name || 'Unassigned';
      case 'forward':
        return ext.configuration?.forward_to || 'Not configured';
      default:
        return ext.name || 'Unnamed';
    }
  };

  return (
    <div className="space-y-2">
      <Label>
        {label} <span className="text-destructive">*</span>
      </Label>

      <Select
        value={value?.type || ''}
        onValueChange={handleTypeChange}
      >
        <SelectTrigger>
          <SelectValue placeholder="Select action type" />
        </SelectTrigger>
        <SelectContent>
          {actionTypes.map((type) => {
            const Icon = type.icon;
            return (
              <SelectItem key={type.value} value={type.value}>
                <div className="flex items-center gap-2">
                  <Icon className="h-4 w-4" />
                  {type.label}
                </div>
              </SelectItem>
            );
          })}
        </SelectContent>
      </Select>

      {value?.type && (
        <Select
          value={getCurrentTargetValue()}
          onValueChange={handleTargetChange}
        >
          <SelectTrigger>
            <SelectValue placeholder={getTargetPlaceholder()} />
          </SelectTrigger>
          <SelectContent>
            {getTargetOptions().map((option) => {
              const typeConfig = getTypeConfig(option.type);
              const Icon = typeConfig.icon;

              return (
                <SelectItem key={option.id} value={option.id.toString()}>
                  <div className="flex items-center gap-2">
                    <span className="font-mono">{option.extension_number}</span>
                    <Badge variant="outline" className={cn('flex items-center gap-1 text-xs', typeConfig.color)}>
                      <Icon className="h-3 w-3" />
                      {typeConfig.label} - {'type' in option ? getDisplayName(option as Extension) : option.name}
                    </Badge>
                  </div>
                </SelectItem>
              );
            })}
          </SelectContent>
        </Select>
      )}

      {error && <p className="text-sm text-destructive">{error}</p>}
      <p className="text-sm text-muted-foreground">
        Where to forward calls during {label.toLowerCase().includes('open') ? 'open' : 'closed'} hours
      </p>
    </div>
  );
};

export default ActionSelector;
