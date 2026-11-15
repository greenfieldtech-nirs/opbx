import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, mkdirSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { detectInstall } from '../src/detect.js';
import { chooseAction } from '../src/main.js';

const tmp = () => mkdtempSync(join(tmpdir(), 'opbx-detect-'));

test('empty dir is none', () => assert.equal(detectInstall(tmp()), 'none'));

test('empty volumes/mysql is none', () => {
  const d = tmp();
  mkdirSync(join(d, 'volumes/mysql'), { recursive: true });
  assert.equal(detectInstall(d), 'none');
});

test('.env with OPBX_INSTALL_STATE=incomplete is incomplete', () => {
  const d = tmp();
  writeFileSync(join(d, '.env'), 'A=1\nOPBX_INSTALL_STATE=incomplete\n');
  assert.equal(detectInstall(d), 'incomplete');
});

test('.env without the marker is complete', () => {
  const d = tmp();
  writeFileSync(join(d, '.env'), 'A=1\n');
  assert.equal(detectInstall(d), 'complete');
});

test('no .env but mysql data is complete', () => {
  const d = tmp();
  mkdirSync(join(d, 'volumes/mysql'), { recursive: true });
  writeFileSync(join(d, 'volumes/mysql/ibdata1'), 'x');
  assert.equal(detectInstall(d), 'complete');
});

test('chooseAction dispatch', () => {
  assert.equal(chooseAction('install', 'none'), 'install');
  assert.equal(chooseAction('install', 'incomplete'), 'resume');
  assert.equal(chooseAction('install', 'complete'), 'offer-reset-or-reconfigure');
  assert.equal(chooseAction('reset', 'none'), 'reset');
  assert.equal(chooseAction('reconfigure', 'none'), 'nothing-to-reconfigure');
  assert.equal(chooseAction('reconfigure', 'complete'), 'reconfigure');
  assert.throws(() => chooseAction('bogus', 'none'), /Unknown mode/);
});
