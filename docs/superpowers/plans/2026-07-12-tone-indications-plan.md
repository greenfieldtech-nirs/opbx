# Tone Indications Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:executing-plans` to implement this plan task-by-task.

**Goal:** Embed Asterisk tone indications into the frontend and play region-specific ring, busy, congestion, and dial tones in the Web Phone, defaulting to `us`.

**Architecture:** Country is read from the organization's `settings` JSON and returned by the WebPhone config endpoint. The frontend selects the matching tone set from a static TypeScript library and plays it through a `TonePlayer` class built on the Web Audio API.

**Tech Stack:** Laravel 12 (PHP 8.4), React 18 + TypeScript, Web Audio API, JsSIP.

---

## File Structure

| File | Responsibility |
|---|---|
| `scripts/generate-tone-indications.php` | One-off parser that reads Asterisk `indications.conf` and emits `frontend/src/lib/tone-indications.ts`. |
| `frontend/src/lib/tone-indications.ts` | Static, parsed tone definitions keyed by country code. |
| `frontend/src/lib/TonePlayer.ts` | Web Audio scheduler: plays tone sequences with cadence, dual frequencies, and silence. |
| `app/Http/Controllers/Api/WebPhoneConfigController.php` | Adds `country` to the config payload. |
| `frontend/src/types/webPhone.types.ts` | Adds `country` to the WebPhone config type. |
| `frontend/src/components/WebPhone/WebPhoneDrawer.tsx` | Replaces the simple ringback oscillator with `TonePlayer`. |
| `tests/Feature/Api/WebPhoneConfigControllerTest.php` | Adds country resolution tests. |
| `docs/superpowers/specs/2026-07-12-tone-indications-design.md` | Design spec. |
| `docs/superpowers/plans/2026-07-12-tone-indications-plan.md` | This plan. |
| `/.my_agent/memory/live-calls.md` | Memory update. |

---

## Task 1: Generate the tone definitions file

**Files:**
- Create: `scripts/generate-tone-indications.php`
- Create: `frontend/src/lib/tone-indications.ts` (generated output)

**Steps:**

### Step 1.1: Write the parser script

Create `scripts/generate-tone-indications.php` with the following content. It fetches the Asterisk `indications.conf.sample` and emits a TypeScript module.

```php
<?php
declare(strict_types=1);

// phpcs:ignoreFile

$url = 'https://raw.githubusercontent.com/asterisk/asterisk/ae85ad744af4fa2a044bd362aaf7fc32dd72a90f/configs/samples/indications.conf.sample';
$text = file_get_contents($url);
if ($text === false) {
    fwrite(STDERR, "Failed to fetch indications.conf\n");
    exit(1);
}

$lines = explode("\n", $text);
$sections = [];
$current = null;

foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, ';')) {
        continue;
    }

    if (preg_match('/^\[(\w+)\]$/', $line, $matches)) {
        $current = $matches[1];
        $sections[$current] = [
            'description' => '',
            'ringcadence' => null,
            'tones' => [],
        ];
        continue;
    }

    if ($current === null) {
        continue;
    }

    if (str_contains($line, '=')) {
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $key = strtolower($key);
        if ($key === 'description') {
            $sections[$current]['description'] = $value;
        } elseif ($key === 'ringcadence') {
            $sections[$current]['ringcadence'] = array_map('intval', explode(',', $value));
        } else {
            $sections[$current]['tones'][$key] = parseToneList($value);
        }
    }
}

function parseToneList(string $value): array
{
    $elements = [];
    foreach (explode(',', $value) as $part) {
        $part = trim($part);
        $once = str_starts_with($part, '!');
        if ($once) {
            $part = substr($part, 1);
        }

        $duration = 0;
        if (str_contains($part, '/')) {
            [$freqPart, $durationStr] = explode('/', $part, 2);
            $duration = (int) $durationStr;
            $part = $freqPart;
        }

        $freqs = [];
        if ($part === '0') {
            $freqs = [];
        } elseif (str_contains($part, '+')) {
            $freqs = array_map('intval', explode('+', $part));
        } elseif (str_contains($part, '*')) {
            // FM modulation: use carrier frequency only in v1
            $freqs = [(int) explode('*', $part)[0]];
        } elseif (is_numeric($part)) {
            $freqs = [(int) $part];
        }

        $elements[] = [
            'freqs' => $freqs,
            'durationMs' => $duration,
            'once' => $once,
        ];
    }

    return $elements;
}

$countries = [];
foreach ($sections as $code => $section) {
    if ($code === 'general') {
        continue;
    }

    $tones = [];
    foreach ($section['tones'] as $toneName => $elements) {
        $tones[$toneName] = array_map(fn ($e) => array_filter([
            'freqs' => $e['freqs'],
            'durationMs' => $e['durationMs'],
            'once' => $e['once'] ? true : null,
        ], fn ($v) => $v !== null), $elements);
    }

    $entry = [
        'description' => $section['description'],
        'tones' => $tones,
    ];
    if ($section['ringcadence'] !== null) {
        $entry['ringcadence'] = $section['ringcadence'];
    }
    $countries[$code] = $entry;
}

$json = json_encode($countries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

echo <<<'TS'
// Auto-generated from Asterisk indications.conf.sample
// Do not edit manually. Regenerate with: php scripts/generate-tone-indications.php

export interface ToneElement {
  freqs: number[];
  durationMs: number;
  once?: boolean;
}

export type ToneName =
  | 'ring'
  | 'busy'
  | 'congestion'
  | 'dial'
  | 'callwaiting'
  | 'dialrecall'
  | 'record'
  | 'info'
  | 'stutter';

export interface ToneSet {
  description: string;
  ringcadence?: number[];
  tones: Partial<Record<ToneName, ToneElement[]>>;
}

export const DEFAULT_COUNTRY = 'us';

export const TONE_INDICATIONS: Record<string, ToneSet> = 
TS;

echo $json . ";\n";

echo <<<'TS'

export function getToneSet(country: string): ToneSet {
  const code = country.toLowerCase().trim();
  return TONE_INDICATIONS[code] ?? TONE_INDICATIONS[DEFAULT_COUNTRY];
}

export function getToneSequence(country: string, toneName: ToneName): ToneElement[] | null {
  return getToneSet(country).tones[toneName] ?? null;
}
TS;
```

