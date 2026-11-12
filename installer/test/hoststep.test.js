import { test } from 'node:test';
import assert from 'node:assert/strict';
import { hostStep } from '../src/hoststep.js';

test('check fails twice then passes: three prompts, missing shown each time', async () => {
  const notes = [];
  let prompts = 0;
  const ui = { note: (t, b) => notes.push(`${t}\n${b}`), text: async () => { prompts++; return ''; } };
  const results = [{ ok: false, missing: ['dir a'] }, { ok: false, missing: ['dir b'] }, { ok: true, missing: [] }];
  await hostStep(ui, { title: 'T', explain: 'E', commands: ['sudo x'], check: async () => results.shift() });
  assert.equal(prompts, 3);
  assert.ok(notes[0].includes('sudo x') && notes[0].includes('E'));
  assert.ok(notes.some((n) => n.includes('dir a')));
  assert.ok(notes.some((n) => n.includes('dir b')));
});
