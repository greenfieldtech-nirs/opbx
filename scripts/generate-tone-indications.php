<?php

declare(strict_types=1);

// phpcs:ignoreFile
// One-off generator: converts Asterisk indications.conf into a TypeScript module.
// Usage: php scripts/generate-tone-indications.php > frontend/src/lib/tone-indications.ts

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
        $current = strtolower($matches[1]);
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
            // FM modulation: approximate with the carrier frequency in v1.
            $freqs = [(int) explode('*', $part)[0]];
        } elseif (is_numeric($part)) {
            $freqs = [(int) $part];
        }

        $element = [
            'freqs' => $freqs,
            'durationMs' => $duration,
        ];
        if ($once) {
            $element['once'] = true;
        }
        $elements[] = $element;
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
        $tones[$toneName] = array_map(
            static fn (array $e): array => array_filter($e, static fn ($v) => $v !== null),
            $elements
        );
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
  tones: Record<string, ToneElement[]>;
}

export const DEFAULT_COUNTRY = 'us';

export const TONE_INDICATIONS: Record<string, ToneSet> = 
TS;

echo $json.";\n";

echo <<<'TS'

export function getToneSet(country: string): ToneSet {
  const code = country.toLowerCase().trim();
  return TONE_INDICATIONS[code] ?? TONE_INDICATIONS[DEFAULT_COUNTRY];
}

export function getToneSequence(country: string, toneName: string): ToneElement[] | null {
  return getToneSet(country).tones[toneName] ?? null;
}
TS;
