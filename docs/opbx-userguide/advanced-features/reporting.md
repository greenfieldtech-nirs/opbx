---
sidebar_position: 7
title: Reporting and Analytics
description: Call logs, CDR records, and live monitoring
---

# Reporting and Analytics

OPBX provides comprehensive reporting tools to monitor call activity, analyze usage patterns, and manage active calls in real-time.

## Call Detail Records (CDR)

CDR is the primary call record system, capturing all call events from Cloudonix webhooks.

### Accessing CDR

1. Navigate to **CDR** in the sidebar
2. Use filters to narrow results
3. Click on any record for detailed information

### Available Filters

| Filter | Description |
|--------|-------------|
| From Number | Caller ID or origin number |
| To Number | Destination number |
| Disposition | Call outcome (answered, busy, failed, etc.) |
| Date Range | Start and end dates for the search |

### CDR Fields

Each record contains:

| Field | Description |
|-------|-------------|
| call_id | Unique identifier for the call |
| from | Caller ID or origin number |
| to | Destination number |
| disposition | Final call status |
| duration | Total call duration in seconds |
| billsec | Billable seconds (time after answer) |
| cost | Call cost (if applicable) |

### Exporting Data

Export CDR data to CSV for external analysis:

1. Apply your desired filters
2. Click **Export**
3. Select CSV format
4. The system streams the export to handle large datasets

:::tip
Large exports may take time to generate. The system processes them in the background and notifies you when ready.
:::

### CDR Statistics

View aggregated metrics for your filtered dataset:

| Metric | Description |
|--------|-------------|
| Total Calls | Number of calls in the selected period |
| Total Duration | Sum of all call durations |
| Average Duration | Mean call length |
| Cost Breakdown | Costs grouped by disposition |

## Live Calls

Monitor active calls in real-time.

### Accessing Live Calls

Navigate to **Live Calls** in the sidebar to see all active sessions.

### Displayed Information

| Field | Description |
|-------|-------------|
| Caller ID | Originating phone number |
| Destination | Number being called |
| Status | Current call state |
| Duration | How long the call has been active |

### Call Statuses

| Status | Description |
|--------|-------------|
| Initiated | Call setup in progress |
| Ringing | Destination is ringing |
| Answered | Call is connected |
| Completed | Call has ended |
| Failed | Call could not connect |

### Available Actions

| Action | Permission | Description |
|--------|------------|-------------|
| Disconnect | Admin/Owner only | End an active call immediately |
| Refresh | Any user | Update the call list manually |

:::warning
Disconnecting calls is immediate and cannot be undone. Use this power responsibly.
:::

### Auto-Refresh

Configure the polling interval for automatic updates:

| Interval | Best For |
|----------|----------|
| 1 second | High-volume call centers |
| 5 seconds | Standard monitoring |
| 30 seconds | Background monitoring |
| 60 seconds | Occasional checking |

## Call Notifications

Receive webhook-based notifications for call events.

### Configuration

Call notifications are configured per organization in the settings:

1. Go to **Settings** > **Notifications**
2. Enable call notifications
3. Enter your webhook URL
4. Select which events to receive

### Event Types

| Event | Trigger |
|-------|---------|
| call.initiated | Call starts |
| call.answered | Call is picked up |
| call.completed | Call ends |
| call.failed | Call fails to connect |

### Webhook Payload

Notifications include call details:

```json
{
  "event": "call.completed",
  "call_id": "abc-123-def",
  "from": "+14155551234",
  "to": "+14155555678",
  "disposition": "answered",
  "duration": 120,
  "timestamp": "2024-01-15T10:30:00Z"
}
```

:::note
Ensure your webhook endpoint responds quickly (within 5 seconds) to avoid delivery retries.
:::

## Best Practices

### Regular Review

Schedule time to review CDR data:

- Weekly: Check for unusual patterns
- Monthly: Analyze cost trends
- Quarterly: Review capacity planning

### Filter Effectively

Use combinations of filters to find specific call patterns:

- Filter by disposition to find failed calls
- Filter by date range for billing reconciliation
- Filter by from/to for user activity review

### Export for Analysis

For complex analysis:

1. Export CDR data to CSV
2. Import into your preferred analytics tool
3. Create custom dashboards and reports

### Monitor Live Calls

During peak hours or important events:

- Keep Live Calls open on a dashboard
- Watch for unusual spikes in volume
- Be ready to disconnect problematic calls

## Troubleshooting

| Issue | Solution |
|-------|----------|
| Missing CDR records | Check webhook delivery status in Cloudonix dashboard |
| Live calls not updating | Verify auto-refresh is enabled and check your connection |
| Export fails | Reduce date range or contact support for large exports |
| Notifications not received | Verify webhook URL is accessible and returns 200 OK |
