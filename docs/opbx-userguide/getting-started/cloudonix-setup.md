---
sidebar_position: 3
title: "Cloudonix Integration"
description: "Connect OPBX to the Cloudonix CPaaS platform"
---

# Cloudonix Integration

Cloudonix is the cloud communications platform that handles all VoIP and telephony functions for OPBX. OPBX provides the PBX configuration layer, while Cloudonix executes the actual calls.

## Understanding the Relationship

| OPBX | Cloudonix |
|------|-----------|
| PBX configuration and logic | Call execution and SIP services |
| User management and routing rules | Media processing and call routing |
| Web interface and APIs | Telephony infrastructure |
| Call data and reporting | Carrier connectivity |

When a call comes in, OPBX tells Cloudonix how to handle it. Cloudonix then manages the actual voice connection.

## Prerequisites

Before configuring the integration, you need:

1. A Cloudonix account - Sign up at [cockpit.cloudonix.io](https://cockpit.cloudonix.io)
2. A domain created in your Cloudonix account
3. Domain API key from the Cloudonix cockpit

:::tip New to Cloudonix?
Cloudonix offers a free tier for testing. You can sign up without a credit card and start exploring immediately.
:::

## Setup Steps

### 1. Access Settings

Log into OPBX as an Owner and navigate to **Settings** in the left sidebar. The Cloudonix integration section appears at the top of the page.

:::note Owner-Only Access
Only users with the Owner role can configure Cloudonix integration. PBX Admins and other roles cannot access these settings.
:::

### 2. Enter Domain UUID

Find your Domain UUID in the Cloudonix cockpit:

1. Log into [cockpit.cloudonix.io](https://cockpit.cloudonix.io)
2. Select your domain from the dropdown
3. Copy the Domain UUID from the domain settings page

Paste this UUID into the **Domain UUID** field in OPBX settings.

### 3. Enter Domain API Key

Generate or copy your API key:

1. In the Cloudonix cockpit, go to **API Keys** section
2. Create a new key or use an existing one
3. Copy the key value

Paste this key into the **Domain API Key** field in OPBX settings.

### 4. Validate Credentials

Click the **Validate Credentials** button. OPBX tests the connection to Cloudonix and verifies your credentials.

If validation succeeds, the system automatically creates a voice application on Cloudonix for handling your calls.

### 5. Generate Webhook API Key

Click **Generate Webhook API Key** to create a secure key for webhook authentication. Cloudonix uses this key to sign webhook requests sent to your OPBX instance.

:::warning Keep This Secret
Store the webhook API key securely. Anyone with this key could potentially send fake call events to your system.
:::

## Webhook Configuration

OPBX automatically configures the webhook URL for Cloudonix callbacks. The default URL follows this pattern:

```
https://your-opbx-domain/api/v1/webhooks/cloudonix
```

### Behind NAT or Firewall

If your OPBX instance is behind a NAT device or firewall, Cloudonix cannot reach the default webhook URL. You have two options:

**Option 1: Environment Variable**

Set the `OPBX_APPLICATION_WEBHOOK_BASEURL` environment variable in your `.env` file:

```bash
OPBX_APPLICATION_WEBHOOK_BASEURL=https://your-public-domain
```

**Option 2: Settings Page**

Configure the webhook base URL directly in the Settings page under **Advanced Options**.

### Local Development with Ngrok

For local development, use ngrok to expose your local OPBX instance:

1. Add your ngrok authtoken to `.env`:
   ```bash
   NGROK_AUTHTOKEN=your_token_here
   ```

2. Restart the containers:
   ```bash
   docker compose restart
   ```

3. Access the ngrok dashboard at `http://localhost:4040` to see your public URL

4. Copy the HTTPS URL and set it as your webhook base URL in OPBX settings

:::tip Ngrok Free Tier
The free ngrok tier provides temporary URLs that change on restart. For persistent development domains, consider upgrading to a paid plan.
:::

## Syncing Extensions

After Cloudonix integration is active, sync your user extensions as Cloudonix subscribers:

1. Go to **Extensions** in the sidebar
2. Click the **Sync to Cloudonix** button
3. The system creates SIP subscribers for all USER-type extensions
4. Each user receives SIP credentials for their phone configuration

:::note Extension Types
Only USER-type extensions sync to Cloudonix as SIP subscribers. Other extension types (conference, IVR, ring groups) are handled internally by OPBX.
:::

## Verification

Test your integration by making a call:

1. Configure a DID number in OPBX
2. Route the DID to an extension or ring group
3. Dial the phone number from an external phone
4. Verify the call connects to the expected destination

Check the call logs in both OPBX and the Cloudonix cockpit to confirm proper call flow.

## Troubleshooting

| Issue | Solution |
|-------|----------|
| "Invalid credentials" error | Verify Domain UUID and API key are correct and belong to the same domain |
| Webhooks not received | Check webhook base URL is publicly accessible; verify firewall rules |
| Calls fail to connect | Ensure extensions are synced to Cloudonix; check SIP credentials |
| No audio on calls | Verify NAT settings; check Cloudonix media server connectivity |

:::info Support
For Cloudonix-specific issues, consult the [Cloudonix documentation](https://developers.cloudonix.com/). For OPBX integration issues, check the application logs with `docker compose logs -f app`.
:::
