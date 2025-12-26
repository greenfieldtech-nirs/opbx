# OPBX Frontend Implementation Guide

## Overview

This document provides a complete guide to the OPBX frontend React application, including architecture, components, and implementation details.

## Project Structure

```
frontend/
├── src/
│   ├── components/           # React components
│   │   ├── ui/              # shadcn/ui base components
│   │   │   ├── button.tsx
│   │   │   ├── card.tsx
│   │   │   ├── dialog.tsx
│   │   │   ├── input.tsx
│   │   │   ├── label.tsx
│   │   │   ├── select.tsx
│   │   │   ├── switch.tsx
│   │   │   ├── textarea.tsx
│   │   │   ├── badge.tsx
│   │   │   ├── skeleton.tsx
│   │   │   └── toaster.tsx
│   │   ├── Layout/          # Layout components
│   │   │   ├── AppLayout.tsx      # Main app shell
│   │   │   ├── Header.tsx         # Top navigation bar
│   │   │   └── Sidebar.tsx        # Side navigation
│   │   ├── Users/           # User management components
│   │   │   ├── UserForm.tsx       # User create/edit form
│   │   │   └── ExtensionForm.tsx  # Extension form
│   │   ├── DIDs/            # Phone number components
│   │   │   └── DIDForm.tsx        # DID routing form
│   │   ├── RingGroups/      # Ring group components
│   │   │   └── RingGroupForm.tsx  # Ring group form
│   │   ├── BusinessHours/   # Business hours components
│   │   │   └── BusinessHoursForm.tsx  # Schedule builder
│   │   └── LiveCalls/       # Live calls components
│   │       ├── LiveCallCard.tsx   # Individual call display
│   │       └── LiveCallList.tsx   # Active calls list
│   ├── pages/               # Page components (routes)
│   │   ├── Login.tsx
│   │   ├── Dashboard.tsx
│   │   ├── Users.tsx
│   │   ├── UsersEnhanced.tsx      # Example with dialogs
│   │   ├── Extensions.tsx
│   │   ├── DIDs.tsx
│   │   ├── RingGroups.tsx
│   │   ├── BusinessHours.tsx
│   │   ├── CallLogs.tsx
│   │   └── LiveCalls.tsx
│   ├── services/            # API service layer
│   │   ├── api.ts                 # Axios instance
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
│   ├── types/               # TypeScript definitions
│   │   └── api.types.ts
│   ├── utils/               # Utility functions
│   │   ├── storage.ts
│   │   └── formatters.ts
│   ├── lib/                 # Library configs
│   │   └── utils.ts              # cn() helper
│   ├── App.tsx              # Root component
│   ├── main.tsx             # Entry point
│   ├── router.tsx           # Route definitions
│   └── index.css            # Global styles
├── public/                  # Static assets
├── docker/                  # Docker configs
│   └── nginx.conf
├── index.html
├── package.json
├── tsconfig.json
├── vite.config.ts
├── tailwind.config.js
├── setup.sh                 # Installation script
├── Dockerfile
└── README.md
```

## Technology Stack

### Core
- **React 18.3** - UI library with hooks and concurrent features
- **TypeScript 5.6** - Type safety and enhanced developer experience
- **Vite 5.4** - Lightning-fast build tool and dev server
- **React Router v6** - Client-side routing with data loading

### State Management
- **TanStack Query (React Query) 5.x** - Server state management
  - Automatic caching and background refetching
  - Optimistic updates
  - Request deduplication
  - Pagination support

### UI Components
- **shadcn/ui** - High-quality component system built on:
  - **Radix UI** - Unstyled, accessible component primitives
  - **Tailwind CSS 3.4** - Utility-first CSS framework
- **Lucide React** - Beautiful, consistent icon set
- **Sonner** - Toast notification system

### Forms & Validation
- **React Hook Form 7.x** - Performant, flexible form library
- **Zod 3.x** - TypeScript-first schema validation
- **@hookform/resolvers** - React Hook Form + Zod integration

### HTTP & Real-time
- **Axios 1.7** - Promise-based HTTP client with interceptors
- **Native WebSocket API** - Real-time bidirectional communication

### Utilities
- **date-fns 4.x** - Modern date utility library
- **clsx** - Conditional className construction
- **tailwind-merge** - Merge Tailwind classes without conflicts
- **class-variance-authority** - Type-safe variant styling

