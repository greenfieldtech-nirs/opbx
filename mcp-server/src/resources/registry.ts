import type { OperationRef } from "../opbx/services/read-service.js";
import type { UserRole } from "../security/permissions.js";
import type { McpRequestContext } from "../server/context.js";

/**
 * MCP resource registry. Resources are read-only JSON snapshots of OPBX
 * entities, mirroring the corresponding get_* tools (same RBAC).
 */

export interface EntityResourceDefinition {
  name: string;
  /** URI template with one {id} variable, e.g. "opbx://extensions/{id}". */
  uriTemplate: string;
  title: string;
  description: string;
  /** Path placeholder name in the OPBX spec path. */
  pathParam: string;
  operation: OperationRef;
  requiredRoles?: readonly UserRole[];
}

export interface StaticResourceDefinition {
  name: string;
  uri: string;
  title: string;
  description: string;
  read: (ctx: McpRequestContext) => Promise<unknown>;
}

const entityRegistry = new Map<string, EntityResourceDefinition>();
const staticRegistry = new Map<string, StaticResourceDefinition>();

export function defineEntityResource(def: EntityResourceDefinition): void {
  if (entityRegistry.has(def.name)) {
    throw new Error(`Duplicate MCP resource registration: ${def.name}`);
  }
  entityRegistry.set(def.name, def);
}

export function defineStaticResource(def: StaticResourceDefinition): void {
  if (staticRegistry.has(def.uri)) {
    throw new Error(`Duplicate MCP static resource: ${def.uri}`);
  }
  staticRegistry.set(def.uri, def);
}

export function allEntityResources(): EntityResourceDefinition[] {
  return [...entityRegistry.values()];
}

export function allStaticResources(): StaticResourceDefinition[] {
  return [...staticRegistry.values()];
}