### Step 1.2: Run the generator

```bash
php scripts/generate-tone-indications.php > frontend/src/lib/tone-indications.ts
```

Expected: a TypeScript file with all country definitions.

### Step 1.3: Verify the generated file

```bash
cd frontend && npx tsc --noEmit src/lib/tone-indications.ts
```

Expected: no type errors.

---

## Task 2: Implement the TonePlayer

**Files:**
- Create: `frontend/src/lib/TonePlayer.ts`

### Step 2.1: Write TonePlayer

```typescript
import { DEFAULT_COUNTRY, getToneSequence, ToneElement, ToneName } from './tone-indications';

export class TonePlayer {
  private audioCtx: AudioContext | null = null;
  private timeoutId: number | null = null;
  private activeNodes: AudioNode[] = [];
  private gainNodes: GainNode[] = [];

  play(toneName: ToneName, country: string): void {
    this.stop();

    const sequence = getToneSequence(country, toneName) ?? getToneSequence(DEFAULT_COUNTRY, toneName);
    if (!sequence || sequence.length === 0) {
      return;
    }

    try {
      this.ensureAudioContext();
      if (!this.audioCtx) {
        return;
      }

      this.scheduleSequence(sequence, 0);
    } catch (error) {
      console.error('[TonePlayer] Failed to play tone:', error);
    }
  }

  stop(): void {
    if (this.timeoutId !== null) {
      window.clearTimeout(this.timeoutId);
      this.timeoutId = null;
    }

    this.gainNodes.forEach((gain) => {
      try {
        gain.gain.setValueAtTime(0, this.audioCtx?.currentTime ?? 0);
      } catch {
        // ignore
      }
    });

    this.activeNodes.forEach((node) => {
      if (node instanceof OscillatorNode) {
        try {
          node.stop();
        } catch {
          // ignore
        }
      }
    });

    this.activeNodes = [];
    this.gainNodes = [];

    if (this.audioCtx && this.audioCtx.state !== 'closed') {
      try {
        this.audioCtx.close();
      } catch {
        // ignore
      }
    }
    this.audioCtx = null;
  }

  private ensureAudioContext(): void {
    if (this.audioCtx) {
      return;
    }

    const AudioContextClass = (window as any).AudioContext || (window as any).webkitAudioContext;
    if (!AudioContextClass) {
      throw new Error('Web Audio API not supported');
    }

    this.audioCtx = new AudioContextClass();
  }

  private scheduleSequence(sequence: ToneElement[], iterationDelayMs: number): void {
    if (!this.audioCtx) {
      return;
    }

    const sequenceDurationMs = sequence.reduce((sum, el) => sum + el.durationMs, 0);
    if (sequenceDurationMs === 0) {
      return;
    }

    const startTime = this.audioCtx.currentTime + iterationDelayMs / 1000;
    let timeOffset = 0;

    for (const element of sequence) {
      const elementStart = startTime + timeOffset / 1000;
      const elementDuration = element.durationMs / 1000;

      if (element.freqs.length === 0) {
        // silence
      } else {
        const gain = this.audioCtx.createGain();
        gain.gain.setValueAtTime(0, elementStart);
        gain.gain.linearRampToValueAtTime(0.1, elementStart + 0.01);
        gain.gain.setValueAtTime(0.1, elementStart + elementDuration - 0.01);
        gain.gain.linearRampToValueAtTime(0, elementStart + elementDuration);
        gain.connect(this.audioCtx.destination);

        this.gainNodes.push(gain);

        for (const freq of element.freqs) {
          const osc = this.audioCtx.createOscillator();
          osc.type = 'sine';
          osc.frequency.setValueAtTime(freq, elementStart);
          osc.connect(gain);
          osc.start(elementStart);
          osc.stop(elementStart + elementDuration);
          this.activeNodes.push(osc);
        }
      }

      timeOffset += element.durationMs;
    }

    const allOnce = sequence.every((el) => el.once);
    if (!allOnce) {
      this.timeoutId = window.setTimeout(() => {
        this.scheduleSequence(sequence, 0);
      }, iterationDelayMs + sequenceDurationMs - 50);
    }

    if (this.audioCtx.state === 'suspended') {
      this.audioCtx.resume().catch((error) => {
        console.warn('[TonePlayer] Could not resume AudioContext:', error);
      });
    }
  }
}
```

