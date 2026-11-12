import { test } from 'node:test';
import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, writeFileSync, readdirSync, statSync } from 'node:fs';
import * as realFs from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { parseEnv, renderEnv, writeFileAtomic, envFileOpts } from '../src/envfile.js';

const TEMPLATE = `# ═══ APPLICATION
APP_NAME="OPBX - Open Source Business PBX"
APP_KEY=                             # Generate: php artisan key:generate
DB_PASSWORD=                        # Generate: openssl rand -base64 32
# DB_EXPOSE_PORT=3306
MAIL_FROM_NAME="\${APP_NAME}"
`;

test('parseEnv: comments, quotes, inline comments', () => {
  const m = parseEnv(`# c\nA=1\nB="x y" # note\nC='q # r'\nD=\nE=v   # gone\nexport F=2\n\nG="a\\"b"\nH=a#b\n`);
  assert.equal(m.get('A'), '1');
  assert.equal(m.get('B'), 'x y');
  assert.equal(m.get('C'), 'q # r');
  assert.equal(m.get('D'), '');
  assert.equal(m.get('E'), 'v');
  assert.equal(m.get('F'), '2');
  assert.equal(m.get('G'), 'a"b');
  assert.equal(m.get('H'), 'a#b');
  assert.equal(m.size, 8);
});

test('renderEnv replaces, keeps comments, appends unknown, idempotent', () => {
  const values = { DB_PASSWORD: 'abc123', APP_NAME: 'My PBX', NEW_KEY: 'x', WITH_DOLLAR: 'a$b' };
  const out = renderEnv(TEMPLATE, values);
  assert.match(out, /^DB_PASSWORD=abc123$/m);
  assert.ok(!out.includes('Generate: openssl'));
  assert.match(out, /^APP_KEY=\s+# Generate: php artisan/m); // untouched key keeps its comment
  assert.match(out, /^APP_NAME="My PBX"$/m);
  assert.match(out, /^# DB_EXPOSE_PORT=3306$/m);
  assert.match(out, /^# ═══ APPLICATION$/m);
  assert.match(out, /# Added by installer\nNEW_KEY=x\nWITH_DOLLAR='a\$b'\n$/);
  assert.equal(renderEnv(out, values), out);
  const back = parseEnv(out);
  assert.equal(back.get('APP_NAME'), 'My PBX');
  assert.equal(back.get('WITH_DOLLAR'), 'a$b');
  assert.equal(back.get('DB_PASSWORD'), 'abc123');
});

test('renderEnv rejects values with both $ and a single quote', () => {
  assert.throws(() => renderEnv(TEMPLATE, { A: "a$b'c" }), /single quote/);
});

test('renderEnv rejects newlines in values', () => {
  assert.throws(() => renderEnv(TEMPLATE, { A: 'x\ny' }));
});

test('writeFileAtomic writes with mode and leaves no temp file', () => {
  const d = mkdtempSync(join(tmpdir(), 'wfa-'));
  const p = join(d, '.env');
  writeFileAtomic(p, 'new', { mode: 0o600 });
  assert.equal(readFileSync(p, 'utf8'), 'new');
  assert.equal(statSync(p).mode & 0o777, 0o600);
  assert.deepEqual(readdirSync(d), ['.env']);
});

test('writeFileAtomic: failure between write and rename keeps old content', () => {
  const d = mkdtempSync(join(tmpdir(), 'wfa-'));
  const p = join(d, '.env');
  writeFileSync(p, 'old');
  const boom = { ...realFs, renameSync() { throw new Error('boom'); } };
  assert.throws(() => writeFileAtomic(p, 'new', { mode: 0o600 }, boom), /boom/);
  assert.equal(readFileSync(p, 'utf8'), 'old');
  assert.deepEqual(readdirSync(d), ['.env']);
});

test('envFileOpts: 0640, host user + gid 82 (www-data in the app image) when root, mode only otherwise', () => {
  assert.deepEqual(envFileOpts({ HOST_UID: '1000', HOST_GID: '1000' }, true), { mode: 0o640, uid: 1000, gid: 82 });
  assert.deepEqual(envFileOpts({}, true), { mode: 0o640, uid: 0, gid: 82 });
  assert.deepEqual(envFileOpts({ HOST_UID: '1000' }, false), { mode: 0o640 });
});
