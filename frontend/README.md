# OPBX Frontend - React Admin Interface

Modern, production-ready React SPA for the OPBX business PBX administration interface.

## Features

- **Modern Stack**: React 18, TypeScript, Vite, Tailwind CSS
- **State Management**: TanStack Query (React Query) for server state
- **UI Components**: shadcn/ui (Radix UI + Tailwind)
- **Real-time Updates**: WebSocket integration for live call presence
- **Authentication**: JWT token-based auth with Laravel Sanctum
- **Form Handling**: React Hook Form with Zod validation
- **Type Safety**: Strict TypeScript with comprehensive type definitions
- **Responsive Design**: Mobile-first, fully responsive layouts
- **Code Splitting**: Lazy-loaded routes for optimal performance
- **Production Ready**: Docker containerization, nginx serving, optimized builds

## Technology Stack

### Core
- **React 18.3** - UI library with hooks
- **TypeScript 5.6** - Type safety and developer experience
- **Vite 5.4** - Fast build tool and dev server
- **React Router v6** - Client-side routing

### State & Data
- **TanStack Query 5.x** - Server state management and caching
- **Axios 1.7** - HTTP client with interceptors
- **WebSocket API** - Native WebSocket for real-time updates

### UI & Styling
- **Tailwind CSS 3.4** - Utility-first CSS framework
- **shadcn/ui** - High-quality React components (Radix UI)
- **Lucide React** - Beautiful icon set
- **Sonner** - Toast notifications

### Forms & Validation
- **React Hook Form 7.x** - Performant form management
- **Zod 3.x** - TypeScript-first schema validation

## Project Structure

```
frontend/
├── src/
│   ├── components/          # React components
│   │   ├── Layout/          # Layout components (Sidebar, Header, AppLayout)
│   │   ├── Auth/            # Authentication components
│   │   ├── Dashboard/       # Dashboard components
│   │   ├── Users/           # User management components
│   │   ├── DIDs/            # DID management components
│   │   ├── RingGroups/      # Ring group components
│   │   ├── BusinessHours/   # Business hours components
│   │   ├── CallLogs/        # Call logs components
│   │   ├── LiveCalls/       # Live calls components
│   │   └── ui/              # Reusable UI components (shadcn/ui)
│   ├── pages/               # Page components (routes)
│   │   ├── Login.tsx
│   │   ├── Dashboard.tsx
│   │   ├── Users.tsx
│   │   ├── Extensions.tsx
│   │   ├── DIDs.tsx
│   │   ├── RingGroups.tsx
│   │   ├── BusinessHours.tsx
│   │   ├── CallLogs.tsx
│   │   └── LiveCalls.tsx
│   ├── services/            # API service layer
│   │   ├── api.ts           # Axios instance with interceptors
│   │   ├── auth.service.ts
│   │   ├── users.service.ts
│   │   ├── extensions.service.ts
│   │   ├── dids.service.ts
│   │   ├── ringGroups.service.ts
│   │   ├── businessHours.service.ts
│   │   ├── callLogs.service.ts
│   │   └── websocket.service.ts
│   ├── hooks/               # Custom React hooks
│   │   ├── useAuth.ts
│   │   └── useWebSocket.ts
│   ├── context/             # React contexts
│   │   └── AuthContext.tsx
│   ├── types/               # TypeScript type definitions
│   │   └── api.types.ts     # API response types
│   ├── utils/               # Utility functions
│   │   ├── storage.ts       # LocalStorage helpers
│   │   └── formatters.ts    # Formatting utilities
│   ├── lib/                 # Third-party library configs
│   │   └── utils.ts         # cn() helper for Tailwind
│   ├── App.tsx              # Root component
│   ├── main.tsx             # Application entry point
│   ├── router.tsx           # Route definitions
│   └── index.css            # Global styles (Tailwind)
├── public/                  # Static assets
├── docker/                  # Docker configs
│   └── nginx.conf          # nginx configuration
├── index.html              # HTML entry point
├── package.json            # Dependencies
├── tsconfig.json           # TypeScript config
├── vite.config.ts          # Vite config
├── tailwind.config.js      # Tailwind config
├── Dockerfile              # Docker build instructions
└── README.md               # This file
```

