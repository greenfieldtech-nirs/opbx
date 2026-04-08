---
sidebar_position: 6
title: Outbound Whitelist
description: Controlling outbound call destinations
---

# Outbound Whitelist

The Outbound Whitelist controls which external numbers your PBX users can dial. Define allowed destinations by country and prefix to restrict international dialing, control costs, and enforce compliance.

## How It Works

When a user attempts an outbound call, OPBX checks the whitelist to determine if the call is permitted and which trunk to use.

### Scoring System

The matching algorithm uses a scoring system to select the best rule:

| Match Type | Points Added |
|------------|--------------|
| Country match | 10 points |
| Prefix match | 1 point per matching digit |

The rule with the highest score wins. If multiple rules have the same score, the most specific prefix match is used.

### Example Scoring

Given these rules:

| Rule | Country | Prefix |
|------|---------|--------|
| A | US | +1 |
| B | US | +1415 |
| C | UK | +44 |

For a call to `+14155551234`:

- Rule A: 10 (country) + 2 (prefix digits) = 12 points
- Rule B: 10 (country) + 6 (prefix digits) = 16 points
- Rule C: 0 points (country mismatch)

Rule B wins and the call uses its configured trunk.

## Whitelist Entry Settings

Each entry defines an allowed destination:

| Setting | Description | Required |
|---------|-------------|----------|
| Name | Descriptive label for the entry | Yes |
| Destination Country | ISO country code | Yes |
| Destination Prefix | Number prefix (e.g., +1, +44) | No |
| Outbound Trunk | Which trunk to use for these calls | Yes |
| Status | Active or Inactive | Yes |

:::tip
Leave the prefix empty to allow all numbers for the selected country.
:::

## Creating a Whitelist Entry

1. Navigate to **Outbound Whitelist** in the sidebar
2. Click **Create**
3. Enter a descriptive name
4. Select the destination country
5. Enter a prefix (optional)
6. Select the outbound trunk to use
7. Set the status to **Active**
8. Click **Save**

## Use Cases

### Restrict International Dialing

Block expensive international calls by only whitelisting your home country:

| Name | Country | Prefix | Trunk |
|------|---------|--------|-------|
| Domestic Only | US | +1 | Main Trunk |

### Control Costs by Region

Use different trunks for different destinations to optimize rates:

| Name | Country | Prefix | Trunk |
|------|---------|--------|-------|
| US Local | US | +1415 | Local Trunk |
| US Long Distance | US | +1 | LD Trunk |
| International | GB | +44 | International Trunk |

### Enforce Compliance

Meet regulatory requirements by restricting calls to approved destinations:

| Name | Country | Prefix | Trunk |
|------|---------|--------|-------|
| Approved Regions | US | +1 | Compliant Trunk |
| Approved Regions | CA | +1 | Compliant Trunk |

## Status Management

Entries have two status states:

| Status | Behavior |
|--------|----------|
| Active | Considered during call routing |
| Inactive | Ignored by the routing system |

:::note
Setting an entry to Inactive immediately prevents new calls but does not affect ongoing calls.
:::

## Default Deny Behavior

If no whitelist entry matches a dialed number:

- The call is blocked
- The caller hears a "call cannot be completed" message
- The attempt is logged for review

:::warning
There is no "allow all" option. You must explicitly whitelist every destination you want to permit.
:::

## Best Practices

### Start Restrictive

Begin with only essential destinations and expand as needed:

1. Whitelist your primary country first
2. Add specific international destinations as required
3. Use prefixes to narrow scope when possible

### Organize by Purpose

Use clear naming conventions:

- "US-Local-SF" for San Francisco local calls
- "US-National" for US long distance
- "EU-Sales-Approved" for European sales territories

### Review Regularly

Audit your whitelist periodically:

- Remove entries for unused destinations
- Update trunks if you change providers
- Verify prefixes are still correct

### Combine with Extensions

Use extension-level restrictions alongside the whitelist:

- Certain extensions can only dial local numbers
- Manager extensions can dial internationally
- Common area phones restricted to internal calls only

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Calls blocked unexpectedly | Check that a whitelist entry covers the dialed number and country |
| Wrong trunk used | Review the scoring; a more specific prefix may be matching |
| International calls failing | Verify the country code is correct (e.g., GB not UK) |
| Entry not working | Ensure the status is Active and the trunk is operational |
