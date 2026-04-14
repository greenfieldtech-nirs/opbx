/**
 * Campaign Schema
 *
 * Zod validation schemas for Auto Dialer Campaign forms.
 * Includes validation for Caller ID pooling feature.
 */

import { z } from 'zod';

// Caller ID Strategy enum
export const callerIdStrategySchema = z.enum([
  'round_robin',
  'random',
  'least_recently_used',
]);

export type CallerIdStrategy = z.infer<typeof callerIdStrategySchema>;

// Caller ID Pool Item schema
export const callerIdPoolItemSchema = z.object({
  did_id: z.number().int().positive('DID ID must be a positive integer'),
  phone_number: z.string().min(1, 'Phone number is required'),
  friendly_name: z.string().optional(),
  weight: z
    .number()
    .int()
    .min(1, 'Weight must be at least 1')
    .max(100, 'Weight cannot exceed 100')
    .default(1),
});

export type CallerIdPoolItem = z.infer<typeof callerIdPoolItemSchema>;

// Caller ID Pool array schema with validation
export const callerIdPoolSchema = z
  .array(callerIdPoolItemSchema)
  .min(1, 'At least one Caller ID is required')
  .max(100, 'Maximum 100 Caller IDs allowed');

// Weekly schedule day schema
const dayScheduleSchema = z.object({
  enabled: z.boolean(),
  time_ranges: z.array(
    z.object({
      id: z.string(),
      start_time: z.string(),
      end_time: z.string(),
    })
  ),
});

// Weekly schedule schema
const weeklyScheduleSchema = z.object({
  monday: dayScheduleSchema,
  tuesday: dayScheduleSchema,
  wednesday: dayScheduleSchema,
  thursday: dayScheduleSchema,
  friday: dayScheduleSchema,
  saturday: dayScheduleSchema,
  sunday: dayScheduleSchema,
});

// Main campaign schema
export const campaignSchema = z.object({
  name: z.string().min(1, 'Campaign name is required').max(255),
  description: z.string().optional(),

  // Routing configuration
  routing_destination_type: z.enum([
    'ai_assistant',
    'ai_load_balancer',
    'hangup',
  ]),
  routing_destination_id: z.string().optional().nullable(),

  // Dialing configuration
  dial_timeout: z
    .number()
    .int()
    .min(10, 'Dial timeout must be at least 10 seconds')
    .max(120, 'Dial timeout cannot exceed 120 seconds'),
  destination_connect: z.enum(['connected', 'immediately']),

  // Caller ID configuration (legacy single caller ID)
  caller_id: z.string().optional(),

  // Caller ID Pool configuration (new)
  caller_id_strategy: callerIdStrategySchema.default('round_robin'),
  caller_id_pool: callerIdPoolSchema,

  // Campaign limits
  max_dial_attempts: z
    .number()
    .int()
    .min(1, 'Must allow at least 1 dial attempt')
    .max(10, 'Cannot exceed 10 dial attempts'),
  concurrent_active_calls: z
    .number()
    .int()
    .min(1, 'Must allow at least 1 concurrent call')
    .max(100, 'Cannot exceed 100 concurrent calls'),
  calls_per_second: z
    .number()
    .int()
    .min(1, 'Must be at least 1 call per second')
    .max(30, 'Cannot exceed 30 calls per second'),

  // Schedule configuration
  schedule: weeklyScheduleSchema,
  start_date: z.string().min(1, 'Start date is required'),
  end_date: z.string().min(1, 'End date is required'),
  timezone: z.string().min(1, 'Timezone is required'),

  // Optional advanced settings
  time_limit: z.number().int().optional(),
  record_calls: z.boolean().optional(),
  auto_start: z.boolean().optional(),

  // AMD (Answering Machine Detection) settings
  amd_enabled: z.boolean().optional(),
  amd_mode: z.enum(['Enabled', 'DetectMessageEnd']).optional(),
  amd_timeout: z.number().int().optional(),
  amd_speech_threshold: z.number().int().optional(),
  amd_speech_end_threshold: z.number().int().optional(),
  amd_silence_timeout: z.number().int().optional(),
});

export type CampaignFormData = z.infer<typeof campaignSchema>;

// Create campaign request schema (for API)
export const createCampaignRequestSchema = campaignSchema.omit({
  // Fields that are computed or set by the backend
});

export type CreateCampaignRequest = z.infer<typeof createCampaignRequestSchema>;

// Update campaign request schema (all fields optional)
export const updateCampaignRequestSchema = campaignSchema.partial();

export type UpdateCampaignRequest = z.infer<typeof updateCampaignRequestSchema>;

// Helper function to validate caller ID pool
export function validateCallerIdPool(pool: unknown): {
  valid: boolean;
  errors: string[];
} {
  const result = callerIdPoolSchema.safeParse(pool);

  if (result.success) {
    return { valid: true, errors: [] };
  }

  return {
    valid: false,
    errors: result.error.errors.map((e) => e.message),
  };
}

// Helper function to get default campaign form values
export function getDefaultCampaignValues(): Partial<CampaignFormData> {
  return {
    caller_id_strategy: 'round_robin',
    caller_id_pool: [],
    dial_timeout: 30,
    max_dial_attempts: 3,
    concurrent_active_calls: 10,
    calls_per_second: 1,
    destination_connect: 'connected',
    routing_destination_type: 'hangup',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
    schedule: {
      monday: { enabled: true, time_ranges: [{ id: '1', start_time: '09:00', end_time: '17:00' }] },
      tuesday: { enabled: true, time_ranges: [{ id: '1', start_time: '09:00', end_time: '17:00' }] },
      wednesday: { enabled: true, time_ranges: [{ id: '1', start_time: '09:00', end_time: '17:00' }] },
      thursday: { enabled: true, time_ranges: [{ id: '1', start_time: '09:00', end_time: '17:00' }] },
      friday: { enabled: true, time_ranges: [{ id: '1', start_time: '09:00', end_time: '17:00' }] },
      saturday: { enabled: false, time_ranges: [] },
      sunday: { enabled: false, time_ranges: [] },
    },
  };
}
