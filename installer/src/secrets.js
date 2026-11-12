import { randomBytes, randomInt } from 'node:crypto';

const hex = (bytes) => randomBytes(bytes).toString('hex');
const pick = (alphabet, n) => Array.from({ length: n }, () => alphabet[randomInt(alphabet.length)]).join('');

// The 7 shared secrets stay with docker/scripts/generate-secrets.sh.
export const generateOpbxSecrets = () => ({
  APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
  DB_PASSWORD: hex(32),
  DB_ROOT_PASSWORD: hex(32),
  REDIS_PASSWORD: hex(32),
});

export const generateSafelineSecrets = () => ({
  POSTGRES_PASSWORD: hex(16),
  MGT_INTERNAL_TOKEN: hex(32),
  DETECTOR_MGMT_TOKEN: hex(32),
  DETECTOR_AES_KEY: pick('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789', 16),
});

// Unambiguous alphabet (no I, O, 0, 1): xxxx-xxxx-xxxx-xxxx-xxxx
export const generateOwnerPassword = () =>
  Array.from({ length: 5 }, () => pick('ABCDEFGHJKLMNPQRSTUVWXYZ23456789', 4)).join('-');
