# OPBX Frontend

The **OPBX Frontend** is the React-based single-page application (SPA) that serves as the administrative interface for the OPBX open-source business PBX platform. It provides a modern, responsive web UI for managing phone systems, users, call routing, auto-dialer campaigns, real-time call monitoring, and platform-level administration.

## Overview

OPBX is a multi-tenant business PBX platform built on Laravel (backend) and React (frontend). This frontend application communicates with the Laravel API to provide a comprehensive web interface for PBX administrators and platform managers.

**Key Capabilities:**

- **User & Extension Management** — Manage SIP extensions, users, supervisors, ring groups and call queues
- **Phone Number Management** — Configure DID numbers and inbound routing
- **IVR & Call Flows** — Build interactive voice response menus and business hours
- **Auto-Dialer** — Create and monitor outbound campaigns with real-time statistics
- **Live Call Monitoring** — Real-time call presence via WebSocket (Soketi/Pusher)
- **Call Analytics** — Searchable call logs, recordings, and CDR data
- **AI Assistants** — Configure AI-powered voice assistants and load balancers, and watch them on the AI Real Time Monitor
- **Web Phone** — In-browser softphone (JsSIP over WebRTC) with transfer, recent calls and a Settings tab; the same component powers the embeddable dialer
- **Platform Administration** — Multi-tenant organization and user management

## Technology Stack

| Layer | Technology | Purpose |
|-------|-----------|---------|
| **Framework** | React 19 | UI library with concurrent features |
| **Language** | TypeScript | Type-safe development (strict mode: off) |
| **Build Tool** | Vite 8 | Fast dev server and optimized production builds |
| **Routing** | React Router 7 | Declarative routing with lazy loading |
| **Server State** | TanStack Query 5 | Data fetching, caching, and synchronization |
| **Client State** | React Context | Global state for Auth and Config |
| **Forms** | react-hook-form + Zod | Type-safe form handling with schema validation |
| **UI Components** | shadcn/ui | Accessible components built on Radix UI primitives |
| **Styling** | Tailwind CSS 4 | Utility-first CSS framework (`@tailwindcss/vite` plugin) |
| **HTTP Client** | Axios | API requests with interceptors for auth and error handling |
| **Real-time** | Laravel Echo + Pusher JS | WebSocket connections for live call updates |
| **Web Phone** | JsSIP | SIP over WebRTC for the browser phone |
| **Drag & Drop** | @dnd-kit | Sortable lists for IVR menus and call flows |
| **Icons** | Lucide React, VS Code Codicons | Iconography (sidebar uses Codicons) |
| **Testing** | Vitest + React Testing Library | Unit and component tests (`npm test`); Playwright is installed for browser automation |

## Directory Structure

```
frontend/
├── public/                    # Static assets
├── src/
│   ├── components/            # Reusable UI components
│   │   ├── ui/               # shadcn/ui components (Button, Dialog, Table, etc.)
│   │   ├── Layout/           # AppLayout, Sidebar, Header
│   │   ├── Auth/             # ProtectedRoute, OwnerRoute, SupervisorGuard
│   │   ├── WebPhone/         # Web Phone (keypad, recents, settings, transfer)
│   │   ├── platform/         # PlatformManagerRoute, PlatformLayout
│   │   └── ...               # Shared components
│   ├── embed/                # Embedded dialer entry point (built with vite.embed.config.ts)
│   ├── pages/                # Route-level page components
│   │   ├── Dashboard.tsx
│   │   ├── UsersComplete.tsx
│   │   ├── Extensions.tsx
│   │   ├── PhoneNumbers.tsx
│   │   ├── RingGroups.tsx
│   │   ├── CallQueues.tsx
│   │   ├── IVRMenus.tsx
│   │   ├── BusinessHours.tsx
│   │   ├── ConferenceRooms.tsx
│   │   ├── AiAssistants.tsx
│   │   ├── AiMonitor.tsx
│   │   ├── AutoDialerCampaigns.tsx
│   │   ├── AutoDialerMonitor.tsx
│   │   ├── CallLogs.tsx
│   │   ├── LiveCalls.tsx
│   │   ├── Settings.tsx
│   │   ├── platform/         # Platform management pages
│   │   └── ...
│   ├── services/             # API service layer
│   │   ├── api.ts            # Axios instance with interceptors
│   │   ├── auth.service.ts
│   │   ├── echo.service.ts   # WebSocket/Laravel Echo
│   │   └── ...               # Domain-specific API services
│   ├── hooks/                # Custom React hooks
│   ├── context/              # React Context providers
│   │   ├── AuthContext.tsx   # Authentication state
│   │   ├── OperateAsContext.tsx # Platform manager "operate as" an organization
│   │   └── ConfigContext.tsx # Application configuration
│   ├── lib/                  # queryClient, query keys and invalidation helpers
│   ├── types/                # TypeScript type definitions
│   ├── schemas/              # Zod validation schemas
│   ├── utils/                # Utility functions
│   ├── router.tsx            # Route definitions with lazy loading
│   ├── main.tsx              # Application entry point
│   └── index.css             # Global styles: Tailwind CSS 4 setup (@theme tokens, CSS variables)
├── .env.example              # Environment variable template
├── Dockerfile                # Production build: npm run build, served by nginx
├── Dockerfile.dev            # Development: Vite dev server with HMR
├── package.json
├── tsconfig.json             # TypeScript configuration (strict: false)
├── vite.config.ts            # Vite configuration with proxy and code splitting
├── vite.embed.config.ts      # Embedded dialer bundle
└── vitest.config.ts          # Vitest configuration
```

