import type { OpbxClient, OperationId, paths } from "../client.js";
import { fetchAllPages, type OperationRef } from "./read-service.js";

/**
 * Cross-resource configuration audit. Read-only: loads the tenant's
 * collections once each and checks referential integrity and activation
 * consistency. Bounded (max 1000 items per collection, details fetched only
 * for relevant campaigns) and defensive (a failing collection degrades that
 * section instead of failing the whole audit).
 */

export interface ValidationIssue {
  severity: "error" | "warning" | "info";
  code: string;
  resource_type: string;
  resource_id: number | string;
  message: string;
  suggested_action: string;
}

export interface ValidationReport {
  valid: boolean;
  issues: ValidationIssue[];
  checked: Record<string, number | string>;
}

type Rec = Record<string, unknown>;

const ref = <Op extends OperationId>(
  operationId: Op,
  path: keyof paths & string,
): OperationRef<Op> => ({ operationId, method: "GET", path });

const REFS = {
  extensions: ref("listExtensions", "/v1/extensions"),
  ringGroups: ref("listRingGroups", "/v1/ring-groups"),
  ivrMenus: ref("listIVRMenus", "/v1/ivr-menus"),
  businessHours: ref("listBusinessHoursSchedules", "/v1/business-hours"),
  conferenceRooms: ref("listConferenceRooms", "/v1/conference-rooms"),
  aiAssistants: ref("listAiAssistants", "/v1/ai-assistants"),
  aiLoadBalancers: ref("listAiLoadBalancers", "/v1/ai-assistant-load-balancers"),
  phoneNumbers: ref("listPhoneNumbers", "/v1/phone-numbers"),
  campaigns: ref("listAutoDialerCampaigns", "/v1/auto-dialer-campaigns"),
  distributionLists: ref("listDistributionLists", "/v1/auto-dialer-campaigns/lists"),
  outboundWhitelist: ref("listOutboundWhitelistEntrys", "/v1/outbound-whitelist"),
  campaignList: ref("getCampaignList", "/v1/auto-dialer-campaigns/{campaign}/list"),
  campaignDetail: ref("getAutoDialerCampaign", "/v1/auto-dialer-campaigns/{campaign}"),
} as const;

function idOf(e: Rec): number | string {
  return (e.id as number | string) ?? "?";
}

function isActive(e: Rec | undefined): boolean {
  return !!e && e.status === "active";
}

/** Business-hours/legacy prefixed target ids: ext-13, rg-5, conf-1, ivr-1. */
function parsePrefixed(target: unknown): { type: string; id: number } | null {
  if (typeof target !== "string") return null;
  const m = /^(ext|rg|conf|ivr)-(\d+)$/.exec(target);
  if (!m) return null;
  return { type: { ext: "extension", rg: "ring_group", conf: "conference_room", ivr: "ivr_menu" }[m[1]!]!, id: Number(m[2]) };
}

