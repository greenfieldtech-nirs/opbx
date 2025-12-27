/**
 * Mock data for Business Hours feature
 */

// ============================================================================
// Type Definitions
// ============================================================================

export type DayOfWeek = 'monday' | 'tuesday' | 'wednesday' | 'thursday' | 'friday' | 'saturday' | 'sunday';

export interface TimeRange {
  id: string;
  start_time: string; // HH:mm format
  end_time: string;   // HH:mm format
}

export interface DaySchedule {
  enabled: boolean;
  time_ranges: TimeRange[];
}

export interface WeeklySchedule {
  monday: DaySchedule;
  tuesday: DaySchedule;
  wednesday: DaySchedule;
  thursday: DaySchedule;
  friday: DaySchedule;
  saturday: DaySchedule;
  sunday: DaySchedule;
}

export type ExceptionType = 'closed' | 'special_hours';

export interface ExceptionDate {
  id: string;
  date: string; // YYYY-MM-DD
  name: string;
  type: ExceptionType;
  time_ranges?: TimeRange[];
}

export type ScheduleStatus = 'active' | 'inactive';

export interface BusinessHoursSchedule {
  id: string;
  organization_id: string;
  name: string;
  status: ScheduleStatus;
  schedule: WeeklySchedule;
  exceptions: ExceptionDate[];
  open_hours_action: string; // Extension ID to forward to during open hours
  closed_hours_action: string; // Extension ID to forward to during closed hours
  current_status?: 'open' | 'closed' | 'exception';
  created_at: string;
  updated_at: string;
  created_by: string;
  updated_by?: string;
}

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

// ============================================================================
// Holiday Data
// ============================================================================

export interface Holiday {
  date: string; // YYYY-MM-DD
  name: string;
}

export interface Country {
  countryCode: string;
  name: string;
}

// ============================================================================
// Mock Data
// ============================================================================

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

// Helper to create standard weekday schedule
function createWeekdaySchedule(startTime: string, endTime: string): WeeklySchedule {
  const weekdaySchedule: DaySchedule = {
    enabled: true,
    time_ranges: [{ id: getNextTimeRangeId(), start_time: startTime, end_time: endTime }]
  };
  const closedSchedule: DaySchedule = {
    enabled: false,
    time_ranges: []
  };

  return {
    monday: weekdaySchedule,
    tuesday: weekdaySchedule,
    wednesday: weekdaySchedule,
    thursday: weekdaySchedule,
    friday: weekdaySchedule,
    saturday: closedSchedule,
    sunday: closedSchedule
  };
}

// Helper to create 24/7 schedule
function create24x7Schedule(): WeeklySchedule {
  const allDaySchedule: DaySchedule = {
    enabled: true,
    time_ranges: [{ id: getNextTimeRangeId(), start_time: '00:00', end_time: '23:59' }]
  };

  return {
    monday: allDaySchedule,
    tuesday: allDaySchedule,
    wednesday: allDaySchedule,
    thursday: allDaySchedule,
    friday: allDaySchedule,
    saturday: allDaySchedule,
    sunday: allDaySchedule
  };
}

