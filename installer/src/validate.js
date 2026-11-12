const V4 = /^\d{1,3}(\.\d{1,3}){3}$/;
const LABEL = /^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/;

// { ok: true, value: 'https://host[:port]' } | { ok: false, reason }
export function normalizePublicUrl(input, mode) {
  let s = String(input ?? '').trim();
  if (!s) return { ok: false, reason: 'Enter an address.' };
  if (!/^[a-z][a-z0-9+.-]*:\/\//i.test(s)) s = `https://${s}`;
  let u;
  try { u = new URL(s); } catch { return { ok: false, reason: 'That is not a valid address.' }; }
  if (u.protocol !== 'https:' && u.protocol !== 'http:') return { ok: false, reason: 'Use an http:// or https:// address.' };
  if (u.username || u.password) return { ok: false, reason: 'Remove the user name and password from the address.' };
  if (u.search || u.hash) return { ok: false, reason: 'Remove the query string from the address.' };
  if (u.pathname !== '/') return { ok: false, reason: 'Use only the server address, without a path (no "/ui" or similar).' };
  const host = u.hostname;
  const isIp = V4.test(host) || host.startsWith('[');
  if (!isIp && !host.split('.').every((l) => LABEL.test(l))) return { ok: false, reason: 'That is not a valid host name.' };
  if (mode === 'production') {
    if (u.protocol !== 'https:') return { ok: false, reason: 'A production server must use HTTPS (https://).' };
    if (!host.includes('.') && !host.startsWith('[')) return { ok: false, reason: 'Use the real domain name your users will open (for example pbx.example.com), not localhost or a single-word name.' };
    if (isIp) return { ok: false, reason: 'Use a domain name, not an IP address: HTTPS certificates are issued for names.' };
  }
  return { ok: true, value: u.origin };
}

export const isEmail = (s) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(s ?? ''));
export const isPortNumber = (n) => Number.isInteger(n) && n >= 1 && n <= 65535;

// Keep in step with valid_addr() in docker/nginx/render.sh (nginx re-validates).
function validAddr(e) {
  const parts = e.split('/');
  if (parts.length > 2) return false;
  const [a, bits] = parts;
  if (parts.length === 2 && !/^\d{1,3}$/.test(bits)) return false;
  if (/^[0-9.]+$/.test(a)) {
    const o = a.split('.');
    return o.length === 4 && o.every((x) => /^\d{1,3}$/.test(x) && +x <= 255) && (bits === undefined || +bits <= 32);
  }
  if (!/^[0-9a-fA-F:]+$/.test(a) || !a.includes(':') || a.includes(':::')) return false;
  const halves = a.split('::');
  if (halves.length > 2) return false;
  const groups = halves.flatMap((h) => (h === '' ? [] : h.split(':')));
  if (!groups.every((g) => /^[0-9a-fA-F]{1,4}$/.test(g))) return false;
  if (halves.length === 1 ? groups.length !== 8 : groups.length > 7) return false;
  return bits === undefined || +bits <= 128;
}

export function parseCidrList(s) {
  if (!String(s ?? '').trim()) return { ok: true, value: [] };
  const value = String(s).split(',').map((x) => x.trim());
  const bad = value.find((x) => !validAddr(x));
  return bad === undefined ? { ok: true, value } : { ok: false, value: [], reason: `"${bad}" is not an IP address or network (for example 10.0.0.0/8).` };
}

// Safe to paste under sudo and to put in a --mount spec: no spaces, commas, quotes, ";" or "..".
export const isSafeAbsolutePath = (s) =>
  typeof s === 'string' && /^\/[A-Za-z0-9._/-]+$/.test(s) && !s.split('/').includes('..');
