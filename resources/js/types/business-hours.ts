// Business Hours API Types
export interface BusinessHoursSchedule {
  id: string;
  organization_id: string;
  name: string;
  status: 'active' | 'inactive';
  schedule: {
    monday: DaySchedule;
    tuesday: DaySchedule;
    wednesday: DaySchedule;
    thursday: DaySchedule;
    friday: DaySchedule;
    saturday: DaySchedule;
    sunday: DaySchedule;
  };
  exceptions: BusinessHoursException[];
  open_hours_action: BusinessHoursAction;
  closed_hours_action: BusinessHoursAction;
  current_status: 'open' | 'closed' | 'exception';
  created_at: string;
  updated_at: string;
}

export interface BusinessHoursAction {
  type: 'extension' | 'ring_group' | 'ivr_menu';
  target_id: string;
}

export interface DaySchedule {
  enabled: boolean;
  time_ranges: TimeRange[];
}

export interface TimeRange {
  id?: string;
  start_time: string; // HH:mm format
  end_time: string; // HH:mm format
}

export interface BusinessHoursException {
  id?: string;
  date: string; // YYYY-MM-DD format
  name: string;
  type: 'closed' | 'special_hours';
  time_ranges?: TimeRange[];
}

// API Request/Response types
export interface BusinessHoursScheduleCollection {
  data: BusinessHoursSchedule[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
}

export interface CreateBusinessHoursScheduleRequest {
  name: string;
  status: 'active' | 'inactive';
  open_hours_action: BusinessHoursAction;
  closed_hours_action: BusinessHoursAction;
  schedule: {
    monday: DayScheduleRequest;
    tuesday: DayScheduleRequest;
    wednesday: DayScheduleRequest;
    thursday: DayScheduleRequest;
    friday: DayScheduleRequest;
    saturday: DayScheduleRequest;
    sunday: DayScheduleRequest;
  };
  exceptions?: CreateBusinessHoursExceptionRequest[];
}

export interface DayScheduleRequest {
  enabled: boolean;
  time_ranges: TimeRangeRequest[];
}

export interface TimeRangeRequest {
  start_time: string;
  end_time: string;
}

export interface CreateBusinessHoursExceptionRequest {
  date: string;
  name: string;
  type: 'closed' | 'special_hours';
  time_ranges?: TimeRangeRequest[];
}

export interface UpdateBusinessHoursScheduleRequest extends CreateBusinessHoursScheduleRequest {}

// API Error Response
export interface ApiError {
  message: string;
  errors?: Record<string, string[]>;
}

// Generic API Response
export interface ApiResponse<T> {
  data: T;
  message?: string;
}