## Getting Started

### Prerequisites

- Node.js 18+ and npm
- Backend API running on `http://localhost:8000`

### Installation

1. **Clone and navigate to frontend directory:**

```bash
cd frontend
```

2. **Install dependencies:**

```bash
npm install
```

3. **Configure environment:**

```bash
cp .env.example .env
```

Edit `.env` and set:

```env
VITE_API_BASE_URL=http://localhost:8000/api
VITE_WS_URL=ws://localhost:6001
```

### Development

**Start development server:**

```bash
npm run dev
```

The app will be available at `http://localhost:3000`

**Type checking:**

```bash
npm run type-check
```

**Linting:**

```bash
npm run lint
```

### Building for Production

**Create optimized production build:**

```bash
npm run build
```

Build output will be in `dist/` directory.

**Preview production build:**

```bash
npm run preview
```

## Docker Deployment

### Build Docker Image

```bash
docker build -t opbx-frontend .
```

### Run Container

```bash
docker run -d \
  -p 3000:80 \
  --name opbx-frontend \
  opbx-frontend
```

### Using Docker Compose

Add to main `docker-compose.yml`:

```yaml
services:
  frontend:
    build:
      context: ./frontend
      dockerfile: Dockerfile
    ports:
      - "3000:80"
    depends_on:
      - app
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "wget", "--no-verbose", "--tries=1", "--spider", "http://localhost/health"]
      interval: 30s
      timeout: 3s
      retries: 3
```

Then run:

```bash
docker-compose up -d frontend
```

## API Integration

### Authentication Flow

1. User submits login credentials
2. Frontend sends POST to `/api/auth/login`
3. Backend returns JWT token and user object
4. Token stored in localStorage
5. Axios interceptor adds token to all requests
6. Protected routes check authentication state

### API Services

All API calls are made through service modules in `src/services/`:

```typescript
// Example: Fetch users
import { usersService } from '@/services/users.service';

const users = await usersService.getAll({ page: 1, per_page: 20 });
```

### Real-time WebSocket

WebSocket connection is established automatically when authenticated:

```typescript
// Subscribe to call events
useWebSocket('call.initiated', (data) => {
  console.log('New call:', data.call);
});
```

## Key Features Explained

### 1. Type-Safe API Integration

All API responses are fully typed:

```typescript
import type { User, PaginatedResponse } from '@/types/api.types';

const response: PaginatedResponse<User> = await usersService.getAll();
```

### 2. Automatic Token Management

Axios interceptor automatically:
- Adds Bearer token to requests
- Handles 401 errors (redirects to login)
- Provides error messages

### 3. Real-time Call Presence

Live Calls page shows active calls with:
- WebSocket updates for call events
- Automatic duration counter
- Visual status indicators
- Fallback polling (5s) if WebSocket fails

### 4. Optimized Performance

- **Code splitting**: Routes lazy-loaded
- **React Query caching**: 5-minute stale time
- **Optimistic updates**: Instant UI feedback
- **Memoization**: Prevents unnecessary re-renders

### 5. Role-Based Access Control

Navigation automatically filters based on user role:

```typescript
// Only owners and admins see Users page
{ name: 'Users', href: '/users', roles: ['owner', 'admin'] }
```

### 6. Responsive Design

All pages are fully responsive:
- Mobile-first approach
- Breakpoints: sm (640px), md (768px), lg (1024px), xl (1280px)
- Touch-friendly UI elements

## Environment Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `VITE_API_BASE_URL` | Backend API base URL | `http://localhost:8000/api` |
| `VITE_WS_URL` | WebSocket server URL | `ws://localhost:6001` |
| `VITE_APP_NAME` | Application name | `OPBX Admin` |

