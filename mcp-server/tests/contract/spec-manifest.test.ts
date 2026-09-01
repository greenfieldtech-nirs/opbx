/**
 * Contract tests: every MCP tool's OPBX operation reference must exist in the
 * real OpenAPI specification (method + path + operationId must agree), and
 * every path template placeholder must have a documented path parameter.
 *
 * These tests fail when OPBX changes in ways that invalidate the adapter —
 * this is the API-drift tripwire. Run via `npm run validate:opbx-api`.
 */
import { describe, expect, it } from "vitest";
import { readFileSync, readdirSync, statSync } from "node:fs";
import path from "node:path";
import yaml from "yaml";
import { allTools } from "../../src/tools/registry.js";
import "../../src/tools/index.js";
import { allEntityResources } from "../../src/resources/registry.js";
import "../../src/resources/entities.js";

const SPEC_PATHS_DIR = path.resolve(__dirname, "../../../docs/opbx-openapi/paths");

interface SpecOperation {
  method: string;
  path: string;
  operationId: string;
  pathParams: string[];
}

function* walk(dir: string): Generator<string> {
  for (const entry of readdirSync(dir)) {
    const full = path.join(dir, entry);
    if (statSync(full).isDirectory()) yield* walk(full);
    else if (entry.endsWith(".yaml")) yield full;
  }
}

function loadSpecOperations(): SpecOperation[] {
  const ops: SpecOperation[] = [];
  for (const file of walk(SPEC_PATHS_DIR)) {
    const doc = yaml.parse(readFileSync(file, "utf8")) as {
      paths?: Record<string, Record<string, { operationId?: string; parameters?: { name: string; in: string }[] }>>;
    } | null;
    if (!doc?.paths) continue;
    for (const [p, item] of Object.entries(doc.paths)) {
      for (const [method, op] of Object.entries(item ?? {})) {
        if (!op?.operationId) continue;
        const pathParams = (op.parameters ?? [])
          .filter((prm) => prm?.in === "path")
          .map((prm) => prm.name);
        // Also honor placeholders from the template itself.
        const fromTemplate = [...p.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]!);
        ops.push({
          method: method.toUpperCase(),
          path: p,
          operationId: op.operationId,
          pathParams: [...new Set([...pathParams, ...fromTemplate])],
        });
      }
    }
  }
  return ops;
}

const specOps = loadSpecOperations();
const byId = new Map(specOps.map((o) => [o.operationId, o]));

describe("OpenAPI contract", () => {
  it("loaded a sane number of spec operations", () => {
    expect(specOps.length).toBeGreaterThan(200);
  });

  const toolsWithOps = allTools().filter((t) => t.operation !== undefined);

  it("all registered tools declare an operation reference", () => {
    // Only composite tools may lack one.
    const without = allTools().filter((t) => t.operation === undefined).map((t) => t.name);
    expect(without).toEqual(["get_organization", "validate_configuration"]);
  });

  it.each(toolsWithOps.map((t) => [t.name, t] as const))(
    "tool %s maps to an existing spec operation",
    (_name, tool) => {
      const ref = tool.operation!;
      const spec = byId.get(ref.operationId as string);
      expect(
        spec,
        `operationId '${ref.operationId}' not found in OpenAPI spec`,
      ).toBeDefined();
      expect(spec!.method).toBe(ref.method);
      expect(
        spec!.path,
        `operationId '${ref.operationId}' moved: spec has ${spec!.path}, tool uses ${ref.path}`,
      ).toBe(ref.path);
    },
  );

  it.each(toolsWithOps.map((t) => [t.name, t] as const))(
    "tool %s path template placeholders are spec path params",
    (_name, tool) => {
      const ref = tool.operation!;
      const placeholders = [...ref.path.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]!);
      const spec = byId.get(ref.operationId as string)!;
      for (const ph of placeholders) {
        expect(
          spec.pathParams,
          `${ref.operationId}: placeholder {${ph}} not a documented path param`,
        ).toContain(ph);
      }
    },
  );

  it("tool registry has no duplicates and a sane surface size", () => {
    // defineTool() throws on duplicate names; this guards the overall surface.
    const names = allTools().map((t) => t.name);
    expect(new Set(names).size).toBe(names.length);
    expect(names.length).toBeGreaterThan(50);
  });

  it.each(allEntityResources().map((r) => [r.uriTemplate, r] as const))(
    "resource %s maps to an existing spec operation",
    (_uri, res) => {
      const spec = byId.get(res.operation.operationId as string);
      expect(spec, `operationId '${res.operation.operationId}' not found`).toBeDefined();
      expect(spec!.method).toBe("GET");
      expect(spec!.path).toBe(res.operation.path);
      expect(res.uriTemplate).toMatch(/^opbx:\/\/[a-z-]+\/\{id\}$/);
    },
  );
});
