# ngrok Setup Guide for Webhook Development

## Overview

ngrok is essential for local OpBX development as it creates secure tunnels from the public internet to your local development environment. This allows Cloudonix to send webhook events to your local application during development and testing.

## Prerequisites

1. **ngrok Account**: Sign up at https://ngrok.com
2. **Authtoken**: Get your personal authtoken from the ngrok dashboard
3. **Docker Environment**: OpBX running in Docker containers

## Installation and Configuration

### 1. Get Your ngrok Authtoken

1. Visit https://ngrok.com and create an account
2. Go to your dashboard: https://dashboard.ngrok.com/get-started/your-authtoken
3. Copy your authtoken (starts with `2`)

### 2. Configure Environment Variables

Add your authtoken to the `.env` file in your project root:

```bash
# Add to .env file
NGROK_AUTHTOKEN=2abcd...your_authtoken_here
```

**Security Note**: Never commit your authtoken to version control. The `.env` file should already be in `.gitignore`.

### 3. Start the Docker Environment

Ensure all services are running:

```bash
docker compose up -d
```

This starts the ngrok container which will automatically use your authtoken.

## Accessing ngrok

### ngrok Web Interface

Visit http://localhost:4040 in your browser to access the ngrok web interface.

**What you'll see:**
- **Public URL**: Your tunnel URL (e.g., `https://abc123.ngrok.io`)
- **Status**: Online/offline status
- **Traffic Inspector**: View incoming requests
- **Request Details**: Headers, body, timing information

### ngrok Logs

View ngrok logs to monitor tunnel activity:

```bash
# View ngrok container logs
docker compose logs ngrok

# Follow logs in real-time
docker compose logs -f ngrok
```

**Example log output:**
```
ngrok    | t=2024-01-11T10:30:00+0000 lvl=info msg="started tunnel" obj=tunnels name=command_line addr=http://nginx:80 url=https://abc123.ngrok.io
ngrok    | t=2024-01-11T10:30:05+0000 lvl=info msg="join connections" obj=join id=abc123 url=https://abc123.ngrok.io
```

## Configuring Cloudonix Webhooks

### 1. Get Your ngrok URL