## Installation

### Quick Start

```bash
# Navigate to frontend directory
cd frontend

# Run setup script (installs dependencies and creates .env)
chmod +x setup.sh
./setup.sh

# Or install manually
npm install
cp .env.example .env
```

### Configure Environment

Edit `.env`:

```env
VITE_API_BASE_URL=http://localhost:8000/api
VITE_WS_URL=ws://localhost:6001
VITE_APP_NAME=OPBX Admin
```

### Start Development Server

```bash
npm run dev
```

Access at: http://localhost:3000

## Architecture Patterns

### 1. Component Architecture

#### Atomic Design Principles

- **Atoms**: Base UI components (`Button`, `Input`, `Label`, etc.)
- **Molecules**: Form components (`UserForm`, `DIDForm`, etc.)
- **Organisms**: Feature components (`LiveCallList`, `Sidebar`, etc.)
- **Templates**: Layout components (`AppLayout`)
- **Pages**: Route-level components (`Dashboard`, `Users`, etc.)

#### Component Organization

```typescript
// Page Component Pattern
export default function Users() {
  // 1. State management
  const [page, setPage] = useState(1);
  const [searchQuery, setSearchQuery] = useState('');

  // 2. Data fetching with React Query
  const { data, isLoading } = useQuery({
    queryKey: ['users', page, searchQuery],
    queryFn: () => usersService.getAll({ page, per_page: 20 }),
  });

  // 3. Mutations for updates
  const createMutation = useMutation({
    mutationFn: usersService.create,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
      toast.success('User created');
    },
  });

  // 4. Render with loading/error states
  if (isLoading) return <LoadingState />;

  return (
    <div className="space-y-6">
      {/* Page content */}
    </div>
  );
}
```

### 2. Form Pattern

All forms use React Hook Form + Zod validation:

```typescript
// Form Component Pattern
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';

// 1. Define validation schema
const userSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  email: z.string().email('Invalid email'),
  role: z.enum(['owner', 'admin', 'agent']),
});

type UserFormData = z.infer<typeof userSchema>;

// 2. Create form component
export function UserForm({ user, onSubmit, onCancel, isLoading }: Props) {
  const {
    register,
    handleSubmit,
    setValue,
    watch,
    formState: { errors },
  } = useForm<UserFormData>({
    resolver: zodResolver(userSchema),
    defaultValues: user || {},
  });

  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      {/* Form fields */}
    </form>
  );
}
```

### 3. API Service Pattern

Centralized API services with TypeScript types:

```typescript
// services/users.service.ts
import api from './api';
import type { User, PaginatedResponse, CreateUserRequest } from '@/types/api.types';

export const usersService = {
  // GET /api/users
  getAll: (params?: { page?: number; per_page?: number; search?: string }) =>
    api.get<PaginatedResponse<User>>('/users', { params }).then(res => res.data),

  // GET /api/users/:id
  getById: (id: string) =>
    api.get<User>(`/users/${id}`).then(res => res.data),

  // POST /api/users
  create: (data: CreateUserRequest) =>
    api.post<User>('/users', data).then(res => res.data),

  // PATCH /api/users/:id
  update: (id: string, data: UpdateUserRequest) =>
    api.patch<User>(`/users/${id}`, data).then(res => res.data),

  // DELETE /api/users/:id
  delete: (id: string) =>
    api.delete(`/users/${id}`).then(res => res.data),
};
```

### 4. Real-time WebSocket Pattern

```typescript
// hooks/useWebSocket.ts
export function useWebSocket() {
  const { user } = useAuth();
  const [ws, setWs] = useState<WebSocket | null>(null);
  const [isConnected, setIsConnected] = useState(false);

  useEffect(() => {
    if (!user) return;

    const socket = new WebSocket(`${WS_URL}?token=${user.token}`);

    socket.onopen = () => setIsConnected(true);
    socket.onclose = () => setIsConnected(false);
    socket.onmessage = (event) => {
      const message = JSON.parse(event.data);
      // Dispatch to subscribers
    };

    setWs(socket);
    return () => socket.close();
  }, [user]);

  const subscribe = (event: string, callback: (data: any) => void) => {
    // Subscribe logic
  };

  return { subscribe, isConnected };
}
```

### 5. Authentication Flow

