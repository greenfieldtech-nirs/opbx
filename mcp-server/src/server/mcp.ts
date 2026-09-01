import { McpServer, ResourceTemplate } from "@modelcontextprotocol/sdk/server/mcp.js";
import { McpError, ErrorCode } from "@modelcontextprotocol/sdk/types.js";
import type { z } from "zod";
import type { Logger } from "pino";
import type { Config } from "../config/index.js";
import type { McpRequestContext } from "./context.js";
import type { RateLimiter } from "../security/rate-limiter.js";
import { RateLimitError } from "../security/rate-limiter.js";
import { identityHasAnyRole } from "../security/permissions.js";
import { annotationsFor } from "../security/tool-policy.js";
import { OpbxError } from "../opbx/errors.js";
import { withSpan } from "../telemetry/tracing.js";
import { allTools, type ToolDefinition } from "../tools/registry.js";
import { allEntityResources, allStaticResources } from "../resources/registry.js";
import { getResource } from "../opbx/services/read-service.js";
import { registerPrompts } from "../prompts/index.js";

/**
 * Builds a fresh McpServer for one authenticated request (stateless mode).
 * Every tool invocation is independently authorized and rate-limited;
 * tools/list filtering, if added later, is not a security boundary.
 */
export function buildMcpServer(
  ctx: McpRequestContext,
  config: Config,
  logger: Logger,
  limiter: RateLimiter,
): McpServer {
  const server = new McpServer(
    { name: "opbx-mcp", version: "0.1.0" },
    { capabilities: { tools: {}, resources: {}, prompts: {} } },
  );

  for (const tool of allTools()) {
    registerTool(server, tool, ctx, logger, limiter);
  }
  registerResources(server, ctx, logger);
  registerPrompts(server);
  return server;
}

function registerResources(server: McpServer, ctx: McpRequestContext, logger: Logger): void {
  for (const def of allStaticResources()) {
    server.registerResource(
      def.name,
      def.uri,
      { title: def.title, description: def.description, mimeType: "application/json" },
      async (uri) => ({
        contents: [{ uri: uri.href, mimeType: "application/json", text: JSON.stringify(await def.read(ctx), null, 2) }],
      }),
    );
  }
  for (const def of allEntityResources()) {
    server.registerResource(
      def.name,
      new ResourceTemplate(def.uriTemplate, { list: undefined }),
      { title: def.title, description: def.description, mimeType: "application/json" },
      async (uri, variables) => {
        // Same RBAC as the corresponding get_* tool.
        if (def.requiredRoles && !identityHasAnyRole(ctx.identity, def.requiredRoles)) {
          throw new McpError(
            ErrorCode.InvalidRequest,
            `Your role is not permitted to read ${def.name} resources.`,
          );
        }
        const id = variables.id;
        const log = logger.child({ mcp_resource: def.name, resource_id: id, request_id: ctx.requestId });
        try {
          const entity = await getResource(ctx.opbx, def.operation, {
            [def.pathParam]: id as string,
          });
          return {
            contents: [{ uri: uri.href, mimeType: "application/json", text: JSON.stringify(entity, null, 2) }],
          };
        } catch (err) {
          log.warn({ err: err instanceof Error ? err.message : String(err) }, "resource read failed");
          if (err instanceof OpbxError) {
            throw new McpError(ErrorCode.InvalidRequest, `${err.type}: ${err.message}`);
          }
          throw new McpError(ErrorCode.InternalError, "Failed to read resource");
        }
      },
    );
  }
}

