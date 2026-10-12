/**
 * IVR menu schema. Limits mirror App\Http\Requests\StoreIvrMenuRequest / UpdateIvrMenuRequest;
 * keep the two in sync.
 */

import { z } from 'zod';

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

/** Returns the first validation message, or null when the payload is valid. */
export function firstIvrMenuError(payload: unknown): string | null {
  const r = ivrMenuSchema.safeParse(payload);
  return r.success ? null : r.error.issues[0].message;
}
