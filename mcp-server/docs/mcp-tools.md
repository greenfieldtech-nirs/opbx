# MCP Tools Reference

> AUTO-GENERATED from the tool registry by `npm run generate:docs`. 107 tools.
> Do not edit by hand. Output shape for all tools: JSON structuredContent
> (success payload, or `{success:false, error:{...}}`, or `{confirmation_required:true, preview:{...}}`).

## `add_distribution_list_destinations`

**Add destinations to distribution list** — Add up to 1000 destinations (phone_number + optional name) to a distribution list in one call. The response reports how many were added/skipped; check get_distribution_list_validation_errors for rejected rows.

**Permission:** `distribution_lists.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** bulk

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/auto-dialer-campaigns/lists/{list}/destinations/batch` (`addListDestinationsBatch`)

| Field | Type | Required | Description |
|---|---|---|---|
| `list_id` | integer | yes | Distribution list ID |
| `destinations` | array | yes |  |

## `add_outbound_whitelist_rule`

**Add outbound whitelist rule** — Allow outbound calls to a destination country (+ optional prefix) via a specific Cloudonix trunk, with an optional default caller ID. When any active rule exists, destinations not matching any rule are rejected.

**Permission:** `outbound_whitelist.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/outbound-whitelist` (`createOutboundWhitelistEntry`)

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes |  |
| `destination_country` | string | yes | Country name (unique per organization), e.g. 'United States' |
| `destination_prefix` | any | no | Optional prefix within the country, e.g. +1555 |
| `outbound_trunk_name` | string | yes | Cloudonix outbound trunk name |
| `default_caller_id_did_id` | any | no | Default caller ID DID for calls via this rule |

## `archive_campaign`

**Archive campaign** — Archive a campaign (owner-only). An active campaign is paused first. Archived campaigns cannot be restarted. Note: OPBX enforces state preconditions at the policy level — a 403 'This action is unauthorized' from a properly-roled caller means the campaign is not in a state that allows this transition.

