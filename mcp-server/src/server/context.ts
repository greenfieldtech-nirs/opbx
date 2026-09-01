import type { OpbxClient } from "../opbx/client.js";
import type { AuthenticatedIdentity } from "../security/permissions.js";

/** Trusted per-request context handed to every tool handler. */
export interface McpRequestContext {
  identity: AuthenticatedIdentity;
  opbx: OpbxClient;
  requestId: string;
  traceId?: string;
}
