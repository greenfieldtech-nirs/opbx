import type { Config } from "../config/index.js";
import type { RateClass } from "./tool-policy.js";
import type { AuthenticatedIdentity } from "./permissions.js";

/**
 * Per-identity, per-rate-class sliding window limiter.
 *
 * ponytail: in-memory and per-process. With N replicas behind a load balancer
 * the effective limit is N× the configured value; move to a shared store
 * (Redis) if that ceiling matters. OPBX itself enforces 60/min per org
 * upstream, which is the real backstop.
 */
export class RateLimiter {
  private readonly hits = new Map<string, number[]>();

  constructor(private readonly config: Config) {}

  private limitFor(rateClass: RateClass): number {
    switch (rateClass) {
      case "read":
        return this.config.RATE_LIMIT_READ_PER_MIN;
      case "write":
        return this.config.RATE_LIMIT_WRITE_PER_MIN;
      case "sensitive":
      case "campaign":
      case "live_call":
      case "bulk":
        return this.config.RATE_LIMIT_SENSITIVE_PER_MIN;
    }
  }

  /** Throws Error("rate_limited") when the caller exceeded the class limit. */
  check(identity: AuthenticatedIdentity, rateClass: RateClass, toolName: string): void {
    const subject =
      identity.principalType === "user"
        ? `u:${identity.userId}`
        : "k:apikey";
    const key = `${subject}:${rateClass}`;
    const now = Date.now();
    const windowStart = now - 60_000;
    const limit = this.limitFor(rateClass);

    let list = this.hits.get(key);
    if (!list) {
      list = [];
      this.hits.set(key, list);
    }
    while (list.length > 0 && list[0]! <= windowStart) list.shift();
    if (list.length >= limit) {
      throw new RateLimitError(
        `Rate limit for ${rateClass} operations exceeded (max ${limit}/min). Tool: ${toolName}`,
      );
    }
    list.push(now);
  }
}

export class RateLimitError extends Error {
  override name = "RateLimitError";
}
