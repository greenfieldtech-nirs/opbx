# Tone Indications Integration Design Specification

> **Project:** OpBX - Open Source Business PBX on Cloudonix CPaaS
> **Feature:** Country-based telephony tone indications for the Web Phone
> **Source:** Asterisk `indications.conf.sample`
> **Date:** 2026-07-12

---

## 1. Goal

Play the correct regional telephony tones (ringback, busy, congestion, dial) in the Web Phone based on the organization's country. When no country is configured, fall back to United States (`us`) tones.

---

## 2. User Story

> As an OpBX user making a call through the Web Phone, I want to hear the ringback, busy, and congestion tones that match my organization's country, so the experience feels like a local phone system.

---

## 3. Scope

### In Scope (v1)
- Embed the Asterisk `indications.conf.sample` tone definitions into the frontend as a TypeScript constants module.
- Expose the organization's country from the Web Phone config endpoint.
- Default country to `us` when not configured.
- Play the correct tones for the following call events:
  - **ring** — when an outgoing call is in progress (`progress` event).
  - **busy** — when a call fails with a busy cause.
  - **congestion** — when a call fails with a network congestion cause.
  - **dial** — when the phone is ready to dial (optional UI polish).
- Stop tones cleanly on call connect, hangup, failure, or drawer close.
- Support single-frequency, dual-frequency, and silence elements with repeating cadences.

### Out of Scope
- FM-modulated tones (`freq1*freq2`) will be approximated by the carrier frequency for now. Full FM synthesis can be added later if needed.
- Custom per-organization tone editing beyond selecting a country.
- Tone generation on the backend or pre-rendered audio files.

---

## 4. Architecture

### Data Flow
```text
Organization.settings.country
  └── WebPhoneConfigController
        └── GET /api/v1/webphone/config
              └── WebPhoneDrawer
                    └── tone-indications.ts
                          └── TonePlayer
                                └── Web Audio API
```

### Components
- **`tone-indications.ts`** — Static library of parsed tone definitions keyed by ISO country code.
- **`TonePlayer`** — Service class that reads the active region's tone definition and schedules Web Audio oscillator nodes.
- **`WebPhoneConfigController`** — Backend controller that adds `country` to the config payload.
- **`WebPhoneDrawer`** — Replaces the current simple ringback oscillator with calls to `TonePlayer`.

---

## 5. Tone Definition Format

The Asterisk `indications.conf` format is parsed once into a TypeScript structure:

```typescript
interface ToneElement {
  freqs: number[];      // Frequencies to play simultaneously (empty for silence)
  durationMs: number;    // Duration of this element
  once?: boolean;        // If true, this element is not repeated
}

type ToneName = 'ring' | 'busy' | 'congestion' | 'dial' | 'callwaiting' | 'dialrecall' | 'record' | 'info' | 'stutter';

interface ToneSet {
  description: string;
  ringcadence?: number[];
  tones: Partial<Record<ToneName, ToneElement[]>>;
}

const TONE_INDICATIONS: Record<string, ToneSet> = { ... };
```

### Parsing Rules
- `425` → `{ freqs: [425], durationMs: 0 }` (continuous until stopped).
- `425/500` → `{ freqs: [425], durationMs: 500 }`.
- `350+440` → `{ freqs: [350, 440], durationMs: 0 }`.
- `350+440/500` → `{ freqs: [350, 440], durationMs: 500 }`.
- `0/500` → `{ freqs: [], durationMs: 500 }` (silence).
- `!425/100` → `{ freqs: [425], durationMs: 100, once: true }`.
- `425*25` → approximated as `{ freqs: [425], durationMs: 0 }` (FM modulation ignored in v1).

A tone sequence repeats indefinitely unless **all** elements are marked `once: true`. Repeating sequences are looped by the `TonePlayer`.

---

## 6. Backend API Contract

### `GET /api/v1/webphone/config`

Adds a single field to the existing `data` payload:

```json
{
  "data": {
    "sip_username": "1000",
    "sip_password": "...",
    "sip_domain": "my-org.example.com",
    "sip_uri": "sip:1000@my-org.example.com",
    "display_name": "John Doe",
    "wss_server": "wss://webrtc.cloudonix.io",
    "websocket_port": 443,
    "server_path": "",
    "sip_contact": "1000",
    "profile_name": "John Doe",
    "registration_mode": "Direct",
    "country": "us"
  }
}
```

**Country resolution logic:**
1. Read `organization.settings['country']`.
2. Trim, lowercase, and validate against the known country codes in `TONE_INDICATIONS`.
3. If missing, empty, or unknown, return `us`.

---

## 7. Frontend Implementation

### `frontend/src/lib/tone-indications.ts`
- Contains the parsed Asterisk tone data for all countries in the sample file.
- Exports `TONE_INDICATIONS`, `getToneSet(country: string)`, and `DEFAULT_COUNTRY`.
- Includes a `ToneName` union and related TypeScript interfaces.