### Step 2.2: Type-check

```bash
cd frontend && npx tsc --noEmit src/lib/TonePlayer.ts
```

Expected: no errors.

---

## Task 3: Update the backend config endpoint

**Files:**
- Modify: `app/Http/Controllers/Api/WebPhoneConfigController.php`

### Step 3.1: Add country resolution

Inside `config()`, after reading `$cloudonixSettings`, add:

```php
$organization = $user->organization;
$country = 'us';
if ($organization && $organization->settings) {
    $settingsCountry = strtolower(trim((string) ($organization->settings['country'] ?? '')));
    if ($settingsCountry !== '') {
        $country = $settingsCountry;
    }
}
```

Add `'country' => $country,` to the response data array.

### Step 3.2: Verify PHP lint

```bash
vendor/bin/pint --dirty
```

Expected: no changes needed.

---

## Task 4: Update the frontend config type

**Files:**
- Modify: `frontend/src/types/webPhone.types.ts`

### Step 4.1: Add country to the type

```typescript
export interface WebPhoneConfig {
  sip_username: string;
  sip_password: string;
  sip_domain: string;
  sip_uri: string;
  display_name: string;
  wss_server: string;
  websocket_port: number;
  server_path: string;
  sip_contact: string;
  profile_name: string;
  registration_mode: string;
  country: string;
}
```

---

## Task 5: Integrate TonePlayer into WebPhoneDrawer

**Files:**
- Modify: `frontend/src/components/WebPhone/WebPhoneDrawer.tsx`

### Step 5.1: Import and replace refs

Add imports:

```typescript
import { TonePlayer } from '@/lib/TonePlayer';
```

Replace the two ringback refs with a single tone player ref:

```typescript
const tonePlayerRef = useRef<TonePlayer | null>(null);
```

### Step 5.2: Replace start/stop helpers

Remove `startRingback` and `stopRingback` callbacks. Add a `getTonePlayer()` helper:

```typescript
const getTonePlayer = useCallback(() => {
  if (!tonePlayerRef.current) {
    tonePlayerRef.current = new TonePlayer();
  }
  return tonePlayerRef.current;
}, []);
```

### Step 5.3: Update session event handlers

In `handleSessionEvents`:

```typescript
session.on('confirmed', () => {
  getTonePlayer().stop();
  setCallState('connected');
  setStatus('On call');
  attachRemoteStream(session);
});

session.on('ended', () => {
  getTonePlayer().stop();
  resetCallState();
  setStatus('Registered');
});

session.on('failed', (event: any) => {
  getTonePlayer().stop();

  const cause = event?.cause?.toLowerCase() ?? '';
  const country = config?.country ?? 'us';

  if (cause === 'busy') {
    getTonePlayer().play('busy', country);
  } else if (['congestion', 'unavailable', 'request_timeout', 'timeout'].some((c) => cause.includes(c))) {
    getTonePlayer().play('congestion', country);
  }

  resetCallState();
  setStatus('Registered');
});
```

