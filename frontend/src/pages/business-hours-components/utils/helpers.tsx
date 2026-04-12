/**
 * Helper Functions for Business Hours
 *
 * Utility functions for displaying and formatting business hours data.
 */

import React from 'react';
import { Phone, Users, Menu, Bot, ArrowRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { BusinessHoursAction, WeeklySchedule, DaySchedule, DayOfWeek, TimeRange } from '@/types';
import type { Extension, RingGroup } from '@/types';

let timeRangeIdCounter = 0;
let exceptionIdCounter = 0;

export function getNextTimeRangeId(): string {
  return `tr-${Date.now()}-${timeRangeIdCounter++}`;
}

export function getNextExceptionId(): string {
  return `exc-${Date.now()}-${exceptionIdCounter++}`;
}

export function createEmptyWeeklySchedule(): WeeklySchedule {
  const emptyDaySchedule: DaySchedule = {
    enabled: false,
    time_ranges: [],
  };

  return {
    monday: { ...emptyDaySchedule },
    tuesday: { ...emptyDaySchedule },
    wednesday: { ...emptyDaySchedule },
    thursday: { ...emptyDaySchedule },
    friday: { ...emptyDaySchedule },
    saturday: { ...emptyDaySchedule },
    sunday: { ...emptyDaySchedule },
  };
}

export function applyScheduleTemplate(template: string): WeeklySchedule {
  const newSchedule = createEmptyWeeklySchedule();

  switch (template) {
    case 'mon-fri-business':
      ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'].forEach(day => {
        newSchedule[day as DayOfWeek] = {
          enabled: true,
          time_ranges: [{
            id: getNextTimeRangeId(),
            start_time: '09:00',
            end_time: '17:00'
          }]
        };
      });
      ['saturday', 'sunday'].forEach(day => {
        newSchedule[day as DayOfWeek] = {
          enabled: false,
          time_ranges: []
        };
      });
      break;

    case 'mon-fri-all-day':
      ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'].forEach(day => {
        newSchedule[day as DayOfWeek] = {
          enabled: true,
          time_ranges: [{
            id: getNextTimeRangeId(),
            start_time: '00:00',
            end_time: '23:59'
          }]
        };
      });
      ['saturday', 'sunday'].forEach(day => {
        newSchedule[day as DayOfWeek] = {
          enabled: false,
          time_ranges: []
        };
      });
      break;

    case 'sun-thu-business':
      ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'].forEach(day => {
        newSchedule[day as DayOfWeek] = {
          enabled: true,
          time_ranges: [{
            id: getNextTimeRangeId(),
            start_time: '09:00',
            end_time: '17:00'
          }]
        };
      });
      ['friday', 'saturday'].forEach(day => {
        newSchedule[day as DayOfWeek] = {
          enabled: false,
          time_ranges: []
        };
      });
      break;

    case 'sun-thu-all-day':
      ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'].forEach(day => {
        newSchedule[day as DayOfWeek] = {
          enabled: true,
          time_ranges: [{
            id: getNextTimeRangeId(),
            start_time: '00:00',
            end_time: '23:59'
          }]
        };
      });
      ['friday', 'saturday'].forEach(day => {
        newSchedule[day as DayOfWeek] = {
          enabled: false,
          time_ranges: []
        };
      });
      break;

    case '24-7':
      Object.keys(newSchedule).forEach(day => {
        newSchedule[day as DayOfWeek] = {
          enabled: true,
          time_ranges: [{
            id: getNextTimeRangeId(),
            start_time: '00:00',
            end_time: '23:59'
          }]
        };
      });
      break;
  }

  return newSchedule;
}

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

/**
 * Get action display name for both structured and legacy formats
 */
export const getActionDisplayName = (
  action: unknown,
  extensions: Extension[],
  ringGroups: RingGroup[],
  ivrMenus: Extension[]
): JSX.Element => {
  if (!action) return <span className="text-muted-foreground">Not set</span>;

  if (typeof action === 'object' && (action as BusinessHoursAction).type && (action as BusinessHoursAction).target_id) {
    const actionObj = action as BusinessHoursAction;

    let targetOption: Extension | RingGroup | undefined = undefined;
    let displayName = '';
    let displayType = '';

    const numericId = extractNumericId(actionObj.target_id);

    switch (actionObj.type) {
      case 'extension':
        targetOption = extensions.find(e => e.id.toString() === numericId);
        displayName = targetOption?.name || `Extension ${actionObj.target_id}`;
        displayType = targetOption?.type || 'user';
        break;
      case 'ring_group':
        targetOption = ringGroups.find(g => g.id.toString() === numericId);
        displayName = targetOption?.name || `Ring Group ${actionObj.target_id}`;
        displayType = 'ring_group';
        break;
      case 'ivr_menu':
        targetOption = ivrMenus.find(m => m.id.toString() === numericId);
        displayName = targetOption?.name || `IVR Menu ${actionObj.target_id}`;
        displayType = 'ivr';
        break;
      default:
        displayName = `${actionObj.type}: ${actionObj.target_id}`;
        displayType = 'user';
    }

    const typeConfig = getTypeConfig(displayType);
    const Icon = typeConfig.icon;
    const extNumber = targetOption && 'extension_number' in targetOption ? targetOption.extension_number : numericId;
    return (
      <div className="flex items-center gap-2">
        <span className="font-mono">{extNumber}</span>
        <Badge variant="outline" className={cn('flex items-center gap-1 text-xs', typeConfig.color)}>
          <Icon className="h-3 w-3" />
          {typeConfig.label} - {displayName}
        </Badge>
      </div>
    );
  }

  if (typeof action === 'string') {
    const ext = extensions.find(e => e.id === action);
    return <span>{ext?.name || action}</span>;
  }

  return <span className="text-muted-foreground">Unknown</span>;
};
