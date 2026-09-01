# MCP Prompts Reference

Four reusable prompts guide agents through the most common workflows. They reference
real tool names and the confirmation model, and contain no credentials.

## `configure_pbx`

**Args:** `goal?` — short description of the desired setup.

Walks the agent through tenant bootstrap: discover existing state → users → extensions →
ring groups/IVR → business hours → DID routing via `configure_phone_number_routing` →
final `validate_configuration` pass.

## `build_inbound_call_flow`

**Args:** `phone_number?` (E.164), `requirements?`

Inbound routing design: inspect current DID routing, build IVR/ring-group/business-hours
pieces in dependency order, apply routing, validate. Notes the full-replacement update
semantics of ring groups / IVR / business hours.

## `create_outbound_campaign`

**Args:** `name?`, `purpose?`

Campaign setup with safety rails: choose routing destination, caller-ID pool, create
campaign (draft), build the distribution list, and — critically — warns that
`assign_distribution_list` **activates a draft campaign immediately** (upstream side
effect), so assignment is the go-live step. Lifecycle management via the
confirmation-gated `start/pause/resume/archive_campaign` tools.

## `diagnose_call_problem`

**Args:** `symptom?`, `number?`

Structured troubleshooting: CDR search → blacklist/blocked-call checks → DID routing →
`validate_configuration` → live calls → campaign/list/whitelist checks for outbound.
Ends with evidence-based findings and minimal-fix proposals.