export async function validateConfiguration(client: OpbxClient): Promise<ValidationReport> {
  const issues: ValidationIssue[] = [];
  const checked: Record<string, number | string> = {};

  // --- Load collections (defensive: one failure degrades only its section) --
  const collections: Record<string, Rec[] | null> = {};
  const loaders: [string, OperationRef][] = [
    ["extensions", REFS.extensions],
    ["ring_groups", REFS.ringGroups],
    ["ivr_menus", REFS.ivrMenus],
    ["business_hours", REFS.businessHours],
    ["conference_rooms", REFS.conferenceRooms],
    ["ai_assistants", REFS.aiAssistants],
    ["ai_load_balancers", REFS.aiLoadBalancers],
    ["phone_numbers", REFS.phoneNumbers],
    ["campaigns", REFS.campaigns],
    ["distribution_lists", REFS.distributionLists],
    ["outbound_whitelist", REFS.outboundWhitelist],
  ];
  await Promise.all(
    loaders.map(async ([key, r]) => {
      try {
        collections[key] = (await fetchAllPages(client, r)) as Rec[];
      } catch (err) {
        collections[key] = null;
        issues.push({
          severity: "info",
          code: "COLLECTION_UNAVAILABLE",
          resource_type: key,
          resource_id: "-",
          message: `Could not load ${key}: ${err instanceof Error ? err.message : String(err)}. Related checks were skipped.`,
          suggested_action: "Re-run validate_configuration or check permissions.",
        });
      }
    }),
  );
  for (const [key, items] of Object.entries(collections)) {
    checked[key] = items === null ? "skipped" : items.length;
  }

  const byId = (key: string) => {
    const map = new Map<number, Rec>();
    for (const e of collections[key] ?? []) {
      const id = Number(e.id);
      if (!Number.isNaN(id)) map.set(id, e);
    }
    return map;
  };

  const extensions = byId("extensions");
  const ringGroups = byId("ring_groups");
  const ivrMenus = byId("ivr_menus");
  const businessHours = byId("business_hours");
  const conferenceRooms = byId("conference_rooms");
  const aiAssistants = byId("ai_assistants");
  const aiLoadBalancers = byId("ai_load_balancers");

  const targetMap: Record<string, Map<number, Rec>> = {
    extension: extensions,
    ring_group: ringGroups,
    ivr_menu: ivrMenus,
    business_hours: businessHours,
    conference_room: conferenceRooms,
    ai_assistant: aiAssistants,
    ai_load_balancer: aiLoadBalancers,
  };

  const checkTarget = (opts: {
    type: string;
    id: number | undefined | null;
    ownerType: string;
    ownerId: number | string;
    ownerActive: boolean;
    context: string;
  }): void => {
    if (opts.id === undefined || opts.id === null) return;
    const target = targetMap[opts.type]?.get(Number(opts.id));
    const base = {
      resource_type: opts.ownerType,
      resource_id: opts.ownerId,
    };
    if (!target) {
      issues.push({
        ...base,
        severity: opts.ownerActive ? "error" : "warning",
        code: "TARGET_MISSING",
        message: `${opts.ownerType} #${opts.ownerId} ${opts.context} references ${opts.type} #${opts.id}, which does not exist.`,
        suggested_action: `Re-point or delete the ${opts.ownerType} (see update_/delete_ tools).`,
      });
      return;
    }
    if (!isActive(target)) {
      issues.push({
        ...base,
        severity: opts.ownerActive ? "error" : "warning",
        code: "TARGET_INACTIVE",
        message: `${opts.ownerType} #${opts.ownerId} ${opts.context} references ${opts.type} #${opts.id} ("${target.name ?? target.extension_number ?? ""}"), which is inactive.`,
        suggested_action: `Activate the ${opts.type} or re-route the ${opts.ownerType}.`,
      });
      return;
    }
    if (opts.type === "ring_group") {
      const activeMembers = Number(target.active_members_count ?? -1);
      if (activeMembers === 0) {
        issues.push({
          ...base,
          severity: "error",
          code: "RING_GROUP_NO_ACTIVE_MEMBERS",
          message: `${opts.ownerType} #${opts.ownerId} ${opts.context} routes to ring group #${opts.id} ("${target.name}"), which has no active members — calls will hit the fallback.`,
          suggested_action: "Add active members with update_ring_group or change the routing.",
        });
      }
    }
  };

  // --- 1. DID routing ---------------------------------------------------------
  for (const did of collections.phone_numbers ?? []) {
    const rt = did.routing_type as string | undefined;
    const rc = (did.routing_config ?? {}) as Rec;
    if (!rt || rt === "call_tracking") continue;
    const key = rt === "business_hours" ? "business_hours_schedule_id" : `${rt}_id`;
    checkTarget({
      type: rt,
      id: rc[key] as number | undefined,
      ownerType: "phone_number",
      ownerId: idOf(did),
      ownerActive: did.status === "active",
      context: `(${did.phone_number}) routing`,
    });
  }

  // --- 2. Ring groups -----------------------------------------------------------
  for (const rg of collections.ring_groups ?? []) {
    const members = (rg.members as unknown[] | undefined) ?? [];
    const membersCount = Number(rg.members_count ?? members.length);
    if (membersCount === 0) {
      issues.push({
        severity: isActive(rg) ? "error" : "warning",
        code: "RING_GROUP_NO_MEMBERS",
        resource_type: "ring_group",
        resource_id: idOf(rg),
        message: `Ring group #${idOf(rg)} ("${rg.name}") has no members — calls fall through to the fallback.`,
        suggested_action: "Add members with update_ring_group or deactivate the group.",
      });
    } else if (Number(rg.active_members_count ?? 1) === 0) {
      issues.push({
        severity: isActive(rg) ? "error" : "warning",
        code: "RING_GROUP_NO_ACTIVE_MEMBERS",
        resource_type: "ring_group",
        resource_id: idOf(rg),
        message: `Ring group #${idOf(rg)} ("${rg.name}") has ${membersCount} member(s) but none active.`,
        suggested_action: "Activate member extensions or add active members.",
      });
    }
    const fa = rg.fallback_action as string | undefined;
    if (fa && fa !== "hangup") {
      checkTarget({
        type: fa,
        id: rg[`fallback_${fa}_id`] as number | undefined,
        ownerType: "ring_group",
        ownerId: idOf(rg),
        ownerActive: isActive(rg),
        context: "fallback",
      });
    }
  }

  // --- 3. IVR menus ---------------------------------------------------------------
  for (const ivr of collections.ivr_menus ?? []) {
    const ft = ivr.failover_destination_type as string | undefined;
    if (ft && ft !== "hangup") {
      checkTarget({
        type: ft,
        id: ivr.failover_destination_id as number | undefined,
        ownerType: "ivr_menu",
        ownerId: idOf(ivr),
        ownerActive: isActive(ivr),
        context: "failover",
      });
    }
    for (const opt of (ivr.options as Rec[] | undefined) ?? []) {
      const dt = opt.destination_type as string | undefined;
      if (!dt) continue;
      checkTarget({
        type: dt,
        id: opt.destination_id as number | undefined,
        ownerType: "ivr_menu",
        ownerId: idOf(ivr),
        ownerActive: isActive(ivr),
        context: `option '${opt.input_digits}'`,
      });
    }
  }

  // --- 4. Business hours -------------------------------------------------------
  for (const bh of collections.business_hours ?? []) {
    for (const which of ["open_hours_action", "closed_hours_action"] as const) {
      const action = bh[which] as Rec | undefined;
      const parsed = parsePrefixed(action?.target_id);
      if (!parsed) {
        if (action?.target_id) {
          issues.push({
            severity: "error",
            code: "BH_ACTION_UNPARSEABLE",
            resource_type: "business_hours",
            resource_id: idOf(bh),
            message: `Business hours #${idOf(bh)} ("${bh.name}") ${which} target '${action.target_id}' is not a valid prefixed ID (ext-/rg-/conf-/ivr-).`,
            suggested_action: "Fix with update_business_hours.",
          });
        }
        continue;
      }
      checkTarget({
        type: parsed.type,
        id: parsed.id,
        ownerType: "business_hours",
        ownerId: idOf(bh),
        ownerActive: isActive(bh),
        context: which.replace("_", " "),
      });
    }
  }

  // --- 5. Extensions ----------------------------------------------------------
  for (const ext of collections.extensions ?? []) {
    const type = ext.type as string;
    const cfg = (ext.configuration ?? {}) as Rec;
    const configTargetKey: Record<string, [string, string]> = {
      conference: ["conference_room", "conference_room_id"],
      ring_group: ["ring_group", "ring_group_id"],
      ivr: ["ivr_menu", "ivr_id"],
      ai_assistant: ["ai_assistant", "ai_assistant_id"],
      ai_load_balancer: ["ai_load_balancer", "ai_load_balancer_id"],
    };
    const ct = configTargetKey[type];
    if (ct) {
      checkTarget({
        type: ct[0],
        id: cfg[ct[1]] as number | undefined,
        ownerType: "extension",
        ownerId: idOf(ext),
        ownerActive: isActive(ext),
        context: `(${ext.extension_number}) configuration`,
      });
    }
    const aaId = ext.ai_assistant_id as number | null | undefined;
    if (aaId) {
      checkTarget({
        type: "ai_assistant",
        id: aaId,
        ownerType: "extension",
        ownerId: idOf(ext),
        ownerActive: isActive(ext),
        context: `(${ext.extension_number}) AI assistant`,
      });
    }
    const user = ext.user as Rec | undefined;
    if (isActive(ext) && type === "user" && user && user.status !== "active") {
      issues.push({
        severity: "warning",
        code: "EXTENSION_USER_INACTIVE",
        resource_type: "extension",
        resource_id: idOf(ext),
        message: `Active extension ${ext.extension_number} is assigned to user #${user.id} ("${user.name}"), who is ${user.status}.`,
        suggested_action: "Reassign with update_extension or reactivate the user.",
      });
    }
  }

  // --- 6. AI load balancers ---------------------------------------------------
  for (const alb of collections.ai_load_balancers ?? []) {
    const members = (alb.members as Rec[] | undefined) ?? [];
    if (isActive(alb) && members.length === 0) {
      issues.push({
        severity: "error",
        code: "ALB_NO_MEMBERS",
        resource_type: "ai_load_balancer",
        resource_id: idOf(alb),
        message: `Active AI load balancer #${idOf(alb)} ("${alb.name}") has no member assistants.`,
        suggested_action: "Add assistants or deactivate the load balancer.",
      });
    }
    for (const m of members) {
      if (m.status !== "active") continue;
      checkTarget({
        type: "ai_assistant",
        id: m.ai_assistant_id as number | undefined,
        ownerType: "ai_load_balancer",
        ownerId: idOf(alb),
        ownerActive: isActive(alb),
        context: `member (position ${m.position ?? "?"})`,
      });
    }
    const fa = alb.fallback_action as string | undefined;
    if (fa && fa !== "hangup") {
      checkTarget({
        type: fa,
        id: alb[`fallback_${fa}_id`] as number | undefined,
        ownerType: "ai_load_balancer",
        ownerId: idOf(alb),
        ownerActive: isActive(alb),
        context: "fallback",
      });
    }
  }

  // --- 7. Campaigns -------------------------------------------------------------
  const listsByCampaign = new Map<number, Rec>();
  for (const l of collections.distribution_lists ?? []) {
    const cid = Number(l.campaign_id);
    if (!Number.isNaN(cid)) listsByCampaign.set(cid, l);
  }
  const activeish = (collections.campaigns ?? []).filter((c) =>
    ["draft", "active", "paused"].includes(c.status as string),
  );
  for (const c of activeish.slice(0, 50)) {
    const cid = Number(c.id);
    const list = listsByCampaign.get(cid);
    if (!list) {
      issues.push({
        severity: c.status === "draft" ? "warning" : "error",
        code: "CAMPAIGN_NO_LIST",
        resource_type: "campaign",
        resource_id: cid,
        message: `Campaign #${cid} ("${c.name}", ${c.status}) has no distribution list assigned.`,
        suggested_action: "Assign one with assign_distribution_list (warning: this activates draft campaigns upstream).",
      });
    } else if (!["ready", "in_use", "used"].includes(list.status as string)) {
      issues.push({
        severity: "error",
        code: "CAMPAIGN_LIST_NOT_READY",
        resource_type: "campaign",
        resource_id: cid,
        message: `Campaign #${cid} ("${c.name}") is linked to list #${list.id} in status '${list.status}'.`,
        suggested_action: "Check get_distribution_list_validation_errors and fix the list.",
      });
    }
    // Destination + caller-ID pool need the campaign detail (bounded).
    if (c.routing_destination_type && c.routing_destination_type !== "hangup") {
      checkTarget({
        type: c.routing_destination_type as string,
        id: c.routing_destination_id as number | undefined,
        ownerType: "campaign",
        ownerId: cid,
        ownerActive: c.status === "active",
        context: "routing destination",
      });
    }
    try {
      const detail = (await getCampaignDetail(client, cid)) as Rec | null;
      const pool = (detail?.caller_id_pool ?? []) as Rec[];
      for (const entry of pool) {
        const didId = Number(entry.did_id ?? entry.id);
        const did = (collections.phone_numbers ?? []).find((d) => Number(d.id) === didId);
        if (!did || !isActive(did)) {
          issues.push({
            severity: c.status === "active" ? "error" : "warning",
            code: "CAMPAIGN_CALLER_ID_INVALID",
            resource_type: "campaign",
            resource_id: cid,
            message: `Campaign #${cid} ("${c.name}") caller-ID pool references ${did ? `inactive DID "${did.phone_number}"` : `missing DID #${didId}`}.`,
            suggested_action: "Update the pool with update_campaign (not possible while active).",
          });
        }
      }
    } catch {
      /* detail fetch optional */
    }
  }

  // --- 8. Outbound restrictions consistency ----------------------------------
  const wl = collections.outbound_whitelist;
  if (Array.isArray(wl)) {
    const activeRules = wl.filter((r) => r.status === "active");
    if (wl.length > 0 && activeRules.length === 0) {
      issues.push({
        severity: "warning",
        code: "OUTBOUND_WHITELIST_ALL_INACTIVE",
        resource_type: "outbound_whitelist",
        resource_id: "-",
        message: `${wl.length} outbound whitelist rule(s) exist but none are active — outbound calling is currently unrestricted.`,
        suggested_action: "Activate intended rules with set_outbound_whitelist_status or delete stale ones.",
      });
    }
  }

  return {
    valid: !issues.some((i) => i.severity === "error"),
    issues: issues.sort((a, b) => {
      const order = { error: 0, warning: 1, info: 2 };
      return order[a.severity] - order[b.severity];
    }),
    checked,
  };
}

async function getCampaignDetail(client: OpbxClient, id: number): Promise<Rec | null> {
  const body = await client.call("GET", "/v1/auto-dialer-campaigns/{campaign}", "getAutoDialerCampaign", {
    pathParams: { campaign: id } as never,
  });
  if (typeof body === "object" && body !== null && "data" in body) {
    return (body as Rec).data as Rec;
  }
  return body as Rec;
}
