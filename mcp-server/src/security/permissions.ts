/**
 * OPBX role model, validated against app/Enums/UserRole.php.
 */
export const USER_ROLES = [
  "owner",
  "pbx_admin",
  "pbx_user",
  "reporter",
  "supervisor",
] as const;

export type UserRole = (typeof USER_ROLES)[number];

/** Roles allowed to manage PBX configuration (owner + pbx_admin). */
export const CONFIG_ROLES: readonly UserRole[] = ["owner", "pbx_admin"];

export interface AuthenticatedIdentity {
  /** How the caller authenticated to OPBX. */
  principalType: "user" | "apikey";
  /** OPBX user id (user principals only). */
  userId?: number;
  /** Organization id; absent for apikey principals (implicit upstream). */
  organizationId?: number;
  organizationName?: string;
  /** OPBX role (user principals only; apikey principals are upstream-scoped). */
  role?: UserRole;
  isPlatformManager?: boolean;
}

/**
 * Role check for user principals. ApiKey principals return `true` here:
 * their authorization is the per-resource EnforceApiKeyScope gate in OPBX
 * (deny-by-default), which cannot be introspected ahead of the call.
 */
export function identityHasAnyRole(
  identity: AuthenticatedIdentity,
  roles: readonly UserRole[],
): boolean {
  if (identity.principalType === "apikey") return true;
  return identity.role !== undefined && roles.includes(identity.role);
}