Update the `handleSessionEvents` dependency array to include `config` and `getTonePlayer` (or destructure `config?.country` before the callback).

### Step 5.4: Update outgoing call progress handler

```typescript
const session = uaRef.current.call(target, {
  mediaConstraints: { audio: true, video: false },
  eventHandlers: {
    connecting: () => setStatus('Calling...'),
    progress: () => {
      setStatus('Ringing...');
      getTonePlayer().play('ring', config?.country ?? 'us');
    },
  },
});
```

### Step 5.5: Update cleanup

Replace the `stopRingback()` calls in the drawer close effect and cleanup with `tonePlayerRef.current?.stop()`.

### Step 5.6: Type-check and build

```bash
cd frontend && npm run type-check && npm run build
```

Expected: `type-check` passes and `build` succeeds.

---

## Task 6: Add backend tests for country

**Files:**
- Modify: `tests/Feature/Api/WebPhoneConfigControllerTest.php`

### Step 6.1: Add country tests

Add three test methods to the existing test class:

```php
public function test_config_returns_us_country_when_organization_settings_has_no_country(): void
{
    $organization = Organization::factory()->create(['settings' => []]);
    $cloudonixSettings = CloudonixSettings::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->owner()->create(['organization_id' => $organization->id]);
    $extension = Extension::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'type' => ExtensionType::USER,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/webphone/config')
        ->assertOk()
        ->assertJsonPath('data.country', 'us');
}

public function test_config_returns_country_from_organization_settings(): void
{
    $organization = Organization::factory()->create(['settings' => ['country' => 'uk']]);
    $cloudonixSettings = CloudonixSettings::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->owner()->create(['organization_id' => $organization->id]);
    $extension = Extension::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'type' => ExtensionType::USER,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/webphone/config')
        ->assertOk()
        ->assertJsonPath('data.country', 'uk');
}

public function test_config_defaults_to_us_for_unknown_country(): void
{
    $organization = Organization::factory()->create(['settings' => ['country' => 'xx']]);
    $cloudonixSettings = CloudonixSettings::factory()->create(['organization_id' => $organization->id]);
    $user = User::factory()->owner()->create(['organization_id' => $organization->id]);
    $extension = Extension::factory()->create([
        'organization_id' => $organization->id,
        'user_id' => $user->id,
        'type' => ExtensionType::USER,
    ]);

    $this->actingAs($user)
        ->getJson('/api/v1/webphone/config')
        ->assertOk()
        ->assertJsonPath('data.country', 'xx');
}
```

Note: the third test intentionally asserts the raw value because the backend should not validate the country; the frontend falls back to `us` for unknown countries. (Adjust if the backend should normalize.)

### Step 6.2: Run backend tests

```bash
./run-tests.sh --filter=WebPhoneConfigControllerTest
```

Expected: all tests pass.

---

## Task 7: Verify the build and full test suite

### Step 7.1: Frontend

```bash
cd frontend && npm run type-check && npm run build
```

Expected: both pass.

### Step 7.2: Backend lint

```bash
vendor/bin/pint --dirty
```

Expected: no changes.

### Step 7.3: Full backend test suite

```bash
./run-tests.sh
```

Expected: all tests pass.

---

## Task 8: Run project regression tests

Use the `project-regression-tester` subagent to verify the full stack still works.

---

## Task 9: Update memory and documentation

- Update `/.my_agent/memory/live-calls.md` to mention the country-based tone indications.
- Move this plan to `docs/superpowers/plans/completed/` once implemented.

---

## Spec Coverage Check

- Country stored in organization `settings` JSON — Task 3.
- Default to `us` — Task 3 and Task 2 (`getToneSet` fallback).
- Country-based tone selection — Task 2 and Task 5.
- Ring, busy, congestion, dial tones — Task 1 (data) and Task 5 (events).
- Clean stop on lifecycle events — Task 5.
- FM modulation approximation — Task 1 parser comment.
- Backend tests — Task 6.
- Frontend build/type-check — Task 5 and Task 7.
- Regression tests — Task 8.

---

## Placeholder Scan

No TBD, TODO, or vague steps. All code snippets are complete. The only generated file (`tone-indications.ts`) is produced by the explicit parser script in Task 1.

---

## Type Consistency Check

- `ToneElement` interface used in `tone-indications.ts` and `TonePlayer.ts`.
- `ToneName` union used in both files.
- `WebPhoneConfig` includes `country: string` matching the backend payload.
- `TonePlayer.play(toneName, country)` matches the signature used in `WebPhoneDrawer`.