### `frontend/src/lib/TonePlayer.ts` (new)
- `TonePlayer` class:
  - `play(toneName: ToneName, country: string)` — starts the tone sequence.
  - `stop()` — stops the current tone and closes the audio context.
- Internally creates an `AudioContext` on the first play (within a user gesture).
- Schedules oscillators and gain nodes using `audioCtx.currentTime` for gap-free looping.
- Resumes a suspended `AudioContext` to satisfy browser autoplay policies.
- Handles dual-frequency tones by creating two oscillators connected to the same gain node.
- Handles silence by scheduling a gain ramp to zero for the duration.
- Loops repeating sequences by rescheduling when the sequence ends.

### `WebPhoneDrawer.tsx` updates
- Import `TonePlayer` and tone names.
- Replace `startRingback` / `stopRingback` with `TonePlayer`.
- On outgoing call `progress` event: `tonePlayer.play('ring', config.country)`.
- On `confirmed`: `tonePlayer.stop()`.
- On `failed` with busy cause: `tonePlayer.play('busy', config.country)`.
- On `failed` with congestion cause: `tonePlayer.play('congestion', config.country)`.
- On `ended` or drawer close/unmount: `tonePlayer.stop()`.
- Keep a single `TonePlayer` instance in a ref.

---

## 8. Tone Events Mapping

| Event | Tone Played | Stop Condition |
|---|---|---|
| Outgoing `progress` | `ring` | `confirmed`, `failed`, `ended`, drawer close |
| `failed` with `busy` cause | `busy` | `ended`, drawer close |
| `failed` with `congestion` cause | `congestion` | `ended`, drawer close |
| Drawer ready (optional) | `dial` | User starts dialing / call connects |

---

## 9. Error Handling

- Unknown country code: silently fall back to `us`.
- Missing tone definition for a country: fall back to `us` for that tone.
- `AudioContext` creation blocked: log warning; tone simply won't play.
- Tone playback failure: log error; do not break the call flow.

---

## 10. Testing Requirements

1. **Backend feature tests:**
   - Config returns `country: 'us'` when organization settings has no country.
   - Config returns the stored lowercase country when set in settings.
   - Config returns `us` when an unknown country code is stored.
   - Existing role/extension/Cloudonix settings tests still pass.

2. **Frontend lint / type / build:**
   - `npm run type-check` and `npm run build` pass.
   - `vendor/bin/pint --dirty` passes (backend change is small).

3. **Manual verification:**
   - Place an outgoing call with country set to `uk` and confirm the UK ring cadence.
   - Place an outgoing call with no country set and confirm the US ring cadence.
   - Hang up before answer and confirm the tone stops immediately.

---

## 11. Files to Create / Modify

### New Files
- `frontend/src/lib/tone-indications.ts` — parsed tone definitions.
- `frontend/src/lib/TonePlayer.ts` — Web Audio tone player.
- `docs/superpowers/specs/2026-07-12-tone-indications-design.md` — this document.
- `docs/superpowers/plans/2026-07-12-tone-indications-plan.md` — implementation plan.

### Modified Files
- `app/Http/Controllers/Api/WebPhoneConfigController.php` — add `country` to config payload.
- `frontend/src/components/WebPhone/WebPhoneDrawer.tsx` — use `TonePlayer` instead of simple oscillator.
- `frontend/src/types/webPhone.types.ts` — add `country` to config type.
- `tests/Feature/Api/WebPhoneConfigControllerTest.php` — add country tests.
- `/.my_agent/memory/live-calls.md` — update Web Phone section.

---

## 12. Open Questions / Risks

1. **FM-modulated tones:** Countries like India (`400*25`), Singapore (`425*24`), and South Africa (`400*33`) use modulated tones. We approximate them with the carrier frequency. This is acceptable for v1 but may not sound identical to a real PSTN line.
2. **Browser autoplay:** `AudioContext` may start suspended. The `TonePlayer` will resume it, but the very first tone after a cold page load might be muted until the user interacts with the page. The call button is a user gesture, so this should be acceptable.
3. **Bundle size:** The tone definitions file will add a few KB to the frontend bundle. This is acceptable for a self-contained feature.
4. **Organization settings UI:** There is currently no UI to set the organization country. It must be set via database or API. A settings UI can be added later.

---

## 13. References

- Asterisk `indications.conf.sample`: https://raw.githubusercontent.com/asterisk/asterisk/ae85ad744af4fa2a044bd362aaf7fc32dd72a90f/configs/samples/indications.conf.sample
- Web Audio API: https://developer.mozilla.org/en-US/docs/Web/API/Web_Audio_API
- Existing Web Phone design: `docs/superpowers/specs/2026-07-10-web-phone-integration-design.md`
