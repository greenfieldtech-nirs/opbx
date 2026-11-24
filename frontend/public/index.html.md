# OPBX: AI Voice Agents Meet Open-Source PBX

Build, deploy, and manage your business phone system with AI-powered voice agents, smart routing, and real-time monitoring. Built on Cloudonix.

- [Try for free](/ui/register)
- [Install OPBX yourself](/install.md)
- [Source code on GitHub](https://github.com/greenfieldtech-nirs/OPBX)

## What is OPBX?

OPBX is an open-source business PBX platform that transforms how organizations handle voice communications. Built on the Cloudonix CPaaS, OPBX eliminates the complexities of managing VoIP infrastructure while giving you complete control over your phone system.

## Features

- **AI Voice Agents**: Cloud and OSS Dograh integration plus generic AI assistant support.
- **Auto Dialer**: Outbound campaign manager with distribution lists and scheduling.
- **Call Tracking** (in development): Campaign tracking, DNI snippets, and analytics.
- **AI Load Balancers**: Distribute inbound calls across AI assistants.
- **Smart Call Routing**: Route calls to extensions, ring groups, IVR, or AI assistants.
- **Ring Groups**: Simultaneous, round-robin, and weighted ringing strategies.
- **IVR Menus**: Interactive voice response with custom routing logic.
- **Business Hours**: Time-of-day, holiday, and custom schedule routing.
- **Real-Time Monitoring**: Live call dashboard with presence and session updates.
- **Call Recording**: Automatic recording with secure storage and compliance.
- **Trunk Management**: Create and manage inbound and outbound SIP trunks, with a built-in configuration cheatsheet.
- **Web Phone**: Browser-based softphone with dialer, call log, and supervisor coaching.
- **MCP Server for AI Agents** (in development): 112 tools let Claude, Cursor, and other AI agents manage the PBX via the Model Context Protocol.
- **Scoped API Keys**: Fine-grained, per-resource API keys for safe automation and integrations.
- **Enterprise Security**: RBAC, multi-tenant isolation, inbound blacklist, outbound whitelist, and audit logging.

## How it works

1. **Deploy & Configure**: Launch OPBX with docker-compose, create your organization, and set up users and extensions.
2. **Connect Cloudonix**: Link your Cloudonix account with API credentials and configure your DID numbers.
3. **Route Calls**: Your PBX is live! Monitor calls in real-time, adjust routing, and scale as you grow.

## Pricing

Start free. Choose self-hosted control or managed cloud convenience. Cloudonix usage is billed separately.

### Self-Hosted: $0 / month

MIT licensed. Run on your own infrastructure.

- Unlimited users & extensions
- AI voice agents
- Smart call routing
- Ring groups & IVR
- Call tracking & analytics
- Business hours routing
- Community support

### Cloud Service: $0 / month (limited offer)

Managed OPBX instance, no ops overhead.

- Hosted OPBX instance
- All self-hosted features included
- Automatic updates & backups
- Managed Cloudonix connectivity
- Priority community support
- No server maintenance

### Self Hosted FDE: contact us

Self hosted with Remote FDE support. [Contact sales](https://www.cloudonix.com/#/contact-sales).

- All self-hosted features included
- Priority enterprise support
- Enterprise assigned FDE

## Frequently asked questions

### What is OPBX?

OPBX is an open-source business PBX platform built on Cloudonix CPaaS. It handles call routing, IVR menus, ring groups, AI voice agents, auto dialer campaigns, call tracking, and business communications without requiring you to manage VoIP infrastructure.

### How does OPBX work with Cloudonix?

OPBX integrates with Cloudonix to handle all VoIP and telephony operations. Cloudonix manages the actual call infrastructure, while OPBX provides the configuration interface and runtime routing decisions.

### Is OPBX really free?

Yes! OPBX is fully open source under the MIT license. You can self-host it on your own infrastructure at no cost. You will need a Cloudonix account for telephony services, which has its own pricing structure.

### What features are included?

OPBX includes multi-tenant organizations, user extensions, DID number mapping, inbound call routing (direct, ring groups, IVR, AI assistants), business hours routing, call logs, real-time call presence, AI voice agents, auto dialer, call tracking, AI load balancers, and a complete admin UI.

### Can I use OPBX for outbound calling?

Yes. OPBX includes an Auto Dialer for outbound campaigns, distribution lists, and scheduling. Additional outbound features are planned for future releases.

### How do I get started?

Sign up for an account, configure your organization and extensions, connect your Cloudonix account with API credentials, map your DID numbers, and start routing calls. The entire setup takes just minutes.
