# OpenAPI Drift Detection

The MCP server must fail loudly when OPBX changes in ways that invalidate the adapter.

## Mechanisms

### 1. Compile-time (strongest)

`src/opbx/generated/schema.d.ts` is generated from the real spec
(`npm run generate:opbx-client`). All operationIds and path templates used by tools
are TypeScript-referenced — if OPBX removes or renames an operation, `npm run typecheck`
and `npm run build` fail. Regenerate the client when the spec changes and let the
compiler point at the breakage.

### 2. Contract tests (`npm run validate:opbx-api`)

`tests/contract/spec-manifest.test.ts` parses the modular spec
(`../docs/opbx-openapi/paths/**/*.yaml`) at test time and asserts, for **every**
registered tool and resource:

- the referenced `operationId` exists in the spec,
- the HTTP method matches,
- the path matches exactly (detects moved/renamed endpoints),
- every `{placeholder}` in the path template is a documented path parameter,
- only the two intended composite tools lack an operation reference,
- tool registry has no duplicates.

These run in CI on every change to `mcp-server/**` **or** `docs/opbx-openapi/**`.

### 3. Spec self-checks discovered during development

The spec itself has known inconsistencies (all documented in
[opbx-api-inventory.md](opbx-api-inventory.md), 22 entries): 4 duplicate path
definitions, stale enums (ring-group strategies/fallbacks), a missing CDR-recording
endpoint, response-schema-as-request-body for ring-group updates, and more. Where spec
and source disagree, **the FormRequests/policies win** and the discrepancy is listed in
the inventory — MCP never silently works around a disagreement.

### What is not (yet) covered

- Request/response **body schema** drift beyond compile-time types (the typed client
  covers what we serialize; deep per-field contract assertions are future work).
- Enum value additions/removals are caught at type level only where the typed client
  models them.
