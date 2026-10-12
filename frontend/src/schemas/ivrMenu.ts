/**
 * IVR menu schema. Limits mirror App\Http\Requests\StoreIvrMenuRequest / UpdateIvrMenuRequest;
 * keep the two in sync.
 */

import { z } from 'zod';
import type {
  CreateIvrMenuRequest,
  IvrDestinationType,
  IvrFailoverDestinationType,
  IvrMenuStatus,
} from '@/types/api.types';

export const IVR_LIMITS = {
  name: 255,
  description: 1000,
  audioFilePath: 500,
  ttsText: 1000,
  ttsVoice: 50,
  timeout: { min: 1, max: 30 },
  maxTurns: { min: 1, max: 9 },
  options: { min: 1, max: 20 },
  inputDigits: 10,
  optionDescription: 255,
} as const;

const failoverTypes = [
  'extension', 'ring_group', 'conference_room', 'ivr_menu', 'ai_assistant',
  'ai_load_balancer', 'business_hours', 'call_queue', 'hangup', 'unavailable',
] as const;

const optionTypes = failoverTypes.filter((t) => t !== 'hangup');

const optionSchema = z
  .object({
    input_digits: z.string().min(1, 'Each menu option needs digits').max(IVR_LIMITS.inputDigits, `Digits may not exceed ${IVR_LIMITS.inputDigits} characters`),
    description: z.string().max(IVR_LIMITS.optionDescription, `Option description may not exceed ${IVR_LIMITS.optionDescription} characters`).nullish(),
    destination_type: z.enum(optionTypes as [string, ...string[]], { errorMap: () => ({ message: 'Invalid option destination type' }) }),
    destination_id: z.union([z.string(), z.number()]).nullish(),
    priority: z.number().int().min(1).max(IVR_LIMITS.options.max),
  })
  .superRefine((o, ctx) => {
    // 'unavailable' options have no target (backend stores 0)
    if (o.destination_type !== 'unavailable' && (o.destination_id === undefined || o.destination_id === null || o.destination_id === '')) {
      ctx.addIssue({ code: 'custom', path: ['destination_id'], message: `Option ${o.input_digits || ''} needs a destination` });
    }
  });

export const ivrMenuSchema = z
  .object({
    name: z.string().trim().min(1, 'Name is required').max(IVR_LIMITS.name, `Name may not exceed ${IVR_LIMITS.name} characters`),
    description: z.string().max(IVR_LIMITS.description, `Description may not exceed ${IVR_LIMITS.description} characters`).nullish(),
    audio_file_path: z.string().max(IVR_LIMITS.audioFilePath, `Audio file path must be ${IVR_LIMITS.audioFilePath} characters or less`).nullish(),
    recording_id: z.number().int().nullish(),
    tts_text: z.string().max(IVR_LIMITS.ttsText, `Text to speak may not exceed ${IVR_LIMITS.ttsText} characters`).nullish(),
    tts_voice: z.string().max(IVR_LIMITS.ttsVoice, `Voice may not exceed ${IVR_LIMITS.ttsVoice} characters`).nullish(),
    max_timeout: z.number().int().min(IVR_LIMITS.timeout.min).max(IVR_LIMITS.timeout.max, `Max timeout must be ${IVR_LIMITS.timeout.min}-${IVR_LIMITS.timeout.max} seconds`),
    inter_digit_timeout: z.number().int().min(IVR_LIMITS.timeout.min).max(IVR_LIMITS.timeout.max, `Inter-digit timeout must be ${IVR_LIMITS.timeout.min}-${IVR_LIMITS.timeout.max} seconds`),
    max_turns: z.number().int().min(IVR_LIMITS.maxTurns.min).max(IVR_LIMITS.maxTurns.max, `Maximum turns must be ${IVR_LIMITS.maxTurns.min}-${IVR_LIMITS.maxTurns.max}`),
    failover_destination_type: z.enum(failoverTypes),
    failover_destination_id: z.union([z.string(), z.number()]).nullish(),
    status: z.enum(['active', 'inactive']),
    options: z
      .array(optionSchema)
      .min(IVR_LIMITS.options.min, 'At least one menu option is required')
      .max(IVR_LIMITS.options.max, `Maximum ${IVR_LIMITS.options.max} menu options allowed`),
  })
  .superRefine((m, ctx) => {
    if (!m.recording_id && !m.audio_file_path && !m.tts_text) {
      ctx.addIssue({ code: 'custom', path: ['tts_text'], message: 'Choose an audio source: a recording, an audio URL, or text to speak' });
    }
  });

/** Values held by the IVR menu form (UI shape; `useTTS` is UI-only). */
export interface IvrMenuFormValues {
  name: string;
  useTTS: boolean;
  audio_file_path: string;
  recording_id?: number;
  tts_text: string;
  tts_voice?: string;
  max_timeout: number;
  inter_digit_timeout: number;
  max_turns: number;
  failover_destination_type: IvrFailoverDestinationType;
  failover_destination_id?: string;
  status: IvrMenuStatus;
  options: Array<{
    input_digits: string;
    description?: string;
    destination_type: IvrDestinationType;
    destination_id: string;
  }>;
}

/** Maps form values to the API payload (only the active audio source is sent). */
export function toIvrPayload(v: IvrMenuFormValues): CreateIvrMenuRequest {
  return {
    name: v.name,
    audio_file_path: v.useTTS ? undefined : (v.recording_id ? undefined : v.audio_file_path),
    recording_id: v.useTTS ? undefined : v.recording_id,
    tts_text: v.useTTS ? v.tts_text : undefined,
    tts_voice: v.useTTS ? v.tts_voice : undefined,
    max_timeout: v.max_timeout,
    inter_digit_timeout: v.inter_digit_timeout,
    max_turns: v.max_turns,
    failover_destination_type: v.failover_destination_type,
    failover_destination_id: v.failover_destination_id,
    status: v.status,
    options: (v.options ?? []).map((o, i) => ({
      input_digits: o.input_digits,
      description: o.description,
      destination_type: o.destination_type,
      destination_id: o.destination_id,
      priority: i + 1,
    })),
  };
}

/** Form schema: validates the API payload, so error paths match the form field names. */
export const ivrMenuFormSchema = z.preprocess((v) => toIvrPayload(v as IvrMenuFormValues), ivrMenuSchema);
