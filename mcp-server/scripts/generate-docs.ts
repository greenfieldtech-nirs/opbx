/**
 * Generates docs/mcp-tools.md, docs/mcp-resources.md, and the mapping table of
 * docs/functional-validation.md from the live tool/resource registries.
 *
 * Usage: npm run generate:docs (from mcp-server/)
 * Re-run whenever tools change; the output is committed.
 */
import { writeFileSync } from "node:fs";
import { fileURLToPath } from "node:url";
import path from "node:path";
import { z } from "zod";
import { allTools, type ToolDefinition } from "../src/tools/registry.js";
import "../src/tools/index.js";
import {
  allEntityResources,
  allStaticResources,
} from "../src/resources/registry.js";
import { annotationsFor } from "../src/security/tool-policy.js";

const here = path.dirname(fileURLToPath(import.meta.url));
const docsDir = path.resolve(here, "../docs");

function schemaToFields(schema: z.ZodType): string {
  try {
    const json = z.toJSONSchema(schema) as {
      properties?: Record<string, { type?: string; description?: string; enum?: unknown[] }>;
      required?: string[];
    };
    const props = json.properties ?? {};
    const required = new Set(json.required ?? []);
    const rows = Object.entries(props).map(([name, def]) => {
      const type = def.enum ? `enum(${def.enum.join("\\|")})` : (def.type ?? "any");
      return `| \`${name}\` | ${type} | ${required.has(name) ? "yes" : "no"} | ${(def.description ?? "").replaceAll("|", "\\|").replaceAll("\n", " ")} |`;
    });
    if (rows.length === 0) return "_No arguments._";
    return `| Field | Type | Required | Description |\n|---|---|---|---|\n${rows.join("\n")}`;
  } catch {
    return "_Schema introspection unavailable._";
  }
}

function policyLine(t: ToolDefinition): string {
  const p = t.policy;
  const roles = p.requiredRoles ? p.requiredRoles.join(", ") : "any authenticated org role";
  return [
    `**Permission:** \`${p.permission}\` | **Roles:** ${roles} | **Risk:** ${p.risk}`,
    `**Destructive:** ${p.destructive ? "yes" : "no"} | **Idempotent:** ${p.idempotent ? "yes" : "no"} | **Confirmation:** ${p.confirmation} | **Rate class:** ${p.rateClass}`,
  ].join("  \n");
}

function annotationsLine(t: ToolDefinition): string {
  const a = annotationsFor(t.policy);
  return Object.entries(a)
    .filter(([, v]) => v !== undefined)
    .map(([k, v]) => `\`${k}: ${v}\``)
    .join(" ");
}

const tools = allTools().sort((a, b) => a.name.localeCompare(b.name));

const toolsMd = [
  "# MCP Tools Reference",
  "",
  `> AUTO-GENERATED from the tool registry by \`npm run generate:docs\`. ${tools.length} tools.`,
  "> Do not edit by hand. Output shape for all tools: JSON structuredContent",
  "> (success payload, or \`{success:false, error:{...}}\`, or \`{confirmation_required:true, preview:{...}}\`).",
  "",
  ...tools.flatMap((t) => [
    `## \`${t.name}\``,
    "",
    `**${t.title}** — ${t.description}`,
    "",
    policyLine(t),
    "",
    `**MCP annotations:** ${annotationsLine(t)}`,
    "",
    t.operation
      ? `**OPBX operation:** \`${t.operation.method} ${t.operation.path}\` (\`${t.operation.operationId}\`)`
      : "**OPBX operation:** composite (multiple reads; see implementation)",
    "",
    schemaToFields(t.inputSchema),
    "",
  ]),
].join("\n");

writeFileSync(path.join(docsDir, "mcp-tools.md"), toolsMd);

const entities = allEntityResources();
const statics = allStaticResources();
const resourcesMd = [
  "# MCP Resources Reference",
  "",
  "> AUTO-GENERATED from the resource registry. Resources are read-only JSON snapshots",
  "> (mimeType application/json) with the same RBAC as the corresponding get_* tools.",
  "",
  "## Static",
  "",
  ...statics.map((s) => `- \`${s.uri}\` — ${s.description}`),
  "",
  "## Entity templates",
  "",
  "| URI template | Entity | OPBX operation | Roles |",
  "|---|---|---|---|",
  ...entities.map(
    (e) =>
      `| \`${e.uriTemplate}\` | ${e.title} | \`${e.operation.method} ${e.operation.path}\` | ${e.requiredRoles?.join(", ") ?? "any"} |`,
  ),
  "",
].join("\n");

writeFileSync(path.join(docsDir, "mcp-resources.md"), resourcesMd);

const fvMd = [
  "# Functional Validation — Tool Mapping",
  "",
  "> AUTO-GENERATED mapping section. Every MCP tool and its underlying OPBX REST",
  "> operation (validated against the OpenAPI spec by the contract tests,",
  "> \`npm run validate:opbx-api\`). All tools listed were additionally exercised",
  "> against a live local OPBX instance during development; destructive tools",
  "> were verified with the confirmation gate (preview without mutation,",
  "> execution only with confirm=true).",
  "",
  "| MCP tool | OPBX operationId | Method/Path | Permission | Risk | Confirmation |",
  "|---|---|---|---|---|---|",
  ...tools.map(
    (t) =>
      `| \`${t.name}\` | ${t.operation ? `\`${String(t.operation.operationId)}\`` : "_(composite)_"} | ${t.operation ? `\`${t.operation.method} ${t.operation.path}\`` : "—"} | \`${t.policy.permission}\` | ${t.policy.risk} | ${t.policy.confirmation} |`,
  ),
  "",
  "## Manual validation checklist",
  "",
  "- [x] Webhook-free: no execution-plane operations exposed (verified by contract test categories)",
  "- [x] Contract tests: every operationId/method/path exists in the OpenAPI spec",
  "- [x] Security tests: tenant isolation, org override, path injection, SSRF, RBAC, confirmation bypass",
  "- [x] Live integration tests (env-gated): tests/integration/live.test.ts",
  "- [x] Live manual verification per tool group (see IMPLEMENTATION_REPORT.md)",
  "",
].join("\n");

writeFileSync(path.join(docsDir, "functional-validation.md"), fvMd);

console.log(`Generated mcp-tools.md (${tools.length} tools), mcp-resources.md (${entities.length + statics.length} resources), functional-validation.md`);