From the ngrok web interface (http://localhost:4040), copy the **HTTPS URL** (e.g., `https://abc123.ngrok.io`).

### 2. Update Environment Variable

Update your `.env` file with the webhook base URL:

```bash
WEBHOOK_BASE_URL=https://abc123.ngrok.io
```

### 3. Configure Cloudonix Portal

Log into your Cloudonix portal and configure webhook URLs:

#### Voice Application Webhooks
Navigate to your Voice Application settings and set:

- **Voice Webhook URL**: `https://abc123.ngrok.io/voice/route`
- **IVR Webhook URL**: `https://abc123.ngrok.io/voice/ivr-input`
- **Ring Group Callback**: `https://abc123.ngrok.io/callbacks/voice/ring-group-callback`

#### Status Webhooks
In your application settings, configure:

- **Call Status Webhook**: `https://abc123.ngrok.io/webhooks/cloudonix/call-status`
- **Session Update Webhook**: `https://abc123.ngrok.io/webhooks/cloudonix/session-update`
- **CDR Webhook**: `https://abc123.ngrok.io/webhooks/cloudonix/cdr`

### 4. Verify Configuration

Test your webhook URLs using the ngrok traffic inspector or by making a test API call:

```bash
# Test webhook endpoint (should return auth error, which is expected)
curl -X POST https://abc123.ngrok.io/webhooks/cloudonix/call-status \
  -H "Content-Type: application/json" \
  -d '{"test": "data"}'
```

You should see the request appear in the ngrok web interface.

## Testing Webhook Delivery

### 1. Monitor Webhook Traffic

Use the ngrok web interface to monitor incoming webhook requests:

1. Go to http://localhost:4040
2. Click on the tunnel URL
3. View the "Traffic" tab to see all HTTP requests

### 2. Check Application Logs

Monitor Laravel logs for webhook processing:

```bash
# View Laravel application logs
docker compose logs -f app

# Or check the log file directly
docker compose exec app tail -f storage/logs/laravel.log
```

**Expected log entries for successful webhooks:**
```
[2024-01-11 10:30:15] local.INFO: Processing call webhook: call_id=abc123
[2024-01-11 10:30:15] local.INFO: Webhook authenticated successfully
[2024-01-11 10:30:15] local.INFO: Call state transition: initiated -> ringing
```

### 3. Test with Real Calls

Make actual phone calls to your configured DID numbers:

1. Call your Cloudonix DID number
2. Watch webhook traffic in ngrok interface
3. Check Laravel logs for processing
4. Verify call appears in the OpBX Live Calls interface

### 4. Simulate Webhook Events (Development)

For testing without making real calls, you can simulate webhook events:

```bash
# Example: Simulate call initiated webhook
curl -X POST https://abc123.ngrok.io/webhooks/cloudonix/call-initiated \
  -H "Content-Type: application/json" \
  -H "X-Cloudonix-Signature: $(echo -n '{"call_id":"test-123","from":"+14155551234","to":"+14155559876"}' | openssl dgst -sha256 -hmac 'your_webhook_secret' -binary | base64)" \
  -d '{
    "call_id": "test-123",
    "from": "+14155551234",
    "to": "+14155559876",
    "did": "+14155559876",
    "direction": "inbound",
    "status": "initiated",
    "organization_id": 1
  }'
```

## ngrok Advanced Configuration

### Custom Subdomains

Reserve a custom subdomain for consistent URLs:

```bash
# In ngrok dashboard, reserve a subdomain like "opbx-dev"
# Then use it in docker-compose.yml
environment:
  - NGROK_AUTHTOKEN=${NGROK_AUTHTOKEN}
command: http nginx:80 --subdomain=opbx-dev
```

Your URL will then be: `https://opbx-dev.ngrok.io`

### Geographic Regions

Choose a specific ngrok region for better performance:

```yaml
# In docker-compose.yml
ngrok:
  environment:
    - NGROK_AUTHTOKEN=${NGROK_AUTHTOKEN}
    - NGROK_REGION=us
  command: http nginx:80
```

**Available regions:**
- `us` (United States)
- `eu` (Europe)
- `ap` (Asia Pacific)
- `au` (Australia)
- `sa` (South America)
- `jp` (Japan)
- `in` (India)

### Request Inspection

Use ngrok's request inspection features:

1. **Replay Requests**: Resend failed webhooks
2. **Modify Responses**: Test different response codes
3. **Request Details**: View headers, body, timing

### Rate Limiting

ngrok has rate limits depending on your plan:

- **Free**: 40 requests/minute, 4 concurrent connections
- **Paid**: Higher limits based on plan

Monitor your usage in the ngrok dashboard.

## Troubleshooting

### Common Issues

#### 1. ngrok Container Won't Start

**Symptoms:** `ngrok` container exits immediately

**Check:**
```bash
docker compose logs ngrok
```

**Common causes:**
- Invalid authtoken
- Network connectivity issues
- Port conflicts

**Solutions:**
```bash
# Verify authtoken
echo $NGROK_AUTHTOKEN

# Restart with fresh container
docker compose down ngrok
docker compose up -d ngrok
```

#### 2. Webhooks Not Reaching Application

**Symptoms:** Requests appear in ngrok but not processed by Laravel

**Check:**
```bash
# Check nginx container
docker compose logs nginx

# Check Laravel logs
docker compose logs app

# Test internal connectivity
docker compose exec nginx curl -I http://app/webhooks/cloudonix/call-initiated
```

**Possible causes:**
- Laravel application not running
- Route not registered
- Middleware blocking requests

#### 3. Authentication Failures

**Symptoms:** Webhooks rejected with 401/403 errors

**Check:**
```bash
# Verify webhook secret in .env
grep CLOUDONIX_WEBHOOK_SECRET .env

# Check Laravel logs for auth details
docker compose exec app tail -f storage/logs/laravel.log
```

**Common issues:**
- Incorrect webhook secret
- Signature calculation errors
- Timestamp outside tolerance window

#### 4. Tunnel Disconnects

**Symptoms:** ngrok tunnel goes offline

**Check:**
```bash
docker compose logs ngrok
docker compose ps ngrok
```

**Solutions:**
```bash
# Restart ngrok
docker compose restart ngrok

# Check network connectivity
ping 8.8.8.8

# Verify no firewall blocking
telnet tunnel.ngrok.com 443
```

#### 5. CORS Issues (Frontend Development)

**Symptoms:** Frontend can't connect to API

**Configuration:**
Update your `frontend/.env` or `vite.config.js`:

```javascript
// vite.config.js
export default defineConfig({
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost', // Use localhost, not ngrok URL
        changeOrigin: true,
      },
    },
  },
});
```

### Performance Optimization

#### Connection Pooling
ngrok maintains persistent connections. For high-volume testing:

```yaml
# In docker-compose.yml
ngrok:
  environment:
    - NGROK_AUTHTOKEN=${NGROK_AUTHTOKEN}
  command: http nginx:80 --log=stdout --log-format=json
```

#### Monitoring
Set up alerts for tunnel status:

```bash
# Check tunnel status programmatically
curl http://localhost:4040/api/tunnels
```

## Security Considerations

### Development Security

1. **Never expose production data** through ngrok tunnels
2. **Use HTTPS URLs only** for webhook configuration
3. **Monitor access logs** for unauthorized access attempts
4. **Rotate authtokens regularly**

### Production Alternatives

For production deployments, consider:
- **Static IP addresses** from your hosting provider
- **Load balancers** with webhook endpoints
- **API gateways** (AWS API Gateway, Cloudflare Workers)
- **Serverless webhook processors**

## ngrok Dashboard Features

### Usage Analytics
- View bandwidth usage
- Monitor request/response times
- Track geographic distribution of requests

### Reserved Domains
- Custom subdomains for consistent URLs
- Custom domains with SSL certificates
- IP whitelisting for enterprise accounts

### Teams and Collaboration
- Share tunnels with team members
- Access controls for shared accounts
- Audit logs for security compliance

This ngrok setup provides a robust development environment for testing Cloudonix webhooks locally, with comprehensive monitoring and troubleshooting capabilities.