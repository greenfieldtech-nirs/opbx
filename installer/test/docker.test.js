import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createDocker } from '../src/docker.js';

const sh = createDocker({ bin: 'sh' });

test('non-zero exit resolves with code and stderr', async () => {
  const r = await sh.run(['-c', 'echo out; echo err >&2; exit 3']);
  assert.deepEqual(r, { code: 3, stdout: 'out\n', stderr: 'err\n' });
});

test('input reaches the child stdin', async () => {
  const r = await sh.run(['-c', 'cat'], { input: 'héllo' });
  assert.equal(r.stdout, 'héllo');
});

test('child exiting without reading stdin does not crash', async () => {
  const r = await sh.run(['-c', 'exit 0'], { input: 'x'.repeat(5_000_000) });
  assert.equal(r.code, 0);
});

test('stdoutTo streams stdout without buffering it', async () => {
  const { PassThrough } = await import('node:stream');
  const sink = new PassThrough(); const got = [];
  sink.on('data', (d) => got.push(d));
  const r = await sh.run(['-c', 'printf hello'], { stdoutTo: sink });
  assert.equal(r.code, 0);
  assert.equal(r.stdout, '');
  assert.equal(Buffer.concat(got).toString(), 'hello');
});
