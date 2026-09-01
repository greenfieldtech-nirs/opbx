# MCP Resources Reference

> AUTO-GENERATED from the resource registry. Resources are read-only JSON snapshots
> (mimeType application/json) with the same RBAC as the corresponding get_* tools.

## Static

- `opbx://organization` — The authenticated identity's organization context (id, name, role).

## Entity templates

| URI template | Entity | OPBX operation | Roles |
|---|---|---|---|
| `opbx://extensions/{id}` | OPBX extension | `GET /v1/extensions/{extension}` | any |
| `opbx://phone-numbers/{id}` | OPBX phone number (DID) | `GET /v1/phone-numbers/{phone_number}` | any |
| `opbx://ring-groups/{id}` | OPBX ring group | `GET /v1/ring-groups/{ring_group}` | any |
| `opbx://ivr-menus/{id}` | OPBX IVR menu | `GET /v1/ivr-menus/{ivrMenu}` | any |
| `opbx://business-hours/{id}` | OPBX business hours schedule | `GET /v1/business-hours/{business_hour}` | any |
| `opbx://conference-rooms/{id}` | OPBX conference room | `GET /v1/conference-rooms/{conference_room}` | any |
| `opbx://ai-assistants/{id}` | OPBX AI assistant | `GET /v1/ai-assistants/{ai_assistant}` | any |
| `opbx://ai-load-balancers/{id}` | OPBX AI load balancer | `GET /v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}` | any |
| `opbx://campaigns/{id}` | OPBX auto-dialer campaign | `GET /v1/auto-dialer-campaigns/{campaign}` | owner, pbx_admin |
| `opbx://distribution-lists/{id}` | OPBX distribution list | `GET /v1/auto-dialer-campaigns/lists/{list}` | owner, pbx_admin |
| `opbx://call-detail-records/{id}` | OPBX call detail record | `GET /v1/call-detail-records/{call_detail_record}` | any |
| `opbx://recordings/{id}` | OPBX recording (announcement) | `GET /v1/recordings/{recording}` | owner, pbx_admin |
| `opbx://users/{id}` | OPBX user | `GET /v1/users/{user}` | owner, pbx_admin, supervisor |
| `opbx://inbound-blacklist/{id}` | OPBX inbound blacklist rule | `GET /v1/inbound-blacklist/{inbound_blacklist}` | any |
| `opbx://outbound-whitelist/{id}` | OPBX outbound whitelist rule | `GET /v1/outbound-whitelist/{outbound_whitelist}` | any |