function registerTool(
  server: McpServer,
  tool: ToolDefinition,
  ctx: McpRequestContext,
  logger: Logger,
  limiter: RateLimiter,
): void {
  server.registerTool(
    tool.name,
    {
      title: tool.title,
      description: tool.description,
      inputSchema: tool.inputSchema as unknown as z.ZodObject<z.ZodRawShape>,
      annotations: annotationsFor(tool.policy),
    },
    async (args) => {
      const log = logger.child({
        mcp_tool: tool.name,
        request_id: ctx.requestId,
        trace_id: ctx.traceId,
        organization_id: ctx.identity.organizationId,
        user_id: ctx.identity.userId,
        permission: tool.policy.permission,
      });

      return withSpan(
        `mcp.tool.${tool.name}`,
        {
          "mcp.tool": tool.name,
          "mcp.permission": tool.policy.permission,
          "opbx.organization_id": ctx.identity.organizationId,
          "opbx.user_id": ctx.identity.userId,
          "request.id": ctx.requestId,
        },
        async () => {
          try {
            // 1. RBAC — every invocation, independent of tool-list visibility.
            if (
              tool.policy.requiredRoles &&
              !identityHasAnyRole(ctx.identity, tool.policy.requiredRoles)
            ) {
              log.warn("tool invocation denied by RBAC");
              return errorResult({
                type: "authorization_error",
                message: `Your role (${ctx.identity.role ?? "api-key"}) is not permitted to use ${tool.name}.`,
              });
            }

            // 2. MCP-side rate limiting.
            limiter.check(ctx.identity, tool.policy.rateClass, tool.name);

            // 3. Confirmation gate. High-impact tools must be invoked with
            //    `confirm: true`; before that they return a live impact
            //    preview so the caller can make an informed decision.
            if (
              tool.policy.confirmation === "required" &&
              (args as Record<string, unknown>).confirm !== true
            ) {
              let preview: Record<string, unknown> | undefined;
              if (tool.preview) {
                try {
                  preview = await tool.preview(ctx, args as never);
                } catch (err) {
                  // If the target no longer exists, say so instead of previewing.
                  return mapError(err, log, ctx);
                }
              }
              return confirmationRequiredResult(tool.name, preview);
            }

            // 4. Execute.
            const result = await tool.handler(ctx, args as Record<string, unknown>);
            if (tool.policy.auditLevel === "elevated") {
              log.info({ audit: true }, "elevated tool executed");
            } else {
              log.info("tool executed");
            }
            return successResult(result);
          } catch (err) {
            return mapError(err, log, ctx);
          }
        },
      );
    },
  );
}

function successResult(result: Record<string, unknown>) {
  return {
    content: [{ type: "text" as const, text: JSON.stringify(result, null, 2) }],
    structuredContent: result,
  };
}

interface NormalizedErrorPayload {
  type: string;
  code?: string;
  message: string;
  field_errors?: { field: string; messages: string[] }[];
  retry_after_seconds?: number;
  suggested_action?: string;
}

function errorResult(error: NormalizedErrorPayload) {
  const payload = { success: false as const, error };
  return {
    isError: true as const,
    content: [{ type: "text" as const, text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload,
  };
}

function confirmationRequiredResult(
  toolName: string,
  preview?: Record<string, unknown>,
) {
  const payload = {
    success: false as const,
    confirmation_required: true as const,
    ...(preview ? { preview } : {}),
    message:
      `${toolName} is a high-impact operation. Review the preview with the user, ` +
      `then re-invoke with "confirm": true to execute.`,
  };
  return {
    // Not an MCP error: the caller should surface this and re-invoke.
    content: [{ type: "text" as const, text: JSON.stringify(payload, null, 2) }],
    structuredContent: payload,
  };
}

const SUGGESTED_ACTIONS: Record<string, string> = {
  validation_error: "Fix the indicated fields and retry.",
  authentication_error: "Obtain a valid OPBX token or API key.",
  authorization_error: "Use an identity with the required OPBX role.",
  not_found: "Verify the resource id; cross-organization access is masked as not-found.",
  conflict: "Inspect the resource state; it likely disallows this transition.",
  resource_in_use: "Remove or reassign referencing resources first.",
  rate_limited: "Back off and retry after the indicated delay.",
  upstream_error: "Retry; if persistent, check OPBX health.",
  network_error: "Check OPBX base URL and network connectivity.",
};

function mapError(err: unknown, log: Logger, ctx?: McpRequestContext) {
  if (err instanceof OpbxError) {
    log.warn(
      { error_type: err.type, error_code: err.code, http_status: err.httpStatus },
      "tool failed with OPBX error",
    );
    // Scope denials for API keys are about key grants, not user roles.
    const suggested =
      ctx?.identity.principalType === "apikey" && err.type === "authorization_error"
        ? "Ask an organization owner to grant the required resource/level to this API key (OPBX Settings → API Keys), or use a user token."
        : SUGGESTED_ACTIONS[err.type];
    return errorResult({
      type: err.type,
      ...(err.code ? { code: err.code } : {}),
      message: err.message,
      ...(err.fieldErrors ? { field_errors: err.fieldErrors } : {}),
      ...(err.retryAfterSeconds !== undefined
        ? { retry_after_seconds: err.retryAfterSeconds }
        : {}),
      suggested_action: suggested,
    });
  }
  if (err instanceof RateLimitError) {
    return errorResult({
      type: "rate_limited",
      message: err.message,
      suggested_action: SUGGESTED_ACTIONS.rate_limited,
    });
  }
  // Never leak internals.
  log.error({ err: err instanceof Error ? err.message : String(err) }, "tool failed with unexpected error");
  return errorResult({
    type: "internal_error",
    message: "An internal MCP server error occurred.",
    suggested_action: "Retry; if persistent, contact the administrator with the request id.",
  });
}