**Permission:** `campaigns.archive` | **Roles:** owner | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** campaign

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `PATCH /v1/auto-dialer-campaigns/{campaign}/archive` (`archiveAutoDialerCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Campaign ID |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `archive_distribution_list`

**Archive distribution list** — Archive a distribution list, retiring it from use. Prefer archiving over deleting for lists with dial history.

**Permission:** `distribution_lists.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `PATCH /v1/auto-dialer-campaigns/lists/{list}/archive` (`archiveDistributionList`)

| Field | Type | Required | Description |
|---|---|---|---|
| `list_id` | integer | yes | Distribution list ID |

## `assign_distribution_list`

**Assign distribution list to campaign** — Assign a distribution list to a campaign. CRITICAL UPSTREAM SIDE EFFECT: assigning a ready list to a DRAFT campaign immediately sets the campaign ACTIVE (bypassing the normal start flow — no started_at, no concurrency-counter reset) and dialing begins when the dialer worker runs. The list must have status 'ready'. If you only want to prepare the campaign, do NOT assign yet.

**Permission:** `distribution_lists.update` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** campaign

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `POST /v1/auto-dialer-campaigns/lists/{list}/assign` (`assignListToCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `list_id` | integer | yes | Distribution list ID |
| `campaign_id` | integer | yes | Campaign ID |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `block_inbound_number`

**Block inbound caller** — Add an inbound blacklist rule blocking a caller pattern. match_type: exact, prefix (e.g. +1555*), or wildcard. rejection_strategy: drop (silently drop), reject (busy signal), or torment (keep the caller on the line). Scope to specific DIDs via did_number_ids or set is_global=true for all numbers.

**Permission:** `inbound_blacklist.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/inbound-blacklist` (`createInboundBlacklistEntry`)

| Field | Type | Required | Description |
|---|---|---|---|
| `caller_id_pattern` | string | yes | Number or pattern: digits, +, *, ? only |
| `match_type` | enum(exact\|prefix\|wildcard) | yes |  |
| `rejection_strategy` | enum(drop\|reject\|torment) | yes |  |
| `is_global` | boolean | no | Apply to all DIDs (default: false) |
| `did_number_ids` | array | no | DID IDs to scope the rule to (required when not global) |

## `configure_phone_number_routing`

**Configure phone number routing** — Point a phone number (DID) at a destination in one validated step: the target is checked to exist and be active (ring groups must have active members) before the routing is applied. For business-hours routing with different open/closed behavior, route to a business_hours schedule (create/configure it with the business-hours tools first). Takes effect on live call routing immediately.

**Permission:** `phone_numbers.route` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `PUT /v1/phone-numbers/{phone_number}` (`updatePhoneNumber`)

| Field | Type | Required | Description |
|---|---|---|---|
| `phone_number_id` | integer | yes | Phone number (DID) ID |
| `destination_type` | enum(extension\|ring_group\|business_hours\|conference_room\|ai_assistant\|ai_load_balancer\|ivr_menu) | yes |  |
| `destination_id` | integer | yes | ID of the destination resource |

## `copy_distribution_list`

**Copy distribution list** — Create a copy of a distribution list including its destinations.

**Permission:** `distribution_lists.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/auto-dialer-campaigns/lists/{list}/copy` (`copyDistributionList`)

| Field | Type | Required | Description |
|---|---|---|---|
| `list_id` | integer | yes | Distribution list ID to copy |

## `create_ai_assistant`

**Create AI assistant** — Create an AI assistant on a registered provider with provider-specific configuration. Discover providers with list_ai_providers and their configuration schema with get_ai_provider before creating.

**Permission:** `ai_assistants.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/ai-assistants` (`createAiAssistant`)

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Unique name within the organization |
| `description` | any | no |  |
| `status` | enum(active\|inactive) | no |  |
| `provider` | string | yes | Provider identifier (see list_ai_providers) |
| `configuration` | object | yes | Provider-specific configuration object (schema depends on provider; see get_ai_provider). Credentials go here and are stored by OPBX — they are never returned by reads. |

## `create_business_hours`

**Create business hours schedule** — Create a business-hours schedule with weekly time ranges, open/closed routing actions, and optional date exceptions. Action targets use prefixed IDs (ext-13, rg-5, conf-1, ivr-1). Known upstream quirk: exception details are partially dropped on duplicate (OPBX bug).

**Permission:** `business_hours.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/business-hours` (`createBusinessHoursSchedule`)

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Unique name within the organization |
| `status` | enum(active\|inactive) | yes |  |
| `timezone` | string | yes | IANA timezone, e.g. America/New_York |
| `open_hours_action` | object | yes | Routing during open hours |
| `closed_hours_action` | object | yes | Routing outside open hours (the fallback) |
| `schedule` | object | yes | Weekly schedule; all 7 days required |
| `exceptions` | array | no | Date-based exceptions (holidays, special hours) |

## `create_campaign`

**Create auto-dialer campaign** — Create an outbound auto-dialer campaign in draft status. Assign a distribution list (assign_distribution_list) and verify readiness (get_distribution_list must be 'ready') before starting it with start_campaign. The routing destination (AI assistant or load balancer) receives answered calls; use 'hangup' for survey/notification-only campaigns.

**Permission:** `campaigns.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** campaign

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/auto-dialer-campaigns` (`createAutoDialerCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes |  |
| `description` | any | no |  |
| `routing_destination_type` | enum(ai_assistant\|ai_load_balancer\|hangup) | yes | Where answered calls go |
| `routing_destination_id` | any | no | Required unless routing_destination_type=hangup. NOTE: OPBX does not re-validate the destination's status at campaign start. |
| `dial_timeout` | integer | yes | Seconds to wait for answer (1-300) |
| `destination_connect` | enum(connected\|immediately) | yes | Connect destination on answer (connected) or at dial (immediately) |
| `caller_id` | string | yes | Primary caller ID (E.164) |
| `max_dial_attempts` | integer | yes |  |
| `concurrent_active_calls` | integer | yes | Max simultaneous calls (1-50) |
| `calls_per_second` | integer | no |  |
| `schedule` | object | yes | Weekly dial windows; all 7 days required |
| `start_date` | string | yes | YYYY-MM-DD, today or later |
| `end_date` | string | yes | YYYY-MM-DD, on/after start_date |
| `timezone` | string | yes | IANA timezone for the schedule |
| `days_active` | array | no | Legacy day filter (schedule takes precedence) |
| `start_time` | any | no | Legacy hour window start |
| `end_time` | any | no | Legacy hour window end |
| `time_limit` | any | no | Max call duration in seconds |
| `record_calls` | boolean | no |  |
| `action_voicemail` | any | no |  |
| `action_human` | any | no |  |
| `action_unknown` | any | no |  |
| `retry_on_voicemail` | boolean | no |  |
| `auto_start` | boolean | no | Start the campaign immediately after creation (use with care) |
| `caller_id_pool` | array | no | Caller-ID rotation pool (DID IDs from list_available_caller_ids) |
| `caller_id_strategy` | enum(round_robin\|random\|least_recently_used) | no |  |

## `create_conference_room`

**Create conference room** — Create a conference room with optional PIN protection and recording. PINs are digits only (max 20).

**Permission:** `conference_rooms.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/conference-rooms` (`createConferenceRoom`)

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Unique name within the organization |
| `description` | any | no |  |
| `max_participants` | integer | yes |  |
| `status` | enum(active\|inactive) | yes |  |
| `pin` | any | no | Participant PIN (digits only) |
| `pin_required` | boolean | no |  |
| `host_pin` | any | no | Host PIN (digits only) |
| `recording_enabled` | boolean | no |  |

## `create_distribution_list`

**Create distribution list** — Create an empty distribution list (destination pool). Add destinations with add_distribution_list_destinations, then assign it to a campaign.

**Permission:** `distribution_lists.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/auto-dialer-campaigns/lists` (`createDistributionList`)

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes |  |
| `description` | any | no |  |

## `create_extension`

**Create extension** — Create a new extension in the organization. For type=user this also provisions a Cloudonix subscriber. Choose the extension number carefully (unique, 3-5 digits). Do not use this to modify an existing extension — use update_extension.

**Permission:** `extensions.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/extensions` (`createExtension`)

| Field | Type | Required | Description |
|---|---|---|---|
| `extension_number` | string | yes | Extension number, 3-5 digits, unique in the organization |
| `type` | enum(user\|conference\|ring_group\|ivr\|ai_assistant\|custom_logic\|forward\|ai_load_balancer) | yes | Extension type. Conditional configuration key required per type: user (user_id), conference (conference_room_id), ring_group (ring_group_id), ivr (ivr_id), ai_assistant (ai_assistant_id), ai_load_balancer (ai_load_balancer_id), custom_logic (container_application_name + container_block_name), forward (forward_to). |
| `status` | enum(active\|inactive) | yes |  |
| `user_id` | any | no | Assigned user ID (required for type=user) |
| `voicemail_enabled` | boolean | no | Enable voicemail (default: true) |
| `default_caller_id_did_id` | any | no | Default outbound caller ID (must be an active DID of the organization) |
| `configuration` | object | no | Type-specific configuration (see type field) |

## `create_ivr_menu`

**Create IVR menu** — Create an IVR (interactive voice response) menu with a prompt and 1-20 keypad options. Provide exactly one prompt source: tts_text (+tts_voice), recording_id, or audio_file_path. Options target existing extensions, ring groups, conference rooms, IVR menus, AI assistants, AI load balancers, or business-hours schedules.

**Permission:** `ivr.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/ivr-menus` (`createIVRMenu`)

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes |  |
| `description` | any | no |  |
| `recording_id` | any | no | Existing recording ID for the prompt (mutually exclusive with tts_text) |
| `audio_file_path` | any | no | Audio URL or recording ID for the prompt |
| `tts_text` | any | no | TTS prompt text (mutually exclusive with recording_id) |
| `tts_voice` | any | no | TTS voice ID (see list_ivr_voices) |
| `max_timeout` | integer | yes | Seconds waiting for input (1-30) |
| `inter_digit_timeout` | integer | yes | Seconds between digits (1-30) |
| `max_turns` | integer | yes | Prompt replays before failover (1-9) |
| `failover_destination_type` | enum(extension\|ring_group\|conference_room\|ivr_menu\|ai_assistant\|ai_load_balancer\|business_hours\|hangup) | yes | Where the call goes after max_turns without valid input |
| `failover_destination_id` | any | no | Target ID for failover (omit only when failover_destination_type=hangup) |
| `status` | enum(active\|inactive) | yes |  |
| `options` | array | yes | Keypad options (1-20) |

## `create_phone_number`

**Register phone number (DID)** — Register a phone number (DID) in OPBX with its inbound routing. This creates the local record — the number must already exist on the Cloudonix side. Phone numbers are E.164 (+15551234567) unless enable_non_e164 is set. routing_config must contain exactly the key matching routing_type (e.g. routing_type=ring_group -> routing_config={ring_group_id: N}). Targets must be active; ring groups need at least one active member.

**Permission:** `phone_numbers.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/phone-numbers` (`createPhoneNumber`)

| Field | Type | Required | Description |
|---|---|---|---|
| `phone_number` | string | yes | E.164 number, e.g. +15551234567 |
| `friendly_name` | any | no |  |
| `routing_type` | enum(extension\|ring_group\|business_hours\|conference_room\|ai_assistant\|ai_load_balancer\|ivr_menu) | yes |  |
| `routing_config` | object | yes |  |
| `status` | enum(active\|inactive) | yes |  |
| `enable_non_e164` | boolean | no | Allow non-E.164 formats (digits, +, # only) |

## `create_ring_group`

**Create ring group** — Create a ring group with a ring strategy, timeout, fallback action, and 1-50 members (extension IDs with priorities). All members must reference existing extensions. Do not use for updates — use update_ring_group.

**Permission:** `ring_groups.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/ring-groups` (`createRingGroup`)

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes | Unique name within the organization |
| `description` | any | no |  |
| `strategy` | enum(simultaneous\|round_robin\|sequential) | yes | Ring strategy (validated against RingGroupStrategy enum) |
| `timeout` | integer | yes | Ring timeout in seconds (5-300) |
| `ring_turns` | integer | yes | Ring cycles before fallback (1-9) |
| `fallback_action` | enum(extension\|ring_group\|ivr_menu\|ai_assistant\|ai_load_balancer\|hangup) | yes | Action when no member answers; the matching fallback_*_id is then required |
| `fallback_extension_id` | any | no | Required when fallback_action=extension |
| `fallback_ring_group_id` | any | no | Required when fallback_action=ring_group |
| `fallback_ivr_menu_id` | any | no | Required when fallback_action=ivr_menu |
| `fallback_ai_assistant_id` | any | no | Required when fallback_action=ai_assistant |
| `fallback_ai_load_balancer_id` | any | no | Required when fallback_action=ai_load_balancer |
| `status` | enum(active\|inactive) | yes |  |
| `members` | array | yes | Ring group members (1-50), each with an extension ID and priority |

## `create_user`

**Create user** — Create a user directly with a password (min 8 chars, mixed case + numbers). Use invite_user instead when the person can set their own password.

**Permission:** `users.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/users` (`createUser`)

| Field | Type | Required | Description |
|---|---|---|---|
| `name` | string | yes |  |
| `email` | string | yes |  |
| `password` | string | yes | Min 8 chars, upper+lower case and numbers (OPBX-enforced) |
| `role` | enum(owner\|pbx_admin\|pbx_user\|reporter\|supervisor) | yes | OPBX role. Assigning/changing 'owner' additionally requires the owner role upstream. |
| `status` | enum(active\|inactive) | no |  |
| `phone` | any | no |  |

## `delete_ai_assistant`

**Delete AI assistant** — Soft-delete an AI assistant. OPBX blocks deletion while extensions reference it (422 AI_ASSISTANT_IN_USE), but does NOT check DID/IVR/campaign references — run validate_configuration first and re-route those before deleting.

**Permission:** `ai_assistants.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/ai-assistants/{ai_assistant}` (`deleteAiAssistant`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the ai_assistant to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `delete_business_hours`

**Delete business hours schedule** — Soft-delete a business-hours schedule. DIDs routed through it will fail their business-hours evaluation — re-route them first. In-use guard reports as 500 (mapped to resource_in_use by MCP).

**Permission:** `business_hours.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/business-hours/{business_hour}` (`deleteBusinessHoursSchedule`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the business_hours to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `delete_campaign`

**Delete campaign** — Delete an auto-dialer campaign. Owner-only and only while in draft status — archive active/paused campaigns instead (archive_campaign). Call sessions are cascade-deleted; CDRs are kept (campaign reference nulled).

**Permission:** `campaigns.delete` | **Roles:** owner | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/auto-dialer-campaigns/{campaign}` (`deleteAutoDialerCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the campaign to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `delete_conference_room`

**Delete conference room** — Delete a conference room. In-use guard reports as 500 (mapped to resource_in_use by MCP).

**Permission:** `conference_rooms.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/conference-rooms/{conference_room}` (`deleteConferenceRoom`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the conference_room to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `delete_distribution_list`

**Delete distribution list** — Delete a distribution list and all its destinations. Owner-only for normal lists (PBX admins may delete failed lists). Prefer archive_distribution_list for lists with dial history.

**Permission:** `distribution_lists.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/auto-dialer-campaigns/lists/{list}` (`deleteDistributionList`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the distribution_list to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `delete_extension`

**Delete extension** — Permanently delete an extension. For user-type extensions the Cloudonix subscriber is deleted too (OPBX continues even if that call fails). WARNING: OPBX does not check references — DIDs or IVR options routed to this extension will dangle. Check with validate_configuration after deleting.

**Permission:** `extensions.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/extensions/{extension}` (`deleteExtension`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the extension to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `delete_ivr_menu`

**Delete IVR menu** — Delete an IVR menu. OPBX returns a proper 409 with the referencing resources (ivr_menus, failover_menus, phone_numbers) when the menu is still in use.

**Permission:** `ivr.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/ivr-menus/{ivrMenu}` (`deleteIVRMenu`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the ivr_menu to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `delete_phone_number`

**Delete phone number (DID)** — Delete a phone number record. This is a local record deletion only — the number is NOT released on Cloudonix. Inbound routing for this number stops immediately.

**Permission:** `phone_numbers.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/phone-numbers/{phone_number}` (`deletePhoneNumber`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the phone_number to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `delete_ring_group`

**Delete ring group** — Delete a ring group. OPBX blocks deletion when the group is still referenced, but returns HTTP 500 (code DELETE_ERROR) instead of 409 — MCP normalizes this to resource_in_use. Remove referencing DIDs/IVR options first.

**Permission:** `ring_groups.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/ring-groups/{ring_group}` (`deleteRingGroup`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the ring_group to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `delete_user`

**Delete user** — Delete a user from the organization. OPBX blocks deleting the last owner (409 LAST_OWNER_DELETE_BLOCKED). The user's extension assignments are affected — check extensions referencing this user first.

**Permission:** `users.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/users/{user}` (`deleteUser`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the user to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `disconnect_call`

**Disconnect active call** — Terminate an in-progress call immediately (owner-only; executed via the Cloudonix API). The session must still be active — completed calls return 404. Review the preview (parties, direction, current state) before confirming.

**Permission:** `live_calls.disconnect` | **Roles:** owner | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** live_call

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/session-updates/{sessionId}/disconnect` (`disconnectSession`)

| Field | Type | Required | Description |
|---|---|---|---|
| `session_id` | integer | yes | Numeric session ID of the active call (from list_active_calls) |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `duplicate_business_hours`

**Duplicate business hours schedule** — Create an inactive copy of a business-hours schedule ('<name> (Copy)') including days, time ranges, and exceptions.

**Permission:** `business_hours.create` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/business-hours/{businessHour}/duplicate` (`duplicateBusinessHours`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Business hours schedule ID to copy |

## `get_active_call`

**Get active call** — Get the event history and current state of one in-progress call by its session ID.

**Permission:** `live_calls.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/session-updates/{sessionId}` (`getSessionDetails`)

| Field | Type | Required | Description |
|---|---|---|---|
| `session_id` | integer | yes | Numeric session ID of the active call (from list_active_calls) |

## `get_active_call_statistics`

**Get active call statistics** — Get aggregate counts of currently active calls by status/direction.

**Permission:** `live_calls.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/session-updates/active/stats` (`getActiveSessionStats`)

_No arguments._

## `get_ai_assistant`

**Get AI assistant** — Get a single AI assistant by ID, including provider, protocol, model/voice settings, and status. Provider credentials are managed by OPBX and are never returned.

**Permission:** `ai_assistants.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ai-assistants/{ai_assistant}` (`getAiAssistant`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the ai_assistant |

## `get_ai_load_balancer`

**Get AI load balancer** — Get an AI load balancer by ID, including its strategy, weights/priorities, and member assistants.

**Permission:** `ai_load_balancers.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}` (`getAiLoadBalancer`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the ai_load_balancer |

## `get_ai_provider`

**Get AI provider** — Get a single AI provider by its identifier, including configuration schema and capabilities.

**Permission:** `ai_assistants.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ai-assistant/providers/{provider}` (`getAiAssistantProvider`)

| Field | Type | Required | Description |
|---|---|---|---|
| `provider` | string | yes | Provider identifier (see list_ai_providers) |

## `get_business_hours`

**Get business hours schedule** — Get a business-hours schedule by ID: weekly day schedules with time ranges, date-based exceptions, timezone, and the open-hours/closed-hours routing actions.

**Permission:** `business_hours.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/business-hours/{business_hour}` (`getBusinessHoursSchedule`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the business_hours |

## `get_call_details`

**Get call details** — Get a single call detail record by ID: parties, timestamps, duration, disposition, cost, and QoS metrics. Call audio, when available, is accessible through the OPBX UI.

**Permission:** `calls.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/call-detail-records/{call_detail_record}` (`getCallDetailRecord`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the call |

## `get_call_statistics`

**Get call statistics** — Get aggregate call statistics for the organization (volumes, dispositions, durations). Supervisor-scoped for supervisor identities.

**Permission:** `calls.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/call-detail-records/statistics` (`getCdrStatistics`)

_No arguments._

## `get_call_tracking_analytics`

**Get call tracking analytics** — Get call-tracking attribution analytics for a date range, optionally grouped by day/week/month and filtered by campaigns, sources, or mediums.

**Permission:** `call_tracking.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/call-tracking-analytics` (`getCallTrackingAnalytics`)

| Field | Type | Required | Description |
|---|---|---|---|
| `start_date` | string | yes | Start date (YYYY-MM-DD) |
| `end_date` | string | yes | End date (YYYY-MM-DD) |
| `campaign_ids` | array | no |  |
| `sources` | array | no |  |
| `mediums` | array | no |  |
| `group_by` | enum(day\|week\|month) | no |  |

## `get_call_tracking_campaign`

**Get call tracking campaign** — Get a call-tracking campaign by ID, including its DNI pool configuration.

**Permission:** `call_tracking.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/call-tracking-campaigns/{call_tracking_campaign}` (`getCallTrackingCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the call_tracking_campaign |

## `get_campaign`

**Get campaign** — Get a campaign by ID: routing destination (AI assistant/load balancer/hangup), caller-ID pool, concurrency/CPS limits, schedule, and assigned distribution list.

**Permission:** `campaigns.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/{campaign}` (`getAutoDialerCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the campaign |

## `get_campaign_caller_id_stats`

**Get campaign caller-ID statistics** — Get per-caller-ID performance statistics for a campaign's caller-ID pool.

**Permission:** `campaigns.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/{campaign}/caller-id-stats` (`getCampaignCallerIdStats`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Campaign ID |

## `get_campaign_list`

**Get campaign distribution list** — Get the distribution list currently assigned to a campaign (if any).

**Permission:** `campaigns.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/{campaign}/list` (`getCampaignList`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Campaign ID |

## `get_campaign_status`

**Get campaign live status** — Get live progress for one campaign: totals, per-destination states, concurrency snapshot, and monitor counters. Use before starting/pausing to understand impact.

**Permission:** `campaigns.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/{campaign}/monitor/detail` (`getMonitorDetail`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Campaign ID |

## `get_campaigns_monitor_summary`

**Get campaigns monitor summary** — Get an org-wide summary of all auto-dialer campaign activity.

**Permission:** `campaigns.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/monitor/summary` (`getMonitorSummary`)

_No arguments._

## `get_conference_room`

**Get conference room** — Get a conference room by ID, including PIN settings, capacity, and status.

**Permission:** `conference_rooms.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/conference-rooms/{conference_room}` (`getConferenceRoom`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the conference_room |

## `get_distribution_list`

**Get distribution list** — Get a distribution list by ID: status, destination counts, assigned campaign, and processing metadata.

**Permission:** `distribution_lists.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/lists/{list}` (`getDistributionList`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the distribution_list |

## `get_distribution_list_validation_errors`

**Get distribution list validation errors** — Get the per-row validation errors of a distribution list that failed processing. Use this to explain to the user why destinations were rejected before fixing and re-adding them.

**Permission:** `distribution_lists.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/lists/{list}/validation-errors` (`getListValidationErrors`)

| Field | Type | Required | Description |
|---|---|---|---|
| `list_id` | integer | yes | Distribution list ID |

## `get_extension`

**Get extension** — Get full details of a single extension by ID, including type, status, assigned user, AI assistant, voicemail and routing configuration. Never returns the SIP password (OPBX never exposes it through this endpoint).

**Permission:** `extensions.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/extensions/{extension}` (`getExtension`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the extension |

## `get_inbound_blacklist_entry`

**Get inbound blacklist entry** — Get a single inbound blacklist rule by ID, including match type and status.

**Permission:** `inbound_blacklist.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/inbound-blacklist/{inbound_blacklist}` (`getInboundBlacklistEntry`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the blacklist_entry |

## `get_inbound_blacklist_statistics`

**Get inbound blacklist statistics** — Get aggregate statistics on blacklist rules and blocked call volume.

**Permission:** `inbound_blacklist.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/inbound-blacklist/statistics` (`getBlacklistStatistics`)

_No arguments._

## `get_ivr_menu`

**Get IVR menu** — Get a single IVR menu by ID: prompt (TTS text/voice or recording), timeout, and all keypad options with their destination types and targets.

**Permission:** `ivr.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ivr-menus/{ivrMenu}` (`getIVRMenu`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the ivr_menu |

## `get_organization`

**Get current organization** — Get the OPBX organization and user context of the authenticated identity, including organization id, name, status, timezone, and the caller's role. Use this to verify connectivity and to learn the tenant context before calling other tools. Do not use it to change any configuration.

**Permission:** `organization.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** composite (multiple reads; see implementation)

_No arguments._

## `get_outbound_whitelist_entry`

**Get outbound whitelist entry** — Get a single outbound whitelist rule by ID, including match pattern and status.

**Permission:** `outbound_whitelist.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/outbound-whitelist/{outbound_whitelist}` (`getOutboundWhitelistEntry`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the whitelist_entry |

## `get_phone_number`

**Get phone number** — Get a single phone number (DID) by ID, including its routing_type and routing_config (extension, ring group, business hours, IVR menu, conference room, AI assistant, or AI load balancer target).

**Permission:** `phone_numbers.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/phone-numbers/{phone_number}` (`getPhoneNumber`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the phone_number |

## `get_recording_metadata`

**Get recording metadata** — Get metadata for a single recording (name, duration, format, size). Audio download URLs are intentionally not exposed through MCP.

**Permission:** `recordings.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/recordings/{recording}` (`getRecording`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the recording |

## `get_ring_group`

**Get ring group** — Get a ring group by ID, including its ring strategy (simultaneous/round-robin), members, timeouts, and fallback action.

**Permission:** `ring_groups.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ring-groups/{ring_group}` (`getRingGroup`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the ring_group |

## `get_supervisor_assignments`

**Get supervisor assignments** — Get which resources (extensions/ring groups) a supervisor user is assigned to monitor.

**Permission:** `supervisors.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/supervisors/{user}/assignments` (`getSupervisorAssignments`)

| Field | Type | Required | Description |
|---|---|---|---|
| `user_id` | integer | yes | ID of the supervisor user |

## `get_supervisor_dashboard`

**Get supervisor dashboard** — Get the supervisor dashboard aggregate: live call counts and team activity for the caller's supervisor scope.

**Permission:** `supervisors.read` | **Roles:** owner, pbx_admin, supervisor | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/dashboard/supervisor` (`getSupervisorDashboard`)

_No arguments._

## `get_user`

**Get user** — Get a user by ID: name, email, role, status, and linked extension. Never contains credentials.

**Permission:** `users.read` | **Roles:** owner, pbx_admin, supervisor | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/users/{user}` (`getUser`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the user |

## `invite_user`

**Invite user** — Send an email invitation to join the organization. The invitee sets their own password via the invitation link. Prefer this over create_user for real people.

**Permission:** `users.invite` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/users/invite` (`inviteUser`)

| Field | Type | Required | Description |
|---|---|---|---|
| `email` | string | yes |  |

## `list_active_calls`

**List active calls** — List calls currently in progress in the organization (up to 100), with optional status/direction filters. Supervisors pass supervisor=true to see their scope. Use get_active_call for the event history of one call.

**Permission:** `live_calls.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/session-updates/active` (`getActiveSessions`)

| Field | Type | Required | Description |
|---|---|---|---|
| `status` | enum(processing\|ringing\|connected) | no | Filter by live call state |
| `direction` | enum(incoming\|outgoing) | no | Filter by direction |
| `supervisor` | boolean | no | Scope to the caller's supervisor-assigned resources (supervisors only) |

## `list_ai_assistants`

**List AI assistants** — List AI assistants in the organization, filterable by status, protocol, or provider. Use get_ai_assistant for full configuration. Provider secrets are never included.

**Permission:** `ai_assistants.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ai-assistants` (`listAiAssistants`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `sort_by` | enum(name\|provider\|protocol\|status\|created_at\|updated_at) | no | Sort field (default: name) |
| `sort_order` | enum(asc\|desc) | no | Sort direction |
| `status` | enum(active\|inactive) | no | Filter by status |
| `protocol` | enum(sip\|websocket) | no | Filter by protocol |
| `provider` | string | no | Filter by provider identifier |
| `search` | string | no | Search assistant name |

## `list_ai_load_balancers`

**List AI load balancers** — List AI assistant load balancers (pools of assistants with a distribution strategy), filterable by strategy or status.

**Permission:** `ai_load_balancers.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ai-assistant-load-balancers` (`listAiLoadBalancers`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `sort_by` | enum(name\|strategy\|status\|created_at\|updated_at) | no |  |
| `sort_order` | enum(asc\|desc) | no | Sort direction |
| `strategy` | enum(round_robin\|priority\|weighted\|least_connections) | no | Filter by distribution strategy |
| `status` | enum(active\|inactive) | no | Filter by status |
| `search` | string | no | Search by name |

## `list_ai_providers`

**List AI providers** — List the AI assistant providers registered in OPBX, with their protocols (sip/websocket) and capabilities. Filter by protocol to see compatible providers. Use these provider IDs when creating AI assistants.

**Permission:** `ai_assistants.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ai-assistant/providers` (`listAiAssistantProviders`)

_No arguments._

## `list_available_caller_ids`

**List available caller IDs** — List phone numbers (DIDs) eligible for use in campaign caller-ID pools. Optionally exclude a campaign's existing pool.

**Permission:** `campaigns.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/available-caller-ids` (`getAvailableCallerIds`)

| Field | Type | Required | Description |
|---|---|---|---|
| `exclude_campaign_id` | integer | no |  |

## `list_blocked_calls`

**List blocked calls** — List calls that were rejected by inbound blacklist rules. Useful when diagnosing 'why didn't this call reach us?' complaints.

**Permission:** `inbound_blacklist.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/inbound-blacklist/blocked-logs` (`getBlockedCallLogs`)

_No arguments._

## `list_business_hours`

**List business hours schedules** — List business-hours schedules (open/closed calendars) in the organization. Use get_business_hours for days, time ranges, exceptions, and open/closed routing actions.

**Permission:** `business_hours.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/business-hours` (`listBusinessHoursSchedules`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |

## `list_call_tracking_campaigns`

**List call tracking campaigns** — List call-tracking campaigns (marketing attribution) in the organization.

**Permission:** `call_tracking.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/call-tracking-campaigns` (`listCallTrackingCampaigns`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |

## `list_call_tracking_numbers`

**List call tracking numbers** — List the tracking (DNI pool) numbers of a call-tracking campaign.

**Permission:** `call_tracking.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/call-tracking-campaigns/{call_tracking_campaign}/call-tracking-numbers` (`listCallTrackingNumbers`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `campaign_id` | integer | yes | Call-tracking campaign ID |

## `list_call_tracking_sessions`

**List call tracking sessions** — List per-visitor call-tracking sessions with attribution data, filterable by campaign, source/medium, date range, and conversion state.

**Permission:** `call_tracking.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/call-tracking-sessions` (`listCallTrackingSessions`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `campaign_ids` | array | no |  |
| `sources` | array | no |  |
| `mediums` | array | no |  |
| `start_date` | string | no |  |
| `end_date` | string | no |  |
| `is_converted` | boolean | no |  |
| `search` | string | no |  |

## `list_campaign_destinations`

**List campaign destinations** — List the dialed destinations of a campaign with per-destination state (pending/dialing/completed/failed), filterable by status and search. Paginated.

**Permission:** `campaigns.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/{campaign}/destinations` (`listCampaignDestinations`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `campaign_id` | integer | yes | Campaign ID |
| `status` | enum(pending\|dialing\|completed\|failed) | no |  |
| `search` | string | no | Search phone number or name |

## `list_campaigns`

**List auto-dialer campaigns** — List outbound auto-dialer campaigns, filterable by lifecycle status (draft/active/paused/completed/archived) and search. Use get_campaign for details and get_campaign_status for live progress.

**Permission:** `campaigns.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns` (`listAutoDialerCampaigns`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `status` | enum(draft\|active\|paused\|completed\|archived) | no | Filter by campaign status |
| `search` | string | no | Search campaign name |

## `list_conference_rooms`

**List conference rooms** — List conference rooms in the organization, including status and capacity.

**Permission:** `conference_rooms.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/conference-rooms` (`listConferenceRooms`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |

## `list_distribution_list_destinations`

**List distribution list destinations** — List destinations inside a distribution list with per-destination status (pending/dialing/completed/failed). Paginated.

**Permission:** `distribution_lists.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/lists/{list}/destinations` (`listListDestinations`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `list_id` | integer | yes | Distribution list ID |
| `status` | enum(pending\|dialing\|completed\|failed) | no |  |
| `search` | string | no |  |

## `list_distribution_lists`

**List distribution lists** — List auto-dialer distribution lists (destination pools), filterable by status (draft/processing/ready/in_use/used/failed/archived) or owning campaign. A list must be 'ready' before its campaign can start.

**Permission:** `distribution_lists.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/auto-dialer-campaigns/lists` (`listDistributionLists`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `status` | enum(draft\|processing\|ready\|in_use\|used\|failed\|archived) | no |  |
| `campaign_id` | integer | no | Filter by assigned campaign |
| `search` | string | no |  |

## `list_extensions`

**List extensions** — List extensions in the authenticated organization, with optional filtering by type, status, and free-text search. Use this to discover extension IDs before calling get_extension or routing tools. Supervisors see only their assigned scope.

**Permission:** `extensions.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/extensions` (`listExtensions`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `sort_by` | enum(extension_number\|name\|type\|status\|created_at) | no | Sort field (default: extension_number) |
| `sort_order` | enum(asc\|desc) | no | Sort direction |
| `type` | enum(user\|conference\|ring_group\|ivr\|ai_assistant\|forward\|ai_load_balancer) | no | Filter by extension type |
| `status` | enum(active\|inactive) | no | Filter by status |
| `search` | string | no | Search extension number or name |

## `list_inbound_blacklist`

**List inbound blacklist** — List inbound blacklist rules (blocked caller patterns) in the organization. Blocked calls are rejected at routing time per the rule's rejection strategy.

**Permission:** `inbound_blacklist.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/inbound-blacklist` (`listInboundBlacklistEntrys`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |

## `list_ivr_menus`

**List IVR menus** — List IVR (interactive voice response) menus in the organization, including status. Use get_ivr_menu for the full option tree and prompt configuration.

**Permission:** `ivr.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ivr-menus` (`listIVRMenus`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |

## `list_ivr_voices`

**List IVR voices** — List the text-to-speech voices available for IVR menu prompts. Use these voice IDs when creating or updating IVR menus with TTS prompts.

**Permission:** `ivr.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ivr-menus/voices` (`getIvrVoices`)

_No arguments._

## `list_outbound_whitelist`

**List outbound whitelist** — List outbound whitelist rules (allowed destination patterns). When any active rule exists, outbound calls to non-matching destinations are rejected.

**Permission:** `outbound_whitelist.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/outbound-whitelist` (`listOutboundWhitelistEntrys`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |

## `list_phone_numbers`

**List phone numbers (DIDs)** — List the organization's phone numbers (DIDs) with their routing configuration. Use this to find a number's ID and current routing_type before changing routing.

**Permission:** `phone_numbers.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/phone-numbers` (`listPhoneNumbers`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |

## `list_recordings`

**List recordings (announcements)** — List announcement/IVR audio recordings in the organization. These are the prompt files usable in IVR menus — not call recordings (see search_calls for those).

**Permission:** `recordings.read` | **Roles:** owner, pbx_admin | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/recordings` (`listRecordings`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |

## `list_ring_groups`

**List ring groups** — List ring groups in the organization. Supervisors see only their assigned scope. Use get_ring_group for member lists and strategy details.

**Permission:** `ring_groups.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/ring-groups` (`listRingGroups`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |

## `list_users`

**List users** — List users in the organization with role and status. Supervisors see their assigned scope. NOTE: the OPBX role filter enum in the API predates the supervisor role; filtering by 'supervisor' may not work upstream.

**Permission:** `users.read` | **Roles:** owner, pbx_admin, supervisor | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/users` (`listUsers`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `sort_by` | enum(name\|email\|role\|status\|created_at\|updated_at) | no |  |
| `sort_order` | enum(asc\|desc) | no | Sort direction |
| `role` | enum(owner\|pbx_admin\|pbx_user\|reporter) | no | Filter by role |
| `status` | enum(active\|inactive) | no |  |
| `search` | string | no | Search name or email |

## `pause_campaign`

**Pause campaign** — Pause an active campaign. In-flight calls are marked failed/cancelled. Only active campaigns can be paused. Note: OPBX enforces state preconditions at the policy level — a 403 'This action is unauthorized' from a properly-roled caller means the campaign is not in a state that allows this transition.

**Permission:** `campaigns.pause` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** campaign

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `PATCH /v1/auto-dialer-campaigns/{campaign}/pause` (`pauseAutoDialerCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Campaign ID |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `remove_outbound_whitelist_rule`

**Remove outbound whitelist rule** — Remove an outbound whitelist rule. If it is the last active rule covering a destination, calls to that destination will stop being allowed. Review the rule in the preview before confirming.

**Permission:** `outbound_whitelist.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/outbound-whitelist/{outbound_whitelist}` (`deleteOutboundWhitelistEntry`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the whitelist_entry to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `resume_campaign`

**Resume campaign** — Resume a paused campaign (restarts outbound dialing). Only paused campaigns can be resumed. Note: OPBX enforces state preconditions at the policy level — a 403 'This action is unauthorized' from a properly-roled caller means the campaign is not in a state that allows this transition.

**Permission:** `campaigns.resume` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** campaign

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `PATCH /v1/auto-dialer-campaigns/{campaign}/resume` (`resumeAutoDialerCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Campaign ID |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `search_calls`

**Search call records (CDRs)** — Search completed call detail records with filters for caller, destination, disposition, direction, date range, and user. Returns normalized pagination. Supervisors only see their assigned scope. Use get_call_details for a single record.

**Permission:** `calls.read` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** read

**MCP annotations:** `readOnlyHint: true` `openWorldHint: true`

**OPBX operation:** `GET /v1/call-detail-records` (`listCallDetailRecords`)

| Field | Type | Required | Description |
|---|---|---|---|
| `page` | integer | yes | Page number (1-based) |
| `per_page` | integer | yes | Items per page (max 100) |
| `from` | string | no | Caller number filter |
| `to` | string | no | Destination number filter |
| `disposition` | enum(ANSWERED\|NO ANSWER\|BUSY\|FAILED\|UNKNOWN) | no | Call disposition |
| `direction` | enum(incoming\|outgoing\|internal\|application) | no | Call direction |
| `from_date` | string | no | Start date (YYYY-MM-DD) |
| `to_date` | string | no | End date (YYYY-MM-DD) |
| `user` | string | no | Filter by assigned user name |
| `extension_id` | integer | no | Filter by extension ID |
| `sort_by` | enum(session_timestamp\|from\|to\|duration\|billsec\|disposition) | no | Sort field (default: session_timestamp) |
| `sort_order` | enum(asc\|desc) | no |  |

## `set_business_hours_status`

**Enable/disable business hours schedule** — Activate or deactivate a business-hours schedule. Affects live call routing immediately for all DIDs routed through it. NOTE: OPBX does not role-gate this endpoint; MCP restricts it to owner|pbx_admin.

**Permission:** `business_hours.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PATCH /v1/business-hours/{businessHour}/toggle-status` (`toggleBusinessHoursStatus`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Business hours schedule ID |
| `status` | enum(active\|inactive) | yes |  |

## `set_inbound_blacklist_status`

**Enable/disable inbound blacklist rule** — Enable or disable a blacklist rule without deleting it.

**Permission:** `inbound_blacklist.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PATCH /v1/inbound-blacklist/{inboundBlacklist}/toggle-status` (`toggleBlacklistStatus`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Blacklist entry ID |
| `status` | enum(active\|inactive) | yes |  |

## `set_ivr_menu_status`

**Enable/disable IVR menu** — Activate or deactivate an IVR menu. Takes effect on live call routing immediately — an inactive menu causes DIDs routed to it to fail over or reject.

**Permission:** `ivr.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PATCH /v1/ivr-menus/{ivrMenu}/toggle-status` (`toggleIvrMenuStatus`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | IVR menu ID |
| `status` | enum(active\|inactive) | yes |  |

## `set_outbound_whitelist_status`

**Enable/disable outbound whitelist rule** — Enable or disable a whitelist rule without deleting it.

**Permission:** `outbound_whitelist.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PATCH /v1/outbound-whitelist/{outboundWhitelist}/toggle-status` (`toggleWhitelistStatus`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Whitelist entry ID |
| `status` | enum(active\|inactive) | yes |  |

## `start_call_coaching`

**Start call coaching (spy/whisper/barge)** — Start supervisor coaching on an active call. Modes: spy (listen only), whisper (speak to one party; requires whisper_party), barge (join the call). Returns a dial destination that the supervisor's web phone must call to attach — there is no separate stop operation; hanging up the coaching leg ends it. Owner and supervisor roles only; supervisors can only coach calls in their assigned scope.

**Permission:** `live_calls.coach` | **Roles:** owner, supervisor | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** live_call

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `POST /v1/session-updates/{sessionId}/coach-target` (`resolveCoachTarget`)

| Field | Type | Required | Description |
|---|---|---|---|
| `session_id` | integer | yes | Numeric session ID of the active call (from list_active_calls) |
| `policy` | enum(spy\|whisper\|barge) | yes | Coaching mode |
| `whisper_party` | enum(caller\|callee\|both) | no | Required when policy=whisper: which party hears the supervisor |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `start_campaign`

**Start campaign** — Start an auto-dialer campaign (begins real outbound calling). Allowed from draft or paused status with a 'ready' distribution list. Review the preview carefully before confirming. Do not use for pausing — use pause_campaign. Note: OPBX enforces state preconditions at the policy level — a 403 'This action is unauthorized' from a properly-roled caller means the campaign is not in a state that allows this transition.

**Permission:** `campaigns.start` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** campaign

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `PATCH /v1/auto-dialer-campaigns/{campaign}/start` (`startAutoDialerCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Campaign ID |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `unassign_distribution_list`

**Unassign distribution list from campaign** — Detach a distribution list from its campaign. Only possible while all destinations are pending with zero dial attempts (OPBX enforces this with a 422 otherwise).

**Permission:** `distribution_lists.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `POST /v1/auto-dialer-campaigns/lists/{list}/unassign` (`unassignListFromCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `list_id` | integer | yes | Distribution list ID |

## `unblock_inbound_number`

**Remove inbound blacklist rule** — Remove an inbound blacklist rule, immediately allowing matching callers again. Review the rule in the preview before confirming.

**Permission:** `inbound_blacklist.delete` | **Roles:** owner, pbx_admin | **Risk:** high  
**Destructive:** yes | **Idempotent:** yes | **Confirmation:** required | **Rate class:** sensitive

**MCP annotations:** `readOnlyHint: false` `destructiveHint: true` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** `DELETE /v1/inbound-blacklist/{inbound_blacklist}` (`deleteInboundBlacklistEntry`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | ID of the blacklist_entry to delete |
| `confirm` | boolean | no | Set to true to confirm execution after reviewing the preview |

## `update_ai_assistant`

**Update AI assistant** — Update an AI assistant's name, description, status, provider, or configuration. Fetch with get_ai_assistant first and send the complete desired state.

**Permission:** `ai_assistants.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PUT /v1/ai-assistants/{ai_assistant}` (`updateAiAssistant`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | AI assistant ID |
| `name` | string | yes | Unique name within the organization |
| `description` | any | no |  |
| `status` | enum(active\|inactive) | no |  |
| `provider` | string | yes | Provider identifier (see list_ai_providers) |
| `configuration` | object | yes | Provider-specific configuration object (schema depends on provider; see get_ai_provider). Credentials go here and are stored by OPBX — they are never returned by reads. |

## `update_business_hours`

**Update business hours schedule** — Update a business-hours schedule. Exceptions are deleted and recreated on every update — always send the full desired exception list. Fetch with get_business_hours first.

**Permission:** `business_hours.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PUT /v1/business-hours/{business_hour}` (`updateBusinessHoursSchedule`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Business hours schedule ID |
| `name` | string | yes | Unique name within the organization |
| `status` | enum(active\|inactive) | yes |  |
| `timezone` | string | yes | IANA timezone, e.g. America/New_York |
| `open_hours_action` | object | yes | Routing during open hours |
| `closed_hours_action` | object | yes | Routing outside open hours (the fallback) |
| `schedule` | object | yes | Weekly schedule; all 7 days required |
| `exceptions` | array | no | Date-based exceptions (holidays, special hours) |

## `update_campaign`

**Update campaign** — Update campaign settings. Only provided fields change. Restrictions: the caller-ID pool cannot be changed while the campaign is active (409), and completed/archived campaigns cannot be meaningfully updated. Fetch with get_campaign first.

**Permission:** `campaigns.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** campaign

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PUT /v1/auto-dialer-campaigns/{campaign}` (`updateAutoDialerCampaign`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Campaign ID |
| `name` | string | no |  |
| `description` | any | no |  |
| `routing_destination_type` | enum(ai_assistant\|ai_load_balancer\|hangup) | no | Where answered calls go |
| `routing_destination_id` | any | no | Required unless routing_destination_type=hangup. NOTE: OPBX does not re-validate the destination's status at campaign start. |
| `dial_timeout` | integer | no | Seconds to wait for answer (1-300) |
| `destination_connect` | enum(connected\|immediately) | no | Connect destination on answer (connected) or at dial (immediately) |
| `caller_id` | string | no | Primary caller ID (E.164) |
| `max_dial_attempts` | integer | no |  |
| `concurrent_active_calls` | integer | no | Max simultaneous calls (1-50) |
| `calls_per_second` | integer | no |  |
| `schedule` | object | no |  |
| `start_date` | string | no | YYYY-MM-DD, today or later |
| `end_date` | string | no | YYYY-MM-DD, on/after start_date |
| `timezone` | string | no | IANA timezone for the schedule |
| `time_limit` | any | no | Max call duration in seconds |
| `record_calls` | boolean | no |  |
| `action_voicemail` | any | no |  |
| `action_human` | any | no |  |
| `action_unknown` | any | no |  |
| `retry_on_voicemail` | boolean | no |  |
| `auto_start` | boolean | no | Start the campaign immediately after creation (use with care) |
| `caller_id_pool` | array | no | Caller-ID rotation pool (DID IDs from list_available_caller_ids) |
| `caller_id_strategy` | enum(round_robin\|random\|least_recently_used) | no |  |

## `update_conference_room`

**Update conference room** — Update a conference room's name, capacity, PIN settings, recording flag, or status. Fetch with get_conference_room first; send the complete desired state.

**Permission:** `conference_rooms.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PUT /v1/conference-rooms/{conference_room}` (`updateConferenceRoom`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Conference room ID |
| `name` | string | yes | Unique name within the organization |
| `description` | any | no |  |
| `max_participants` | integer | yes |  |
| `status` | enum(active\|inactive) | yes |  |
| `pin` | any | no | Participant PIN (digits only) |
| `pin_required` | boolean | no |  |
| `host_pin` | any | no | Host PIN (digits only) |
| `recording_enabled` | boolean | no |  |

## `update_extension`

**Update extension** — Update an existing extension: status, voicemail, assigned user, AI assistant, default caller ID, or type-specific configuration. Only provided fields change. Do not use this to create extensions.

**Permission:** `extensions.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PUT /v1/extensions/{extension}` (`updateExtension`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Extension ID |
| `extension_number` | string | no | Extension number, 3-5 digits, unique in the organization |
| `type` | enum(user\|conference\|ring_group\|ivr\|ai_assistant\|custom_logic\|forward\|ai_load_balancer) | no |  |
| `status` | enum(active\|inactive) | no |  |
| `user_id` | any | no | Assigned user ID (required for type=user) |
| `voicemail_enabled` | boolean | no | Enable voicemail (default: true) |
| `default_caller_id_did_id` | any | no | Default outbound caller ID (must be an active DID of the organization) |
| `configuration` | object | no | Type-specific configuration (see type field) |

## `update_ivr_menu`

**Update IVR menu** — Update an existing IVR menu (full replacement of options). Fetch with get_ivr_menu first and send the complete desired state. Provide exactly one prompt source: tts_text (+tts_voice), recording_id, or audio_file_path. Options target existing extensions, ring groups, conference rooms, IVR menus, AI assistants, AI load balancers, or business-hours schedules.

**Permission:** `ivr.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PUT /v1/ivr-menus/{ivrMenu}` (`updateIVRMenu`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | IVR menu ID |
| `name` | string | yes |  |
| `description` | any | no |  |
| `recording_id` | any | no | Existing recording ID for the prompt (mutually exclusive with tts_text) |
| `audio_file_path` | any | no | Audio URL or recording ID for the prompt |
| `tts_text` | any | no | TTS prompt text (mutually exclusive with recording_id) |
| `tts_voice` | any | no | TTS voice ID (see list_ivr_voices) |
| `max_timeout` | integer | yes | Seconds waiting for input (1-30) |
| `inter_digit_timeout` | integer | yes | Seconds between digits (1-30) |
| `max_turns` | integer | yes | Prompt replays before failover (1-9) |
| `failover_destination_type` | enum(extension\|ring_group\|conference_room\|ivr_menu\|ai_assistant\|ai_load_balancer\|business_hours\|hangup) | yes | Where the call goes after max_turns without valid input |
| `failover_destination_id` | any | no | Target ID for failover (omit only when failover_destination_type=hangup) |
| `status` | enum(active\|inactive) | yes |  |
| `options` | array | yes | Keypad options (1-20) |

## `update_phone_number`

**Update phone number / routing** — Update a phone number's friendly name, status, or inbound routing. The number itself is immutable. Changing routing_type requires the matching routing_config. Takes effect on live call routing immediately. routing_config must contain exactly the key matching routing_type (e.g. routing_type=ring_group -> routing_config={ring_group_id: N}). Targets must be active; ring groups need at least one active member.

**Permission:** `phone_numbers.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PUT /v1/phone-numbers/{phone_number}` (`updatePhoneNumber`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Phone number ID |
| `friendly_name` | any | no |  |
| `routing_type` | enum(extension\|ring_group\|business_hours\|conference_room\|ai_assistant\|ai_load_balancer\|ivr_menu) | no |  |
| `routing_config` | object | no |  |
| `status` | enum(active\|inactive) | no |  |

## `update_ring_group`

**Update ring group** — Update a ring group. OPBX applies full-replacement semantics on this endpoint: name, strategy, timeout, ring_turns, fallback_action, status and members are all required — fetch the current group with get_ring_group first and send the complete desired state.

**Permission:** `ring_groups.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PUT /v1/ring-groups/{ring_group}` (`updateRingGroup`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | Ring group ID |
| `name` | string | yes | Unique name within the organization |
| `description` | any | no |  |
| `strategy` | enum(simultaneous\|round_robin\|sequential) | yes | Ring strategy (validated against RingGroupStrategy enum) |
| `timeout` | integer | yes | Ring timeout in seconds (5-300) |
| `ring_turns` | integer | yes | Ring cycles before fallback (1-9) |
| `fallback_action` | enum(extension\|ring_group\|ivr_menu\|ai_assistant\|ai_load_balancer\|hangup) | yes | Action when no member answers; the matching fallback_*_id is then required |
| `fallback_extension_id` | any | no | Required when fallback_action=extension |
| `fallback_ring_group_id` | any | no | Required when fallback_action=ring_group |
| `fallback_ivr_menu_id` | any | no | Required when fallback_action=ivr_menu |
| `fallback_ai_assistant_id` | any | no | Required when fallback_action=ai_assistant |
| `fallback_ai_load_balancer_id` | any | no | Required when fallback_action=ai_load_balancer |
| `status` | enum(active\|inactive) | yes |  |
| `members` | array | yes | Ring group members (1-50), each with an extension ID and priority |

## `update_user`

**Update user** — Update a user's name, email, role, status, or phone. Role changes to/from 'owner' require the caller to be an owner (OPBX-enforced). Only provided fields change. Cannot change passwords — that is deliberately not exposed via MCP.

**Permission:** `users.update` | **Roles:** owner, pbx_admin | **Risk:** medium  
**Destructive:** no | **Idempotent:** no | **Confirmation:** none | **Rate class:** write

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: false` `openWorldHint: true`

**OPBX operation:** `PUT /v1/users/{user}` (`updateUser`)

| Field | Type | Required | Description |
|---|---|---|---|
| `id` | integer | yes | User ID |
| `name` | string | no |  |
| `email` | string | no |  |
| `role` | enum(owner\|pbx_admin\|pbx_user\|reporter\|supervisor) | no | OPBX role. Assigning/changing 'owner' additionally requires the owner role upstream. |
| `status` | enum(active\|inactive) | no |  |
| `phone` | any | no |  |

## `validate_configuration`

**Validate PBX configuration** — Read-only audit of the organization's PBX configuration. Checks cross-resource integrity: DID routes to missing/inactive destinations, ring groups without (active) members, IVR options/failovers pointing at missing or inactive targets, business-hours actions with broken targets, extensions referencing inactive AI assistants or users, AI load balancers without members or with inactive members, campaigns without ready lists or with invalid caller IDs, and inconsistent outbound restrictions. Returns structured findings with suggested actions. Use before and after making routing changes, and when diagnosing call problems. Makes multiple bounded reads (max 1000 items per collection).

**Permission:** `configuration.validate` | **Roles:** any authenticated org role | **Risk:** low  
**Destructive:** no | **Idempotent:** yes | **Confirmation:** none | **Rate class:** bulk

**MCP annotations:** `readOnlyHint: false` `destructiveHint: false` `idempotentHint: true` `openWorldHint: true`

**OPBX operation:** composite (multiple reads; see implementation)

_No arguments._
