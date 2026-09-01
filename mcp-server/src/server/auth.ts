import { createHash } from "node:crypto";
import type { Config } from "../config/index.js";
import type { Logger } from "pino";
import { OpbxClient } from "../opbx/client.js";
import {
  USER_ROLES,
  type AuthenticatedIdentity,
  type UserRole,
} from "../security/permissions.js";

/**
 * OPBX credential pass-through authentication.
 *
 * The MCP client presents its own OPBX credential (Bearer). Two kinds exist
 * in OPBX (validated against source):
 *
 *  - Sanctum PAT (`id|token`): resolved via GET /v1/auth/me, which returns
 *    user id, organization, role and platform-manager flag. NOTE: PATs expire
 *    after 24h and are revoked on any login/refresh/password change upstream.
 *  - Scoped API key (`opbxk_…`): OPBX has no identity echo endpoint for keys
 *    and keys are forbidden from calling /auth/me. The key is forwarded
 *    untouched; org scoping and per-resource authorization are enforced
 *    upstream by EnforceApiKeyScope (deny-by-default). Identity is therefore
 *    minimal and RBAC is delegated to OPBX for these principals.
 *
 * The credential is never logged and never exposed back to the MCP client.
 */

const API_KEY_PREFIX = "opbxk_";

export class AuthenticationError extends Error {
  override name = "AuthenticationError";
}

interface CacheEntry {
  identity: AuthenticatedIdentity;
  expiresAt: number;
}

export class IdentityResolver {
  /** Identity cache keyed by credential hash. In-memory by design (stateless,
   * rebuildable); a restart just costs one extra /auth/me per caller. */
  private readonly cache = new Map<string, CacheEntry>();
  private static readonly MAX_ENTRIES = 10_000;

  constructor(
    private readonly config: Config,
    private readonly logger: Logger,
  ) {}

  /** Extract the bearer credential from an Authorization header value. */
  static credentialFromHeader(header: string | undefined): string {
    if (!header) throw new AuthenticationError("Missing Authorization header");
    const match = /^Bearer\s+(\S+)$/i.exec(header.trim());
    if (!match || !match[1]) {
      throw new AuthenticationError("Authorization header must be a Bearer token");
    }
    return match[1];
  }

  async resolve(credential: string): Promise<AuthenticatedIdentity> {
    if (credential.startsWith(API_KEY_PREFIX)) {
      return { principalType: "apikey" };
    }

    const cacheKey = createHash("sha256").update(credential).digest("hex");
    const cached = this.cache.get(cacheKey);
    if (cached && cached.expiresAt > Date.now()) {
      return cached.identity;
    }

    const client = new OpbxClient(this.config, credential, this.logger);
    const response = await client.call("GET", "/v1/auth/me", "getCurrentUser");
    const user = response.user;
    if (!user?.id || !user.organization_id) {
      throw new AuthenticationError("OPBX identity response was incomplete");
    }
    const role = USER_ROLES.includes(user.role as UserRole)
      ? (user.role as UserRole)
      : undefined;
    if (!role) {
      throw new AuthenticationError(`Unknown OPBX role: ${String(user.role)}`);
    }

    const identity: AuthenticatedIdentity = {
      principalType: "user",
      userId: user.id,
      organizationId: user.organization_id,
      ...(user.organization?.name !== undefined
        ? { organizationName: user.organization.name }
        : {}),
      role,
      isPlatformManager: user.is_platform_manager === true,
    };

    if (this.cache.size >= IdentityResolver.MAX_ENTRIES) {
      // Cheap eviction: drop expired entries, else clear oldest quarter.
      const now = Date.now();
      for (const [k, v] of this.cache) if (v.expiresAt <= now) this.cache.delete(k);
      if (this.cache.size >= IdentityResolver.MAX_ENTRIES) {
        let n = IdentityResolver.MAX_ENTRIES / 4;
        for (const k of this.cache.keys()) {
          this.cache.delete(k);
          if (--n <= 0) break;
        }
      }
    }
    this.cache.set(cacheKey, {
      identity,
      expiresAt: Date.now() + this.config.AUTH_IDENTITY_CACHE_TTL_SECONDS * 1000,
    });
    return identity;
  }
}
