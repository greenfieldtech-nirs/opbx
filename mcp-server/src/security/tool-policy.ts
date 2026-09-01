/**
 * Security metadata for MCP tools. Kept separate from business logic.
 * Annotations map to MCP ToolAnnotations; the rest drives MCP-side policy
 * (RBAC checks, rate classes, confirmation requirements, audit level).
 */
import type { ToolAnnotations } from "@modelcontextprotocol/sdk/types.js";
import type { UserRole } from "./permissions.js";

export type RiskLevel = "low" | "medium" | "high";
export type RateClass = "read" | "write" | "sensitive" | "campaign" | "live_call" | "bulk";
export type AuditLevel = "standard" | "elevated";

export interface ToolPolicy {
  /** Human-readable permission name, e.g. "campaign.start". Used in audit logs. */
  permission: string;
  /** OPBX roles allowed to invoke. Undefined/empty = any authenticated org user. */
  requiredRoles?: readonly UserRole[];
  risk: RiskLevel;
  destructive: boolean;
  idempotent: boolean;
  /** High-impact operations require an explicit confirmation flow (Phase 5). */
  confirmation: "none" | "required";
  rateClass: RateClass;
  auditLevel: AuditLevel;
}

export function annotationsFor(policy: ToolPolicy): ToolAnnotations {
  const readOnly = policy.rateClass === "read" && !policy.destructive && policy.risk === "low";
  return {
    readOnlyHint: readOnly,
    destructiveHint: readOnly ? undefined : policy.destructive,
    idempotentHint: readOnly ? undefined : policy.idempotent,
    // Tools talk to the OPBX API — technically a closed world, but routing
    // changes affect live telephony; keep the honest default.
    openWorldHint: true,
  };
}

export const READ_POLICY = (
  permission: string,
  overrides: Partial<ToolPolicy> = {},
): ToolPolicy => ({
  permission,
  risk: "low",
  destructive: false,
  idempotent: true,
  confirmation: "none",
  rateClass: "read",
  auditLevel: "standard",
  ...overrides,
});

export const WRITE_POLICY = (
  permission: string,
  overrides: Partial<ToolPolicy> = {},
): ToolPolicy => ({
  permission,
  requiredRoles: ["owner", "pbx_admin"],
  risk: "medium",
  destructive: false,
  idempotent: false,
  confirmation: "none",
  rateClass: "write",
  auditLevel: "standard",
  ...overrides,
});

export const DESTRUCTIVE_POLICY = (
  permission: string,
  overrides: Partial<ToolPolicy> = {},
): ToolPolicy => ({
  permission,
  requiredRoles: ["owner", "pbx_admin"],
  risk: "high",
  destructive: true,
  idempotent: true,
  confirmation: "required",
  rateClass: "sensitive",
  auditLevel: "elevated",
  ...overrides,
});