## Development Setup

### Prerequisites

- **Node.js** 26 (the Docker images use `node:26-alpine`) and npm
- Running **Laravel backend** on `http://localhost:8000` (or configured API URL)
- (Optional) **Soketi/WebSocket server** for real-time features

### Installation

```bash
cd frontend
npm install
```

### Environment Configuration

Copy `.env.example` to `.env` and adjust values:

```bash
cp .env.example .env
```

| Variable | Description | Default |
|----------|-------------|---------|
| `VITE_API_BASE_URL` | Laravel API base URL | `http://localhost:8000/api/v1` |
| `VITE_PUSHER_APP_KEY` | Pusher app key for WebSocket | `pbxappkey` |
| `VITE_PUSHER_APP_CLUSTER` | Pusher cluster | `mt1` |
| `VITE_WS_HOST` | WebSocket server host | `localhost` |
| `VITE_WS_PORT` | WebSocket server port | `6001` |
| `VITE_WS_SCHEME` | WebSocket scheme (`http` or `https`) | `http` |

### Running the Development Server

```bash
npm run dev
```

The Vite dev server starts on **port 3000** (configurable in `vite.config.ts`). It proxies `/api` requests to
`VITE_API_PROXY_TARGET` (default `http://localhost:8000`). It answers only to the host names in `VITE_ALLOWED_HOSTS`
plus `localhost`.

**Access the app:**
- Frontend: `http://localhost:3000`
- API proxy: `http://localhost:3000/api` → `http://localhost:8000/api`

### Docker Development

When using the project's `docker-compose.yml`, the frontend container runs automatically. No manual `.env` file is needed — variables are injected by Docker Compose (`VITE_API_BASE_URL=/api/v1`, `VITE_API_PROXY_TARGET=http://nginx`, and the WebSocket settings from the root `.env`). The container runs `npm ci` when `package.json` or `package-lock.json` changed.

```bash
./opbx.sh start
# Access at http://localhost (through nginx) or http://localhost:3000
```

In production (`docker-compose.prod.yml`) the frontend is built once by `frontend/Dockerfile` and served as static files; the `VITE_*` values are baked in at build time.

## Build Commands

| Command | Description |
|---------|-------------|
| `npm run dev` | Start Vite dev server on `:3000` with HMR |
| `npm run build` | Production build (TypeScript compile + Vite bundle) |
| `npm run build:embed` | Build the embedded dialer bundle into `../public/embed/assets/` |
| `npm test` | Run the Vitest unit tests |
| `npm run preview` | Preview the production build locally |
| `npm run lint` | Run ESLint (no warnings allowed) |
| `npm run type-check` | Run TypeScript compiler check only (`tsc --noEmit`) |

### Production Build

```bash
npm run build
```

Output is written to `frontend/dist/` with:
- Code splitting into vendor chunks (React, Query, UI)
- Source maps enabled
- Optimized and minified assets

## Architecture Overview

```mermaid
graph TB
    subgraph "Browser"
        A[React 19 SPA]
        B[TanStack Query]
        C[React Context]
        D[React Router]
        E[Laravel Echo]
    end

    subgraph "Frontend Services"
        F[Axios API Client]
        G[Auth Service]
        H[Echo Service]
        I[Domain Services]
    end

    subgraph "Backend"
        J[Laravel API]
        K[Soketi/WebSocket]
        L[MySQL + Redis]
    end

    A --> D
    A --> B
    A --> C
    A --> E
    B --> F
    C --> G
    E --> H
    F --> J
    G --> J
    I --> J
    H --> K
    J --> L
    K --> J
```

