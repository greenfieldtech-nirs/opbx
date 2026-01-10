# ngrok Setup Guide for Cloudonix Webhook Development

This guide covers setting up ngrok to expose your local OpBX development environment to the internet for Cloudonix webhook testing and integration.

## Overview

ngrok creates secure tunnels from your local development environment to the public internet, allowing Cloudonix to send webhooks to your local OpBX instance. This is essential for testing real-time call routing and webhook functionality.

## Prerequisites

- Docker and Docker Compose installed
- OpBX project running locally
- ngrok account (free tier is sufficient)

## Step 1: Get Your ngrok Authtoken

1. **Create ngrok Account:**
   - Go to [https://ngrok.com](https://ngrok.com)
   - Sign up for a free account
   - Verify your email

2. **Get Your Authtoken:**
   - Go to [https://dashboard.ngrok.com/get-started/your-authtoken](https://dashboard.ngrok.com/get-started/your-authtoken)
   - Copy the authtoken (it starts with `2` and is around 50 characters)

## Step 2: Configure Environment Variables

1. **Copy Environment Template:**
   ```bash
   cp .env.example .env
   ```

2. **Set ngrok Authtoken:**
   Edit your `.env` file and add:
   ```bash
   # ngrok Configuration
   # Get your authtoken from https://dashboard.ngrok.com/get-started/your-authtoken
   NGROK_AUTHTOKEN=2ABC...your_token_here
   ```

3. **Set Webhook Base URL (initially empty):**
   ```bash
   # Webhook Configuration
   # Public URL where Cloudonix can reach your webhooks
   # For local development, use ngrok (tunnel service runs in Docker)
   WEBHOOK_BASE_URL=
   ```

## Step 3: Start Docker Services with ngrok

ngrok is already configured in your `docker-compose.yml` file. Start all services:

```bash
docker-compose up -d
```

This will start:
- Laravel app (`app` container)
- nginx reverse proxy (`nginx` container)
- ngrok tunnel (`ngrok` container)
- MySQL database
- Redis cache
- Queue worker
- WebSocket server (Soketi)

## Step 4: Access ngrok Web Interface

ngrok provides a web interface to monitor tunnels and view request logs.

1. **Open ngrok Web UI:**
   - Go to [http://localhost:4040](http://localhost:4040) in your browser

2. **What You'll See:**
   - **Status:** Connection status and tunnel information
   - **Tunnels:** Active tunnels with public URLs
   - **Traffic Inspector:** Real-time webhook request logs
   - **Events:** Connection events and errors

## Step 5: Get Your Public Webhook URL

1. **Find Your Tunnel URL:**
   - In the ngrok web interface (http://localhost:4040)
   - Look for the tunnel entry (usually "http-80")
   - Copy the **HTTPS URL** (not HTTP) - it looks like: `https://abc123.ngrok.io`

2. **Update Environment Variable:**
   Edit your `.env` file:
   ```bash
   WEBHOOK_BASE_URL=https://abc123.ngrok.io
   ```

3. **Restart Services:**
   ```bash
   docker-compose restart
   ```

## Step 6: Configure Webhooks in Cloudonix Portal

### Voice Routing Webhooks (Real-time Call Control)

These webhooks control active phone calls and use Bearer token authentication.

1. **Log into Cloudonix Portal:**
   - Go to your Cloudonix developer account
   - Navigate to your domain/application settings

2. **Configure Voice Routing URL:**
   ```
   https://abc123.ngrok.io/api/voice/route
   ```

3. **Configure IVR Input URL (if using IVR):**
   ```
   https://abc123.ngrok.io/api/voice/ivr-input
   ```

4. **Configure Ring Group Callback URL (if using sequential routing):**
   ```
   https://abc123.ngrok.io/api/voice/ring-group-callback
   ```

### Status & CDR Webhooks (Asynchronous Notifications)

These webhooks provide call status updates and billing information.

1. **Configure Call Status Webhook:**
   ```
   https://abc123.ngrok.io/api/webhooks/cloudonix/call-status
   ```

2. **Configure Call Initiated Webhook:**
   ```
   https://abc123.ngrok.io/api/webhooks/cloudonix/call-initiated
   ```

3. **Configure Session Update Webhook:**
   ```
   https://abc123.ngrok.io/api/webhooks/cloudonix/session-update
   ```

4. **Configure CDR Webhook:**
   ```
   https://abc123.ngrok.io/api/webhooks/cloudonix/cdr
   ```

### Webhook Security Configuration

1. **Set Webhook Secret:**
   - In Cloudonix portal, configure the webhook secret to match your `.env`:
     ```bash
     CLOUDONIX_WEBHOOK_SECRET=your_64_char_secret_here
     ```

2. **Organization-Specific Settings:**
   - Set Bearer tokens for voice routing per organization
   - Configure domain UUID for CDR webhook organization identification

## Step 7: Test Webhook Delivery

### Manual Testing with cURL

**Test Voice Routing Webhook:**
```bash
# Get organization's Bearer token from your database first
TOKEN="XI_your_org_bearer_token"

curl -X POST https://abc123.ngrok.io/api/voice/route \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "from": "+14155551234",
    "to": "+14155559999",
    "call_id": "test_call_123"
  }'
```

**Test Status Webhook:**
```bash
# Generate HMAC-SHA256 signature
PAYLOAD='{"call_id":"test_123","status":"completed"}'
SECRET="your_webhook_secret_from_env"
SIGNATURE=$(echo -n "$PAYLOAD" | openssl dgst -sha256 -hmac "$SECRET" | cut -d' ' -f2)

curl -X POST https://abc123.ngrok.io/api/webhooks/cloudonix/call-status \
  -H "X-Cloudonix-Signature: $SIGNATURE" \
  -H "Content-Type: application/json" \
  -d "$PAYLOAD"
```

### Automated Testing

```bash
# Run all webhook tests
php artisan test tests/Feature/Webhooks/

# Run voice routing tests specifically
php artisan test --filter=VoiceRouting

# Run webhook authentication tests
php artisan test --filter=WebhookAuth
```

### Monitor Webhook Activity

1. **Check ngrok Web Interface:**
   - Visit [http://localhost:4040](http://localhost:4040)
   - Go to "Traffic Inspector" tab
   - Watch for incoming webhook requests

2. **Check Application Logs:**
   ```bash
   docker-compose logs -f app
   ```

3. **Check Laravel Logs:**
   ```bash
   tail -f storage/logs/laravel.log
   ```

## Troubleshooting

### ngrok Connection Issues

**Problem:** ngrok container fails to start
```bash
# Check if authtoken is set correctly
docker-compose logs ngrok

# Verify authtoken format (should start with '2')
echo $NGROK_AUTHTOKEN
```

**Problem:** Tunnel shows "offline"
```bash
# Restart ngrok service
docker-compose restart ngrok

# Check if nginx is running
docker-compose ps nginx
```

### Webhook Authentication Failures

**Voice Webhook Issues:**
- Check that Bearer token is configured in Organization Settings
- Verify DID number is mapped to correct organization
- Check logs: `docker-compose logs app | grep "Voice webhook"`

**Status/CDR Webhook Issues:**
- Verify webhook secret matches between `.env` and Cloudonix portal
- Check signature generation logic
- Check logs: `docker-compose logs app | grep "signature"`

### Common Issues

1. **Using HTTP instead of HTTPS:**
   - Always use HTTPS URLs from ngrok
   - Update `WEBHOOK_BASE_URL` with https://

2. **Authtoken Expired:**
   - Get new authtoken from ngrok dashboard
   - Update `.env` and restart services

3. **ngrok URL Changed:**
   - ngrok URLs change on restart (unless using paid plan)
   - Update `WEBHOOK_BASE_URL` in `.env`
   - Update webhook URLs in Cloudonix portal

4. **Port Conflicts:**
   - Ensure port 4040 is available locally
   - Check if another service is using it

## Security Considerations

- **Never commit authtoken to git**
- **Use HTTPS URLs only**
- **Monitor webhook logs for suspicious activity**
- **Rotate webhook secrets regularly**
- **Use ngrok's paid plan for production-like URLs**

## Advanced Configuration

### Custom ngrok Configuration

Create `ngrok.yml` for advanced tunnel configuration:

```yaml
authtoken: your_token_here
tunnels:
  web:
    proto: http
    addr: nginx:80
    hostname: your-custom-domain.ngrok.io  # Paid plan feature
```

### Persistent URLs (Paid Plan)

For persistent URLs that don't change on restart:
1. Upgrade to ngrok paid plan
2. Reserve a custom domain
3. Update tunnel configuration

### Multiple Tunnels

If you need separate tunnels for different services:

```yaml
tunnels:
  web:
    proto: http
    addr: nginx:80
  api:
    proto: http
    addr: app:8000  # Direct to Laravel
```

## Related Documentation

- [Webhook Authentication Guide](WEBHOOK-AUTHENTICATION.md)
- [Cloudonix Developer Documentation](https://developers.cloudonix.com)
- [ngrok Documentation](https://ngrok.com/docs)
- [Docker Compose Configuration](../docker-compose.yml)

---

## Quick Reference

**Start Services:**
```bash
docker-compose up -d
```

**Check Tunnel URL:**
```bash
curl http://localhost:4040/api/tunnels | jq '.tunnels[0].public_url'
```

**Monitor Logs:**
```bash
docker-compose logs -f ngrok
```

**Update Webhook URL:**
```bash
# Edit .env
WEBHOOK_BASE_URL=https://new-url.ngrok.io
docker-compose restart
```

**Test Webhook:**
```bash
curl -X POST https://your-url.ngrok.io/api/webhooks/cloudonix/test \
  -H "Content-Type: application/json" \
  -d '{"test": true}'
```</content>
<parameter name="filePath">docs/NGROK_SETUP_GUIDE.md