```typescript
// context/AuthContext.tsx
export function AuthProvider({ children }: { children: React.ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState<string | null>(storage.getToken());

  useEffect(() => {
    if (token) {
      // Fetch current user
      authService.me().then(setUser).catch(() => {
        storage.clearAll();
        setToken(null);
      });
    }
  }, [token]);

  const login = async (email: string, password: string) => {
    const response = await authService.login({ email, password });
    storage.setToken(response.token);
    storage.setUser(response.user);
    setToken(response.token);
    setUser(response.user);
  };

  const logout = () => {
    storage.clearAll();
    setToken(null);
    setUser(null);
  };

  return (
    <AuthContext.Provider value={{ user, token, login, logout, isAuthenticated: !!token }}>
      {children}
    </AuthContext.Provider>
  );
}
```

## Key Features Implementation

### 1. User Management

**Components:**
- `UserForm`: Create/edit users with role and status
- `ExtensionForm`: Configure user extensions

**Features:**
- Full CRUD operations
- Role-based access control
- Auto-create extension on user creation
- Password change (edit mode: optional)
- Search and pagination

**Usage:**
```typescript
<Dialog open={isCreateDialogOpen}>
  <DialogContent>
    <UserForm
      onSubmit={handleCreate}
      onCancel={() => setIsCreateDialogOpen(false)}
      isLoading={createMutation.isPending}
    />
  </DialogContent>
</Dialog>
```

### 2. DID (Phone Number) Management

**Components:**
- `DIDForm`: Configure phone number routing

**Routing Types:**
- Direct to extension
- Ring group
- Business hours routing
- Voicemail

**Features:**
- Dynamic routing configuration based on type
- Country code selection
- Status toggle (active/inactive)

### 3. Ring Groups

**Components:**
- `RingGroupForm`: Create ring groups with member selection

**Strategies:**
- **Simultaneous**: Ring all members at once
- **Round Robin**: Distribute calls evenly
- **Sequential**: Ring members one by one

**Features:**
- Drag-and-drop member ordering
- Timeout configuration
- Fallback actions (voicemail, busy, extension)

### 4. Business Hours

**Components:**
- `BusinessHoursForm`: Configure operating hours

**Features:**
- Weekly schedule builder
- Timezone selection
- Holiday management
- Separate routing for open/closed hours
- Time range validation

### 5. Live Call Presence

**Components:**
- `LiveCallList`: Display active calls
- `LiveCallCard`: Individual call information

**Features:**
- Real-time WebSocket updates
- Live duration counter
- Call status indicators
- Caller ID display
- Extension routing information

**WebSocket Events:**
```typescript
subscribe<CallPresenceUpdate>('call.presence', (update) => {
  if (update.event === 'call.initiated') {
    // Add call to list
  } else if (update.event === 'call.ended') {
    // Remove call from list
  }
});
```

### 6. Call Logs

**Features:**
- Full call history
- Advanced filtering:
  - Date range picker
  - Status filter
  - DID filter
  - Extension filter
- Pagination (50 per page)
- Export to CSV (future)
- Call detail view

## Styling System

### Tailwind CSS Configuration

```javascript
// tailwind.config.js
module.exports = {
  content: ['./index.html', './src/**/*.{js,ts,jsx,tsx}'],
  theme: {
    extend: {
      colors: {
        border: 'hsl(var(--border))',
        input: 'hsl(var(--input))',
        ring: 'hsl(var(--ring))',
        background: 'hsl(var(--background))',
        foreground: 'hsl(var(--foreground))',
        primary: {
          DEFAULT: 'hsl(var(--primary))',
          foreground: 'hsl(var(--primary-foreground))',
        },
        // ... more colors
      },
    },
  },
  plugins: [require('@tailwindcss/forms')],
};
```

### Component Styling Pattern

```typescript
// Using cn() utility to merge Tailwind classes
import { cn } from '@/lib/utils';

<Button
  className={cn(
    'px-4 py-2',
    variant === 'primary' && 'bg-blue-600 text-white',
    isDisabled && 'opacity-50 cursor-not-allowed'
  )}
>
  Click me
</Button>
```

### Responsive Design

```typescript
// Mobile-first responsive classes
<div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
  {/* Breakpoints: sm(640px), md(768px), lg(1024px), xl(1280px) */}
</div>
```

## Testing Strategy

### Unit Testing (Recommended)