### Application Bootstrap Flow

```mermaid
sequenceDiagram
    participant Browser
    participant main.tsx
    participant AuthContext
    participant ConfigContext
    participant Router

    Browser->>main.tsx: Load SPA
    main.tsx->>main.tsx: Use shared QueryClient<br/>(lib/queryClient.ts)
    main.tsx->>AuthContext: Initialize auth state
    AuthContext->>AuthContext: Verify token via /auth/me
    main.tsx->>ConfigContext: Fetch app configuration
    ConfigContext->>ConfigContext: GET /config/application
    main.tsx->>Router: Render route tree
    Router->>Browser: Lazy-load page component
```

## State Management

### Server State: TanStack Query

All server data is managed via **TanStack Query** (`@tanstack/react-query`). The shared client lives in
`src/lib/queryClient.ts`:

```typescript
export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: (failureCount, error: AxiosError) => {
        // Don't retry on 401/403
        if (error.response?.status === 401 || error.response?.status === 403) {
          return false;
        }
        return failureCount < 1;
      },
      refetchOnWindowFocus: false,
      refetchIntervalInBackground: false,
      staleTime: 30_000,
    },
    mutations: { retry: false },
  },
});
```

Data stays fresh for 30 seconds and there is **no global invalidation**: every mutation invalidates the query keys it
affects (`src/lib/invalidation.ts`, `src/lib/queryKeys.ts`). A new mutation must do the same, or the UI shows stale
data for up to 30 seconds. Pages that poll opt in per query with `refetchInterval`; polling stops while the tab is
hidden.

### Client State: React Context

Two global contexts manage client-side state:

#### AuthContext
- Stores `user`, `token`, `isAuthenticated`
- Handles login, register, logout, and token verification
- Clears TanStack Query cache on auth state changes
- Keeps the token in `localStorage` with "Remember me", otherwise in `sessionStorage`

#### ConfigContext
- Stores `ApplicationConfig` (mode, webhooks, warnings)
- Provides `isProduction`, `shouldHideWebhookFields`, `isValidConfiguration`
- Falls back to development defaults on API error

### API Layer: Axios with Interceptors

The centralized `api.ts` client provides:

- **Auth header injection** — Bearer token from browser storage
- **Cache-busting headers** on GET requests (`Cache-Control: no-cache`)
- **Automatic 401 handling** — Clears storage and redirects to `/ui/login`
- **FormData support** — Removes `Content-Type` header for multipart uploads
- **Error message extraction** — Parses validation errors from API responses

## Key Features

### PBX Administration

| Feature | Page | Description |
|---------|------|-------------|
| **Dashboard** | `/ui/dashboard` | Overview with call statistics and system status |
| **Users** | `/ui/users` | Manage organization users and roles |
| **Extensions** | `/ui/extensions` | SIP extension configuration and status |
| **Phone Numbers** | `/ui/phone-numbers` | DID number management and routing |
| **Ring Groups** | `/ui/ring-groups` | Call distribution groups with strategies |
| **IVR Menus** | `/ui/ivr-menus` | Interactive voice response tree builder |
| **Business Hours** | `/ui/business-hours` | Time-based call routing rules |
| **Conference Rooms** | `/ui/conference-rooms` | Audio conference bridge management |

### Auto-Dialer

| Feature | Page | Description |
|---------|------|-------------|
| **Campaigns** | `/ui/auto-dialer/campaigns` | Create and manage outbound campaigns |
| **Campaign Detail** | `/ui/auto-dialer/campaigns/:id` | Campaign statistics and control |
| **Upload Lists** | `/ui/auto-dialer/campaigns/:id/upload` | Upload destination phone number lists |
| **Distribution Lists** | `/ui/auto-dialer/distribution-lists` | Reusable contact lists for campaigns |
| **Monitor** | `/ui/auto-dialer/monitor` | Real-time campaign progress dashboard |

### Call Management

| Feature | Page | Description |
|---------|------|-------------|
| **Live Calls** | `/ui/live-calls` | Real-time active call monitoring via WebSocket |
| **Call Logs** | `/ui/call-logs` | Searchable CDR with filters and playback |
| **Announcements** | `/ui/announcements` | Manage system audio announcements |
| **Call Notifications** | `/ui/call-notifications` | Configure call alert settings |
| **Call Queues** | `/ui/call-queues`, `/ui/call-queues/:id` | Queues, agents and the live queue view |
| **Queue Reports** | `/ui/queue-reports` | Queue statistics and call history |
| **Queues Dashboard** | `/ui/queues-dashboard` | Wallboard (also public at `/public/queues-dashboard/:token`) |

