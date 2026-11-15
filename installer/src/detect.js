import { existsSync, readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';

// 'none' | 'incomplete' | 'complete'
export function detectInstall(repoDir) {
  const envPath = join(repoDir, '.env');
  const hasEnv = existsSync(envPath);
  const mysqlDir = join(repoDir, 'volumes', 'mysql');
  const hasData = existsSync(mysqlDir) && readdirSync(mysqlDir).length > 0;
  if (!hasEnv && !hasData) return 'none';
  if (hasEnv && /^OPBX_INSTALL_STATE=incomplete\s*$/m.test(readFileSync(envPath, 'utf8'))) return 'incomplete';
  return 'complete';
}