## Pages Overview

### Dashboard (`/dashboard`)
- Active calls count
- Total extensions and DIDs
- Calls today statistics
- Recent call activity

### Users (`/users`)
- User list with pagination
- Search by name/email
- Create/edit/delete users
- Role and status management

### Extensions (`/extensions`)
- Extension cards grid view
- Extension status indicators
- SIP configuration
- Voicemail settings

### Phone Numbers (`/dids`)
- DID list with routing info
- Routing type configuration
- Direct extension routing
- Ring group routing
- Business hours routing

### Ring Groups (`/ring-groups`)
- Ring group cards
- Strategy selection (simultaneous, round-robin, sequential)
- Member management
- Timeout and fallback config

### Business Hours (`/business-hours`)
- Schedule creation
- Weekly hours configuration
- Holiday management
- Open/closed routing

### Call Logs (`/call-logs`)
- Full call history
- Advanced filters (date range, status, DID)
- Export to CSV
- Pagination (50 per page)

### Live Calls (`/live-calls`)
- Real-time active call list
- WebSocket updates
- Call duration counter
- Caller ID and destination info

## Development Guidelines

### Adding a New Page

1. Create page component in `src/pages/`
2. Add route in `src/router.tsx`
3. Add navigation item in `src/components/Layout/Sidebar.tsx`
4. Create service methods if needed
5. Add TypeScript types in `src/types/api.types.ts`

### Adding a New API Service

1. Create service file in `src/services/`
2. Import `api` instance from `@/services/api`
3. Define service methods with typed responses
4. Export service object

### Using React Query

```typescript
// Fetch data
const { data, isLoading, error } = useQuery({
  queryKey: ['users', page],
  queryFn: () => usersService.getAll({ page }),
});

// Mutate data
const mutation = useMutation({
  mutationFn: (data) => usersService.create(data),
  onSuccess: () => {
    queryClient.invalidateQueries({ queryKey: ['users'] });
  },
});
```

## Troubleshooting

### API Connection Issues

If frontend can't connect to backend:

1. Check `.env` has correct `VITE_API_BASE_URL`
2. Ensure backend is running
3. Check browser console for CORS errors
4. Verify Vite proxy config in `vite.config.ts`

### WebSocket Not Connecting

1. Check `VITE_WS_URL` in `.env`
2. Ensure Laravel WebSocket server is running
3. Check browser console for WebSocket errors
4. Verify auth token is valid

### Build Errors

1. Clear node_modules and reinstall:
   ```bash
   rm -rf node_modules package-lock.json
   npm install
   ```

2. Check TypeScript errors:
   ```bash
   npm run type-check
   ```

## Performance Optimization

### Production Build Analysis

Analyze bundle size:

```bash
npm run build
```

Check `dist/` folder size and chunking in build output.

### Lazy Loading

All pages are lazy-loaded:

```typescript
const Users = lazy(() => import('@/pages/Users'));
```

### React Query Caching

Configured for optimal performance:
- 5-minute stale time
- 1 retry on failure
- No refetch on window focus

## Security Considerations

1. **Token Storage**: Tokens stored in localStorage (consider httpOnly cookies for enhanced security)
2. **XSS Protection**: All user input sanitized by React
3. **CORS**: Backend must allow frontend origin
4. **HTTPS**: Use HTTPS in production
5. **Content Security Policy**: Configure CSP headers in nginx

## Contributing

1. Follow existing code style
2. Use TypeScript strict mode
3. Add types for all props and API responses
4. Use existing UI components from `src/components/ui/`
5. Test responsiveness on mobile/tablet/desktop

## License

This project is part of the OPBX open-source PBX system.

## Support

For issues and questions:
- Check backend API documentation
- Review Cloudonix developer docs: https://developers.cloudonix.com/
- Inspect browser console for errors
- Check network tab for failed API requests
