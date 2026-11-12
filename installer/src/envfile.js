import * as nodeFs from 'node:fs';
import { basename, dirname, join } from 'node:path';
import { randomBytes } from 'node:crypto';

const LINE = /^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$/;

function parseValue(raw) {
  const v = raw.trimStart();
  const q = v[0];
  if (q === '"' || q === "'") {
    let out = '';
    for (let i = 1; i < v.length; i++) {
      if (q === '"' && v[i] === '\\' && i + 1 < v.length) {
        const n = v[++i];
        out += n === 'n' ? '\n' : n;
      } else if (v[i] === q) return out;
      else out += v[i];
    }
    return out; // unterminated: take the rest
  }
  return v.replace(/\s+#.*$/, '').trim();
}

export function parseEnv(text) {
  const m = new Map();
  for (const line of text.split(/\r?\n/)) {
    const hit = LINE.exec(line);
    if (hit) m.set(hit[1], parseValue(hit[2]));
  }
  return m;
}

function format(v) {
  if (/[\r\n]/.test(v)) throw new Error('env values cannot contain newlines');
  if (/^[A-Za-z0-9_@%+=:,./-]*$/.test(v)) return v;
  // single quotes: neither dotenv nor compose expands "$" there
  if (v.includes('$')) {
    if (v.includes("'")) throw new Error("env values cannot contain both '$' and a single quote");
    return `'${v}'`;
  }
  return `"${v.replace(/[\\"]/g, '\\$&')}"`;
}

export function renderEnv(templateText, values) {
  const used = new Set();
  const lines = templateText.split('\n').map((line) => {
    const hit = LINE.exec(line);
    if (!hit || !Object.hasOwn(values, hit[1])) return line;
    used.add(hit[1]);
    return `${hit[1]}=${format(String(values[hit[1]]))}`;
  });
  const extra = Object.keys(values).filter((k) => !used.has(k));
  let out = lines.join('\n');
  if (extra.length) {
    if (!out.endsWith('\n')) out += '\n';
    out += `\n# Added by installer\n${extra.map((k) => `${k}=${format(String(values[k]))}`).join('\n')}\n`;
  }
  return out;
}

// temp file in the same directory, then rename: readers see the old or the new file, never half of one.
export function writeFileAtomic(path, text, { mode = 0o600, uid, gid } = {}, fs = nodeFs) {
  const tmp = join(dirname(path), `.${basename(path)}.${randomBytes(4).toString('hex')}.tmp`);
  try {
    fs.writeFileSync(tmp, text, { mode });
    fs.chmodSync(tmp, mode); // writeFileSync's mode is masked by umask
    if (uid !== undefined && gid !== undefined) fs.chownSync(tmp, uid, gid);
    fs.renameSync(tmp, path);
  } catch (e) {
    try { fs.rmSync(tmp, { force: true }); } catch { /* best effort */ }
    throw e;
  }
}