export const mockBusinessHoursSchedules: BusinessHoursSchedule[] = [
  {
    id: 'schedule-1',
    organization_id: 'org-1',
    name: 'Main Office Hours',
    status: 'active',
    schedule: createWeekdaySchedule('09:00', '17:00'),
    exceptions: [
      {
        id: 'exception-1',
        date: '2025-12-25',
        name: 'Christmas Day',
        type: 'closed'
      },
      {
        id: 'exception-2',
        date: '2025-07-04',
        name: 'Independence Day',
        type: 'closed'
      }
    ],
    open_hours_action: 'ext-101',
    closed_hours_action: 'ext-voicemail',
    current_status: 'open',
    created_at: '2025-12-01T10:00:00Z',
    updated_at: '2025-12-01T10:00:00Z',
    created_by: 'user-1'
  },
  {
    id: 'schedule-2',
    organization_id: 'org-1',
    name: 'Support 24/7',
    status: 'active',
    schedule: create24x7Schedule(),
    exceptions: [],
    open_hours_action: 'ext-200',
    closed_hours_action: 'ext-voicemail',
    current_status: 'open',
    created_at: '2025-12-05T14:30:00Z',
    updated_at: '2025-12-05T14:30:00Z',
    created_by: 'user-1'
  },
  {
    id: 'schedule-3',
    organization_id: 'org-1',
    name: 'Summer Hours',
    status: 'inactive',
    schedule: {
      monday: {
        enabled: true,
        time_ranges: [{ id: getNextTimeRangeId(), start_time: '09:00', end_time: '18:00' }]
      },
      tuesday: {
        enabled: true,
        time_ranges: [{ id: getNextTimeRangeId(), start_time: '09:00', end_time: '18:00' }]
      },
      wednesday: {
        enabled: true,
        time_ranges: [{ id: getNextTimeRangeId(), start_time: '09:00', end_time: '18:00' }]
      },
      thursday: {
        enabled: true,
        time_ranges: [{ id: getNextTimeRangeId(), start_time: '09:00', end_time: '18:00' }]
      },
      friday: {
        enabled: true,
        time_ranges: [{ id: getNextTimeRangeId(), start_time: '09:00', end_time: '15:00' }]
      },
      saturday: {
        enabled: false,
        time_ranges: []
      },
      sunday: {
        enabled: false,
        time_ranges: []
      }
    },
    exceptions: [],
    open_hours_action: 'ext-150',
    closed_hours_action: 'ext-voicemail',
    current_status: 'closed',
    created_at: '2025-06-01T08:00:00Z',
    updated_at: '2025-09-15T16:00:00Z',
    created_by: 'user-2',
    updated_by: 'user-1'
  }
];

// Mock associated DIDs
export const mockDidBusinessHours: DidBusinessHours[] = [
  {
    did_number_id: 'did-1',
    phone_number: '+15550100',
    name: 'Main Line',
    business_hours_schedule_id: 'schedule-1',
    business_hours_action: 'ring_group',
    business_hours_target: 'ring-group-1',
    after_hours_action: 'voicemail'
  },
  {
    did_number_id: 'did-2',
    phone_number: '+15550101',
    name: 'Support',
    business_hours_schedule_id: 'schedule-2',
    business_hours_action: 'ring_group',
    business_hours_target: 'ring-group-2',
    after_hours_action: 'extension',
    after_hours_target: 'ext-100'
  },
  {
    did_number_id: 'did-3',
    phone_number: '+15550102',
    name: 'Sales',
    business_hours_schedule_id: 'schedule-1',
    business_hours_action: 'extension',
    business_hours_target: 'ext-101',
    after_hours_action: 'voicemail'
  }
];

// Mock extensions for select boxes
export const mockExtensions = [
  { id: 'ext-101', name: 'Reception - Ext 101', extension: '101' },
  { id: 'ext-102', name: 'Sales Team - Ext 102', extension: '102' },
  { id: 'ext-103', name: 'Support - Ext 103', extension: '103' },
  { id: 'ext-150', name: 'Manager - Ext 150', extension: '150' },
  { id: 'ext-200', name: 'Technical Support - Ext 200', extension: '200' },
  { id: 'ext-voicemail', name: 'Voicemail', extension: 'VM' },
];

// ============================================================================
// Helper Functions
// ============================================================================

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

  // Check if all days have same schedule
  const enabledDays = days.filter(day => schedule[day].enabled && schedule[day].time_ranges.length > 0);

  if (enabledDays.length === 0) {
    return 'Closed all days';
  }

  // Check for 24/7
  const is24x7 = enabledDays.length === 7 && enabledDays.every(day => {
    const ranges = schedule[day].time_ranges;
    return ranges.length === 1 && ranges[0].start_time === '00:00' && ranges[0].end_time === '23:59';
  });

  if (is24x7) {
    return 'Open 24 hours, all days';
  }

  // Check for weekday pattern (Mon-Fri)
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

  // For complex schedules, show first enabled day
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

    // Handle last day
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
export function isEndTimeAfterStart(startTime: string, endTime: string): boolean {
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
