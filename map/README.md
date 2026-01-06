# OpBX Source Code Map

## Project Overview

OpBX is a containerized business PBX application built on top of Cloudonix CPaaS platform. It provides inbound call routing with multi-tenant support, real-time call presence, and comprehensive PBX configuration management.

### Technology Stack
- **Backend**: Laravel (PHP) with MySQL + Redis
- **Frontend**: React SPA with TypeScript
- **Real-time**: WebSockets/SSE for call presence
- **Infrastructure**: Docker with nginx, queue workers, and schedulers

## Architecture Overview

### Class Diagram
The project includes a comprehensive class diagram that illustrates all relationships between controllers, models, services, traits, and other components. View the diagram at:
- **[class-diagram.md](class-diagram.md)** - Full documentation with explanations
- **[class-diagram.mmd](class-diagram.mmd)** - Pure Mermaid syntax for rendering

The diagram shows:
- **Multi-tenant model relationships** with organization scoping
- **Controller inheritance hierarchy** and trait usage
- **Service layer dependencies** and strategy patterns
- **Database relationships** between all models
- **Execution vs Control plane separation**

### Core Architecture Principles
- **Multi-tenant**: Organization-scoped isolation with RBAC
- **Dual Planes**: Control Plane (CRUD/config) vs Execution Plane (real-time routing)
- **State Management**: MySQL for durable data, Redis for ephemeral state
- **Cloudonix Integration**: REST API for configuration, webhooks for call events

## Directory Structure

```
map/
├── README.md                    # This file - project overview
├── class-diagram.md             # Full class diagram with Mermaid syntax
├── class-diagram.mmd            # Pure Mermaid diagram for rendering
├── backend/                     # Laravel backend documentation
│   ├── controllers.md          # All controllers and their functions
│   ├── models.md               # Models, relationships, and database schema
│   ├── services.md             # Business logic services and infrastructure
│   ├── routes.md               # API endpoints and webhook routes
│   └── architecture.md         # Backend patterns and principles
├── frontend/                    # React frontend documentation
│   ├── pages.md                # Application pages and routing
│   ├── components.md           # Component architecture and patterns
│   ├── services.md             # API integration and real-time services
│   └── architecture.md         # Frontend patterns and best practices
├── data-flow.md                # End-to-end data flow diagrams
└── integration-points.md       # Backend ↔ Frontend integration
```
map/
├── README.md                    # This file - project overview
├── backend/                     # Laravel backend documentation
│   ├── controllers.md          # All controllers and functions
│   ├── models.md               # Models and relationships
│   ├── services.md             # Business logic services
│   ├── routes.md               # API and webhook routes
│   └── architecture.md         # Backend patterns
├── frontend/                    # React frontend documentation
│   ├── pages.md                # Application pages
│   ├── components.md           # Component architecture
│   ├── services.md             # API integration
│   └── architecture.md         # Frontend patterns
├── data-flow.md                # End-to-end data flows
└── integration-points.md       # Backend ↔ Frontend integration
```

## Key Features

### PBX Functionality
- **Multi-tenant Organizations**: Complete tenant isolation
- **Users & Extensions**: SIP endpoint management with Cloudonix sync
- **DID Management**: Phone number assignment and routing
- **Ring Groups**: Call distribution (simultaneous, round-robin)
- **Business Hours**: Time-based routing with schedules
- **IVR Menus**: Interactive voice response
- **Conference Rooms**: Meeting room management
- **Call Logs**: Historical call records and CDR data

### Real-time Features
- **Live Call Presence**: Real-time active call monitoring
- **WebSocket Updates**: Live call state changes
- **Call Recordings**: Audio recording management

## Quick Navigation

### Finding Specific Functionality
- **User Management**: See `frontend/pages.md` → Users page, `backend/controllers.md` → UsersController
- **Call Routing**: See `backend/services.md` → CallRoutingService, `backend/controllers.md` → VoiceRoutingController
- **Real-time Updates**: See `frontend/services.md` → websocket.service, `data-flow.md` → Real-time Flow
- **Database Schema**: See `backend/models.md` → Models & Relationships

### Understanding Data Flow
- **Configuration Changes**: Frontend → API → Database (see `data-flow.md`)
- **Incoming Calls**: Cloudonix Webhook → Routing Logic → CXML Response (see `backend/routes.md`)
- **Live Updates**: Database Changes → WebSocket → UI Updates (see `integration-points.md`)

## Architecture Highlights

### Control vs Execution Plane Separation
- **Control Plane**: CRUD APIs for PBX configuration (MySQL primary)
- **Execution Plane**: Real-time webhook processing (Redis for state, minimal DB reads)

### State Management Strategy
- **MySQL**: Organizations, users, extensions, routing config, call logs
- **Redis**: Call state, idempotency keys, distributed locks, presence data
- **WebSocket**: Real-time UI updates for call presence

### Security & Multi-tenancy
- Organization-scoped queries with global scopes
- RBAC: Owner (full access), Admin (user management), Agent (basic features), User (read-only)
- Dual authentication: Bearer tokens (voice routing), Sanctum tokens (API)

This map serves as the authoritative guide to the OpBX codebase. Use it to quickly understand how features are implemented and where to find specific functionality.