```bash
npm install -D vitest @testing-library/react @testing-library/jest-dom
```

```typescript
// Example: UserForm.test.tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { UserForm } from './UserForm';

describe('UserForm', () => {
  it('validates required fields', async () => {
    const onSubmit = vi.fn();
    render(<UserForm onSubmit={onSubmit} onCancel={() => {}} />);

    const submitButton = screen.getByText('Create User');
    fireEvent.click(submitButton);

    expect(await screen.findByText('Name must be at least 2 characters')).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });
});
```

## Performance Optimization

### 1. Code Splitting

```typescript
// router.tsx
import { lazy, Suspense } from 'react';

const Users = lazy(() => import('./pages/Users'));
const Dashboard = lazy(() => import('./pages/Dashboard'));

// Wrap in Suspense
<Route path="/users" element={
  <Suspense fallback={<LoadingSpinner />}>
    <Users />
  </Suspense>
} />
```

### 2. React Query Caching

```typescript
// Query client configuration
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000, // 5 minutes
      cacheTime: 10 * 60 * 1000, // 10 minutes
      retry: 1,
      refetchOnWindowFocus: false,
    },
  },
});
```

### 3. Memoization

```typescript
import { useMemo, useCallback } from 'react';

// Memoize expensive calculations
const filteredUsers = useMemo(() => {
  return users.filter(u => u.name.includes(searchQuery));
}, [users, searchQuery]);

// Memoize callbacks
const handleDelete = useCallback((id: string) => {
  deleteMutation.mutate(id);
}, [deleteMutation]);
```

## Deployment

### Production Build

```bash
npm run build
```

Output: `dist/` directory

### Docker Deployment

```dockerfile
# Multi-stage build
FROM node:18-alpine AS builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

FROM nginx:alpine
COPY --from=builder /app/dist /usr/share/nginx/html
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
```

Build and run:

```bash
docker build -t opbx-frontend .
docker run -d -p 3000:80 opbx-frontend
```

## Troubleshooting

### Common Issues

**Issue: API connection fails**
- Check `VITE_API_BASE_URL` in `.env`
- Verify backend is running
- Check CORS configuration on backend

**Issue: WebSocket not connecting**
- Check `VITE_WS_URL` in `.env`
- Ensure WebSocket server is running
- Verify authentication token is valid

**Issue: Build errors**
- Run `npm install` to ensure all dependencies are installed
- Clear cache: `rm -rf node_modules .vite package-lock.json && npm install`
- Check TypeScript errors: `npm run type-check`

**Issue: Slow development server**
- Reduce bundle size by lazy loading routes
- Disable source maps in development (not recommended)
- Increase Node.js memory: `NODE_OPTIONS=--max_old_space_size=4096 npm run dev`

## Best Practices

### 1. TypeScript
- Always use strict mode
- Define types for all API responses
- Avoid `any` type
- Use proper type inference

### 2. Components
- Keep components small and focused
- Extract reusable logic to custom hooks
- Use composition over prop drilling
- Implement proper loading and error states

### 3. State Management
- Use React Query for server state
- Use local state for UI state
- Minimize global state
- Implement optimistic updates

### 4. Performance
- Lazy load routes
- Memoize expensive calculations
- Use proper React Query cache configuration
- Optimize images and assets

### 5. Accessibility
- Use semantic HTML
- Add ARIA labels where needed
- Ensure keyboard navigation works
- Test with screen readers
- Maintain proper color contrast

## Next Steps

### Enhancements to Consider

1. **Storybook Integration**: Document components visually
2. **E2E Testing**: Implement Playwright or Cypress tests
3. **Internationalization**: Add i18n support with react-i18next
4. **Dark Mode**: Full dark theme implementation
5. **Offline Support**: PWA with service workers
6. **Advanced Analytics**: Usage tracking and metrics
7. **Error Boundary**: Better error handling UI
8. **Accessibility Audit**: WCAG 2.1 AA compliance verification

## Support & Documentation

- **Frontend README**: `/frontend/README.md`
- **API Documentation**: Check backend `/api/documentation`
- **Cloudonix Docs**: https://developers.cloudonix.com/
- **shadcn/ui Docs**: https://ui.shadcn.com/
- **React Query Docs**: https://tanstack.com/query/latest

---

**Version**: 1.0.0
**Last Updated**: 2025-12-21
**Maintainers**: OPBX Development Team
