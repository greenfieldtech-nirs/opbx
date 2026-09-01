# Functional Validation — Tool Mapping

> AUTO-GENERATED mapping section. Every MCP tool and its underlying OPBX REST
> operation (validated against the OpenAPI spec by the contract tests,
> `npm run validate:opbx-api`). All tools listed were additionally exercised
> against a live local OPBX instance during development; destructive tools
> were verified with the confirmation gate (preview without mutation,
> execution only with confirm=true).

| MCP tool | OPBX operationId | Method/Path | Permission | Risk | Confirmation |
|---|---|---|---|---|---|
| `add_distribution_list_destinations` | `addListDestinationsBatch` | `POST /v1/auto-dialer-campaigns/lists/{list}/destinations/batch` | `distribution_lists.update` | medium | none |
| `add_outbound_whitelist_rule` | `createOutboundWhitelistEntry` | `POST /v1/outbound-whitelist` | `outbound_whitelist.create` | medium | none |
| `archive_campaign` | `archiveAutoDialerCampaign` | `PATCH /v1/auto-dialer-campaigns/{campaign}/archive` | `campaigns.archive` | high | required |
| `archive_distribution_list` | `archiveDistributionList` | `PATCH /v1/auto-dialer-campaigns/lists/{list}/archive` | `distribution_lists.update` | medium | none |
| `assign_distribution_list` | `assignListToCampaign` | `POST /v1/auto-dialer-campaigns/lists/{list}/assign` | `distribution_lists.update` | high | required |
| `block_inbound_number` | `createInboundBlacklistEntry` | `POST /v1/inbound-blacklist` | `inbound_blacklist.create` | medium | none |
| `configure_phone_number_routing` | `updatePhoneNumber` | `PUT /v1/phone-numbers/{phone_number}` | `phone_numbers.route` | medium | none |
| `copy_distribution_list` | `copyDistributionList` | `POST /v1/auto-dialer-campaigns/lists/{list}/copy` | `distribution_lists.create` | medium | none |
| `create_ai_assistant` | `createAiAssistant` | `POST /v1/ai-assistants` | `ai_assistants.create` | medium | none |
| `create_business_hours` | `createBusinessHoursSchedule` | `POST /v1/business-hours` | `business_hours.create` | medium | none |
| `create_campaign` | `createAutoDialerCampaign` | `POST /v1/auto-dialer-campaigns` | `campaigns.create` | medium | none |
| `create_conference_room` | `createConferenceRoom` | `POST /v1/conference-rooms` | `conference_rooms.create` | medium | none |
| `create_distribution_list` | `createDistributionList` | `POST /v1/auto-dialer-campaigns/lists` | `distribution_lists.create` | medium | none |
| `create_extension` | `createExtension` | `POST /v1/extensions` | `extensions.create` | medium | none |
| `create_ivr_menu` | `createIVRMenu` | `POST /v1/ivr-menus` | `ivr.create` | medium | none |
| `create_phone_number` | `createPhoneNumber` | `POST /v1/phone-numbers` | `phone_numbers.create` | medium | none |
| `create_ring_group` | `createRingGroup` | `POST /v1/ring-groups` | `ring_groups.create` | medium | none |
| `create_user` | `createUser` | `POST /v1/users` | `users.create` | medium | none |
| `delete_ai_assistant` | `deleteAiAssistant` | `DELETE /v1/ai-assistants/{ai_assistant}` | `ai_assistants.delete` | high | required |
| `delete_business_hours` | `deleteBusinessHoursSchedule` | `DELETE /v1/business-hours/{business_hour}` | `business_hours.delete` | high | required |
| `delete_campaign` | `deleteAutoDialerCampaign` | `DELETE /v1/auto-dialer-campaigns/{campaign}` | `campaigns.delete` | high | required |
| `delete_conference_room` | `deleteConferenceRoom` | `DELETE /v1/conference-rooms/{conference_room}` | `conference_rooms.delete` | high | required |
| `delete_distribution_list` | `deleteDistributionList` | `DELETE /v1/auto-dialer-campaigns/lists/{list}` | `distribution_lists.delete` | high | required |
| `delete_extension` | `deleteExtension` | `DELETE /v1/extensions/{extension}` | `extensions.delete` | high | required |
| `delete_ivr_menu` | `deleteIVRMenu` | `DELETE /v1/ivr-menus/{ivrMenu}` | `ivr.delete` | high | required |
| `delete_phone_number` | `deletePhoneNumber` | `DELETE /v1/phone-numbers/{phone_number}` | `phone_numbers.delete` | high | required |
| `delete_ring_group` | `deleteRingGroup` | `DELETE /v1/ring-groups/{ring_group}` | `ring_groups.delete` | high | required |
| `delete_user` | `deleteUser` | `DELETE /v1/users/{user}` | `users.delete` | high | required |
| `disconnect_call` | `disconnectSession` | `DELETE /v1/session-updates/{sessionId}/disconnect` | `live_calls.disconnect` | high | required |
| `duplicate_business_hours` | `duplicateBusinessHours` | `POST /v1/business-hours/{businessHour}/duplicate` | `business_hours.create` | medium | none |
| `get_active_call` | `getSessionDetails` | `GET /v1/session-updates/{sessionId}` | `live_calls.read` | low | none |
| `get_active_call_statistics` | `getActiveSessionStats` | `GET /v1/session-updates/active/stats` | `live_calls.read` | low | none |
| `get_ai_assistant` | `getAiAssistant` | `GET /v1/ai-assistants/{ai_assistant}` | `ai_assistants.read` | low | none |
| `get_ai_load_balancer` | `getAiLoadBalancer` | `GET /v1/ai-assistant-load-balancers/{ai_assistant_load_balancer}` | `ai_load_balancers.read` | low | none |
| `get_ai_provider` | `getAiAssistantProvider` | `GET /v1/ai-assistant/providers/{provider}` | `ai_assistants.read` | low | none |
| `get_business_hours` | `getBusinessHoursSchedule` | `GET /v1/business-hours/{business_hour}` | `business_hours.read` | low | none |
| `get_call_details` | `getCallDetailRecord` | `GET /v1/call-detail-records/{call_detail_record}` | `calls.read` | low | none |
| `get_call_statistics` | `getCdrStatistics` | `GET /v1/call-detail-records/statistics` | `calls.read` | low | none |
| `get_call_tracking_analytics` | `getCallTrackingAnalytics` | `GET /v1/call-tracking-analytics` | `call_tracking.read` | low | none |
| `get_call_tracking_campaign` | `getCallTrackingCampaign` | `GET /v1/call-tracking-campaigns/{call_tracking_campaign}` | `call_tracking.read` | low | none |
| `get_campaign` | `getAutoDialerCampaign` | `GET /v1/auto-dialer-campaigns/{campaign}` | `campaigns.read` | low | none |
| `get_campaign_caller_id_stats` | `getCampaignCallerIdStats` | `GET /v1/auto-dialer-campaigns/{campaign}/caller-id-stats` | `campaigns.read` | low | none |
| `get_campaign_list` | `getCampaignList` | `GET /v1/auto-dialer-campaigns/{campaign}/list` | `campaigns.read` | low | none |
| `get_campaign_status` | `getMonitorDetail` | `GET /v1/auto-dialer-campaigns/{campaign}/monitor/detail` | `campaigns.read` | low | none |
| `get_campaigns_monitor_summary` | `getMonitorSummary` | `GET /v1/auto-dialer-campaigns/monitor/summary` | `campaigns.read` | low | none |
| `get_conference_room` | `getConferenceRoom` | `GET /v1/conference-rooms/{conference_room}` | `conference_rooms.read` | low | none |
| `get_distribution_list` | `getDistributionList` | `GET /v1/auto-dialer-campaigns/lists/{list}` | `distribution_lists.read` | low | none |
| `get_distribution_list_validation_errors` | `getListValidationErrors` | `GET /v1/auto-dialer-campaigns/lists/{list}/validation-errors` | `distribution_lists.read` | low | none |
| `get_extension` | `getExtension` | `GET /v1/extensions/{extension}` | `extensions.read` | low | none |
| `get_inbound_blacklist_entry` | `getInboundBlacklistEntry` | `GET /v1/inbound-blacklist/{inbound_blacklist}` | `inbound_blacklist.read` | low | none |
| `get_inbound_blacklist_statistics` | `getBlacklistStatistics` | `GET /v1/inbound-blacklist/statistics` | `inbound_blacklist.read` | low | none |
| `get_ivr_menu` | `getIVRMenu` | `GET /v1/ivr-menus/{ivrMenu}` | `ivr.read` | low | none |
| `get_organization` | _(composite)_ | — | `organization.read` | low | none |
| `get_outbound_whitelist_entry` | `getOutboundWhitelistEntry` | `GET /v1/outbound-whitelist/{outbound_whitelist}` | `outbound_whitelist.read` | low | none |
| `get_phone_number` | `getPhoneNumber` | `GET /v1/phone-numbers/{phone_number}` | `phone_numbers.read` | low | none |
| `get_recording_metadata` | `getRecording` | `GET /v1/recordings/{recording}` | `recordings.read` | low | none |
| `get_ring_group` | `getRingGroup` | `GET /v1/ring-groups/{ring_group}` | `ring_groups.read` | low | none |
| `get_supervisor_assignments` | `getSupervisorAssignments` | `GET /v1/supervisors/{user}/assignments` | `supervisors.read` | low | none |
| `get_supervisor_dashboard` | `getSupervisorDashboard` | `GET /v1/dashboard/supervisor` | `supervisors.read` | low | none |
| `get_user` | `getUser` | `GET /v1/users/{user}` | `users.read` | low | none |
| `invite_user` | `inviteUser` | `POST /v1/users/invite` | `users.invite` | medium | none |
| `list_active_calls` | `getActiveSessions` | `GET /v1/session-updates/active` | `live_calls.read` | low | none |
| `list_ai_assistants` | `listAiAssistants` | `GET /v1/ai-assistants` | `ai_assistants.read` | low | none |
| `list_ai_load_balancers` | `listAiLoadBalancers` | `GET /v1/ai-assistant-load-balancers` | `ai_load_balancers.read` | low | none |
| `list_ai_providers` | `listAiAssistantProviders` | `GET /v1/ai-assistant/providers` | `ai_assistants.read` | low | none |
| `list_available_caller_ids` | `getAvailableCallerIds` | `GET /v1/auto-dialer-campaigns/available-caller-ids` | `campaigns.read` | low | none |
| `list_blocked_calls` | `getBlockedCallLogs` | `GET /v1/inbound-blacklist/blocked-logs` | `inbound_blacklist.read` | low | none |
| `list_business_hours` | `listBusinessHoursSchedules` | `GET /v1/business-hours` | `business_hours.read` | low | none |
| `list_call_tracking_campaigns` | `listCallTrackingCampaigns` | `GET /v1/call-tracking-campaigns` | `call_tracking.read` | low | none |
| `list_call_tracking_numbers` | `listCallTrackingNumbers` | `GET /v1/call-tracking-campaigns/{call_tracking_campaign}/call-tracking-numbers` | `call_tracking.read` | low | none |
| `list_call_tracking_sessions` | `listCallTrackingSessions` | `GET /v1/call-tracking-sessions` | `call_tracking.read` | low | none |
| `list_campaign_destinations` | `listCampaignDestinations` | `GET /v1/auto-dialer-campaigns/{campaign}/destinations` | `campaigns.read` | low | none |
| `list_campaigns` | `listAutoDialerCampaigns` | `GET /v1/auto-dialer-campaigns` | `campaigns.read` | low | none |
| `list_conference_rooms` | `listConferenceRooms` | `GET /v1/conference-rooms` | `conference_rooms.read` | low | none |
| `list_distribution_list_destinations` | `listListDestinations` | `GET /v1/auto-dialer-campaigns/lists/{list}/destinations` | `distribution_lists.read` | low | none |
| `list_distribution_lists` | `listDistributionLists` | `GET /v1/auto-dialer-campaigns/lists` | `distribution_lists.read` | low | none |
| `list_extensions` | `listExtensions` | `GET /v1/extensions` | `extensions.read` | low | none |
| `list_inbound_blacklist` | `listInboundBlacklistEntrys` | `GET /v1/inbound-blacklist` | `inbound_blacklist.read` | low | none |
| `list_ivr_menus` | `listIVRMenus` | `GET /v1/ivr-menus` | `ivr.read` | low | none |
| `list_ivr_voices` | `getIvrVoices` | `GET /v1/ivr-menus/voices` | `ivr.read` | low | none |
| `list_outbound_whitelist` | `listOutboundWhitelistEntrys` | `GET /v1/outbound-whitelist` | `outbound_whitelist.read` | low | none |
| `list_phone_numbers` | `listPhoneNumbers` | `GET /v1/phone-numbers` | `phone_numbers.read` | low | none |
| `list_recordings` | `listRecordings` | `GET /v1/recordings` | `recordings.read` | low | none |
| `list_ring_groups` | `listRingGroups` | `GET /v1/ring-groups` | `ring_groups.read` | low | none |
| `list_users` | `listUsers` | `GET /v1/users` | `users.read` | low | none |
| `pause_campaign` | `pauseAutoDialerCampaign` | `PATCH /v1/auto-dialer-campaigns/{campaign}/pause` | `campaigns.pause` | high | required |
| `remove_outbound_whitelist_rule` | `deleteOutboundWhitelistEntry` | `DELETE /v1/outbound-whitelist/{outbound_whitelist}` | `outbound_whitelist.delete` | high | required |
| `resume_campaign` | `resumeAutoDialerCampaign` | `PATCH /v1/auto-dialer-campaigns/{campaign}/resume` | `campaigns.resume` | high | required |
| `search_calls` | `listCallDetailRecords` | `GET /v1/call-detail-records` | `calls.read` | low | none |
| `set_business_hours_status` | `toggleBusinessHoursStatus` | `PATCH /v1/business-hours/{businessHour}/toggle-status` | `business_hours.update` | medium | none |
| `set_inbound_blacklist_status` | `toggleBlacklistStatus` | `PATCH /v1/inbound-blacklist/{inboundBlacklist}/toggle-status` | `inbound_blacklist.update` | medium | none |
| `set_ivr_menu_status` | `toggleIvrMenuStatus` | `PATCH /v1/ivr-menus/{ivrMenu}/toggle-status` | `ivr.update` | medium | none |
| `set_outbound_whitelist_status` | `toggleWhitelistStatus` | `PATCH /v1/outbound-whitelist/{outboundWhitelist}/toggle-status` | `outbound_whitelist.update` | medium | none |
| `start_call_coaching` | `resolveCoachTarget` | `POST /v1/session-updates/{sessionId}/coach-target` | `live_calls.coach` | high | required |
| `start_campaign` | `startAutoDialerCampaign` | `PATCH /v1/auto-dialer-campaigns/{campaign}/start` | `campaigns.start` | high | required |
| `unassign_distribution_list` | `unassignListFromCampaign` | `POST /v1/auto-dialer-campaigns/lists/{list}/unassign` | `distribution_lists.update` | medium | none |
| `unblock_inbound_number` | `deleteInboundBlacklistEntry` | `DELETE /v1/inbound-blacklist/{inbound_blacklist}` | `inbound_blacklist.delete` | high | required |
| `update_ai_assistant` | `updateAiAssistant` | `PUT /v1/ai-assistants/{ai_assistant}` | `ai_assistants.update` | medium | none |
| `update_business_hours` | `updateBusinessHoursSchedule` | `PUT /v1/business-hours/{business_hour}` | `business_hours.update` | medium | none |
| `update_campaign` | `updateAutoDialerCampaign` | `PUT /v1/auto-dialer-campaigns/{campaign}` | `campaigns.update` | medium | none |
| `update_conference_room` | `updateConferenceRoom` | `PUT /v1/conference-rooms/{conference_room}` | `conference_rooms.update` | medium | none |
| `update_extension` | `updateExtension` | `PUT /v1/extensions/{extension}` | `extensions.update` | medium | none |
| `update_ivr_menu` | `updateIVRMenu` | `PUT /v1/ivr-menus/{ivrMenu}` | `ivr.update` | medium | none |
| `update_phone_number` | `updatePhoneNumber` | `PUT /v1/phone-numbers/{phone_number}` | `phone_numbers.update` | medium | none |
| `update_ring_group` | `updateRingGroup` | `PUT /v1/ring-groups/{ring_group}` | `ring_groups.update` | medium | none |
| `update_user` | `updateUser` | `PUT /v1/users/{user}` | `users.update` | medium | none |
| `validate_configuration` | _(composite)_ | — | `configuration.validate` | low | none |

## Manual validation checklist

- [x] Webhook-free: no execution-plane operations exposed (verified by contract test categories)
- [x] Contract tests: every operationId/method/path exists in the OpenAPI spec
- [x] Security tests: tenant isolation, org override, path injection, SSRF, RBAC, confirmation bypass
- [x] Live integration tests (env-gated): tests/integration/live.test.ts
- [x] Live manual verification per tool group (see IMPLEMENTATION_REPORT.md)
