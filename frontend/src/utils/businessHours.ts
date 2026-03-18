/**
 * Business Hours Utility Functions
 */

import type {
  BusinessHoursAction,
  BusinessHoursSchedule,
  DayOfWeek,
  TimeRange,
  DaySchedule,
  WeeklySchedule,
  ExceptionType,
  ExceptionDate,
  ScheduleStatus,
  Country,
} from '@/types';

export type RoutingAction = 'extension' | 'ring_group' | 'voicemail' | 'announcement' | 'hangup';

export interface DidBusinessHours {
  did_number_id: string;
  phone_number: string;
  name: string;
  business_hours_schedule_id: string;
  business_hours_action: RoutingAction;
  business_hours_target?: string;
  after_hours_action: RoutingAction;
  after_hours_target?: string;
  exception_action?: RoutingAction;
  exception_target?: string;
}

export interface Holiday {
  date: string;
  name: string;
}

// ID Generators
let scheduleIdCounter = 4;
let exceptionIdCounter = 5;
let timeRangeIdCounter = 20;

export function getNextScheduleId(): string {
  return `schedule-${scheduleIdCounter++}`;
}

export function getNextExceptionId(): string {
  return `exception-${exceptionIdCounter++}`;
}

export function getNextTimeRangeId(): string {
  return `tr-${timeRangeIdCounter++}`;
}

/**
 * Get schedule summary text (e.g., "Mon-Fri 9:00-17:00")
 */
export function getScheduleSummary(schedule: WeeklySchedule): string {
  const days: DayOfWeek[] = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
  const dayAbbr: Record<DayOfWeek, string> = {
    monday: 'Mon',
    tuesday: 'Tue',
    wednesday: 'Wed',
    thursday: 'Thu',
    friday: 'Fri',
    saturday: 'Sat',
    sunday: 'Sun'
  };

  const enabledDays = days.filter(day => schedule[day].enabled && schedule[day].time_ranges.length > 0);

  if (enabledDays.length === 0) {
    return 'Closed all days';
  }

  const is24x7 = enabledDays.length === 7 && enabledDays.every(day => {
    const ranges = schedule[day].time_ranges;
    return ranges.length === 1 && ranges[0].start_time === '00:00' && ranges[0].end_time === '23:59';
  });

  if (is24x7) {
    return 'Open 24 hours, all days';
  }

  const weekdays: DayOfWeek[] = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'];
  const weekend: DayOfWeek[] = ['saturday', 'sunday'];

  const weekdayEnabled = weekdays.every(day => schedule[day].enabled && schedule[day].time_ranges.length > 0);
  const weekendClosed = weekend.every(day => !schedule[day].enabled || schedule[day].time_ranges.length === 0);

  if (weekdayEnabled && weekendClosed) {
    const firstRange = schedule.monday.time_ranges[0];
    const allSameHours = weekdays.every(day => {
      const ranges = schedule[day].time_ranges;
      return ranges.length === 1 &&
             ranges[0].start_time === firstRange.start_time &&
             ranges[0].end_time === firstRange.end_time;
    });

    if (allSameHours) {
      return `Mon-Fri ${firstRange.start_time}-${firstRange.end_time}`;
    }
  }

  const firstEnabled = enabledDays[0];
  const firstRange = schedule[firstEnabled].time_ranges[0];
  if (enabledDays.length === 1) {
    return `${dayAbbr[firstEnabled]} ${firstRange.start_time}-${firstRange.end_time}`;
  }

  return `${enabledDays.length} days configured`;
}

/**
 * Get detailed hours text for a schedule
 */
export function getDetailedHours(schedule: WeeklySchedule): string[] {
  const days: DayOfWeek[] = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
  const dayNames: Record<DayOfWeek, string> = {
    monday: 'Monday',
    tuesday: 'Tuesday',
    wednesday: 'Wednesday',
    thursday: 'Thursday',
    friday: 'Friday',
    saturday: 'Saturday',
    sunday: 'Sunday'
  };

  const result: string[] = [];
  let currentGroup: DayOfWeek[] = [];
  let currentSchedule: string | null = null;

  days.forEach((day, index) => {
    const daySchedule = schedule[day];
    let scheduleText: string;

    if (!daySchedule.enabled || daySchedule.time_ranges.length === 0) {
      scheduleText = 'Closed';
    } else if (daySchedule.time_ranges.length === 1) {
      const range = daySchedule.time_ranges[0];
      scheduleText = `${range.start_time}-${range.end_time}`;
    } else {
      scheduleText = daySchedule.time_ranges.map(r => `${r.start_time}-${r.end_time}`).join(', ');
    }

    if (scheduleText === currentSchedule) {
      currentGroup.push(day);
    } else {
      if (currentGroup.length > 0 && currentSchedule) {
        result.push(formatDayGroup(currentGroup, currentSchedule, dayNames));
      }
      currentGroup = [day];
      currentSchedule = scheduleText;
    }

    if (index === days.length - 1 && currentGroup.length > 0 && currentSchedule) {
      result.push(formatDayGroup(currentGroup, currentSchedule, dayNames));
    }
  });

  return result;
}

function formatDayGroup(days: DayOfWeek[], schedule: string, dayNames: Record<DayOfWeek, string>): string {
  if (days.length === 1) {
    return `${dayNames[days[0]]}: ${schedule}`;
  } else if (days.length === 2) {
    return `${dayNames[days[0]]}-${dayNames[days[1]]}: ${schedule}`;
  } else {
    return `${dayNames[days[0]]}-${dayNames[days[days.length - 1]]}: ${schedule}`;
  }
}

/**
 * Get routing action display text
 */
export function getRoutingActionText(action: RoutingAction): string {
  const actionMap: Record<RoutingAction, string> = {
    extension: 'Extension',
    ring_group: 'Ring Group',
    voicemail: 'Voicemail',
    announcement: 'Announcement',
    hangup: 'Hangup'
  };
  return actionMap[action];
}

/**
 * Validate time format (HH:mm)
 */
export function isValidTimeFormat(time: string): boolean {
  const timeRegex = /^([0-1][0-9]|2[0-3]):[0-5][0-9]$/;
  return timeRegex.test(time);
}

/**
 * Check if end time is after start time
 */
export function isEndTimeAfter(startTime: string, endTime: string): boolean {
  const [startHour, startMin] = startTime.split(':').map(Number);
  const [endHour, endMin] = endTime.split(':').map(Number);

  const startMinutes = startHour * 60 + startMin;
  const endMinutes = endHour * 60 + endMin;

  return endMinutes > startMinutes;
}

/**
 * Format date for display
 */
export function formatExceptionDate(dateString: string): string {
  const date = new Date(dateString);
  return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}

// Re-export types for convenience
export type {
  BusinessHoursAction,
  BusinessHoursSchedule,
  DayOfWeek,
  TimeRange,
  DaySchedule,
  WeeklySchedule,
  ExceptionType,
  ExceptionDate,
  ScheduleStatus,
  Country,
};