### AI & Advanced

| Feature | Page | Description |
|---------|------|-------------|
| **AI Assistants** | `/ui/ai-assistants` | Configure AI voice assistants |
| **AI Load Balancers** | `/ui/ai-assistant-load-balancers` | Distribute AI assistant traffic |
| **AI Real Time Monitor** | `/ui/ai-monitor` | Live figures per AI assistant and load balancer (owner, PBX admin) |
| **Outbound Whitelist** | `/ui/outbound-whitelist` | Restrict outbound dialing (owner, PBX admin) |
| **Trunks** | `/ui/trunks` | Cloudonix SIP trunks (owner, PBX admin) |
| **API Keys** | `/ui/api-keys` | Scoped API keys (owner only) |
| **Inbound Blacklist** | `/ui/inbound-blacklist` | Block unwanted incoming numbers |

### Platform Management (Platform Manager Role)

| Feature | Page | Description |
|---------|------|-------------|
| **Platform Dashboard** | `/ui/platform/dashboard` | Cross-tenant system overview |
| **Organizations** | `/ui/platform/organizations` | Manage tenant organizations |
| **Platform Users** | `/ui/platform/users` | Cross-organization user management |
| **Audit Log** | `/ui/platform/audit-log` | System-wide activity logging |
| **Platform Status** | `/ui/platform/status` | Component health with 24-hour history |

### Route Guards

- **ProtectedRoute** — Requires authentication for all `/ui/*` routes
- **OwnerRoute** — Restricts a page to the roles it lists, owners by default (Settings, API Keys; Outbound Whitelist and Trunks also allow PBX Admin)
- **SupervisorGuard** — Limits pages for the Supervisor role
- **PlatformManagerRoute** — Restricts platform admin pages to platform managers

## Real-Time Features (WebSocket)

The frontend connects to a **Soketi** WebSocket server via **Laravel Echo** for live call presence updates:

**Events subscribed per organization:**
- `call.initiated` — New inbound/outbound call started
- `call.answered` — Call picked up by an extension
- `call.ended` — Call terminated with duration
- Presence channel — Active admin users viewing the dashboard

**Connection features:**
- Automatic reconnection with exponential backoff (max 5 retries)
- Connection state tracking (`disconnected` → `connecting` → `connected`)
- Auth via Bearer token on the `/broadcasting/auth` endpoint

## Code Conventions

- **Components**: `PascalCase` filenames (e.g., `BusinessHoursForm.tsx`)
- **Hooks**: `camelCase` starting with `use` (e.g., `useAuth()`)
- **Types/Interfaces**: `PascalCase` (e.g., `BusinessHoursConfig`)
- **Imports**: `@/` path alias for `src/` directory
- **Functional components only** — No class components
- **2-space indentation** for all TypeScript/TSX files

## Environment Variables Reference

| Variable | Required | Description |
|----------|----------|-------------|
| `VITE_API_BASE_URL` | Yes | Laravel API base URL (e.g., `http://localhost:8000/api/v1`, or `/api/v1` behind nginx) |
| `VITE_PUSHER_APP_KEY` | No | Pusher app key for Laravel Echo |
| `VITE_PUSHER_APP_CLUSTER` | No | Pusher cluster identifier |
| `VITE_WS_HOST` | No | WebSocket server hostname (in development, `localhost` follows the page's host name) |
| `VITE_WS_PORT` | No | WebSocket server port |
| `VITE_WS_SCHEME` | No | `http` or `https` |
| `VITE_API_PROXY_TARGET` | No | Proxy target for dev server (default: `http://localhost:8000`) |
| `VITE_ALLOWED_HOSTS` | No | Comma-separated list of allowed hosts for Vite dev server |

## Related Documentation

- [OPBX Backend Documentation](../README.md) — Laravel API and architecture
- [Cloudonix Docs](https://developers.cloudonix.com/) — CPaaS platform documentation
- [OPBX REST API](https://developers.cloudonix.com/opbxRestOpenAPI) — API endpoint reference
- [Laravel Docs](https://laravel.com/docs/13.x) — Backend framework documentation
- [shadcn/ui](https://ui.shadcn.com/) — UI component library documentation

## License

This project is open-source. See the root repository for license details.
