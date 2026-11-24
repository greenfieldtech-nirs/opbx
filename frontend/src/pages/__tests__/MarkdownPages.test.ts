import { readFileSync } from 'node:fs';
import path from 'node:path';
import { describe, expect, it } from 'vitest';
import { features } from '../Home/FeaturesGrid';
import { faqs } from '../Home/FAQSection';
import { steps } from '../Home/HowItWorks';
import { cloudFeatures, fdeFeatures, selfHostedFeatures } from '../Home/Pricing';
import { ASKS, DEV_PORTS, EVERYDAY, REQUIREMENTS, TRANSCRIPT, TROUBLE, UPGRADE_STEPS } from '../InstallGuide';
import { SECTIONS, TERMS_UPDATED } from '../TermsOfUse';

// The public/*.md files are hand-kept Markdown copies of these pages for AI agents (and llms-full.txt is
// built from them). Every piece of page content must appear in its copy, so editing a page without its
// .md fails here.
const md = (file: string) =>
  readFileSync(path.resolve(import.meta.dirname, '../../../public', file), 'utf8').replace(/\s+/g, ' ');
const norm = (s: string) => s.replace(/\s+/g, ' ').trim();

function expectAllIn(file: string, texts: string[]) {
  const doc = md(file);
  const missing = texts.map(norm).filter((t) => !doc.includes(t));
  expect(missing, `missing from public/${file}`).toEqual([]);
}

describe('Markdown copies of the public pages', () => {
  it('index.html.md has the homepage features, steps, pricing and FAQ', () => {
    expectAllIn('index.html.md', [
      ...features.flatMap((f) => [f.title, f.description]),
      ...steps.flatMap((s) => [s.title, s.description]),
      ...selfHostedFeatures,
      ...cloudFeatures,
      ...fdeFeatures,
      ...faqs.flatMap((f) => [f.question, f.answer]),
    ]);
  });

  it('install.md has every requirement, question, command, upgrade step and troubleshooting entry', () => {
    expectAllIn('install.md', [
      ...REQUIREMENTS.flat(),
      ...ASKS,
      TRANSCRIPT,
      ...EVERYDAY.flat(),
      ...UPGRADE_STEPS,
      ...DEV_PORTS.flatMap(([port, ...rest]) => [String(port), ...rest]),
      ...TROUBLE.flatMap(({ q, a, code }) => [q, a, ...(code ? [code] : [])]),
    ]);
  });

  it('terms.md has every section of the Terms of Use, word for word', () => {
    expectAllIn('terms.md', [
      `Last updated ${TERMS_UPDATED}`,
      ...SECTIONS.flatMap((s, i) => [
        `${i + 1}. ${s.title}`,
        ...s.body.flatMap((b) => {
          if (typeof b === 'string') return [b];
          if ('lead' in b) return [b.lead, b.text];
          if ('list' in b) return b.list;
          return b.defs.flatMap(([term, meaning]) => [term, meaning]);
        }),
      ]),
    ]);
  });
});
