# React Frontend Structure

## Overview

The OpBX frontend is a modern React SPA built with TypeScript, providing a comprehensive administrative interface for PBX management. The application follows atomic design principles with feature-based organization and enterprise-grade state management.

## Technology Stack

- **React 18** with TypeScript for type safety
- **Vite** for fast development and optimized builds
- **React Router v6** for client-side routing
- **React Query (TanStack)** for server state management
- **Zustand** for client state management
- **Tailwind CSS** with **Radix UI** for component primitives
- **React Hook Form + Zod** for form management
- **Laravel Echo + Pusher** for real-time WebSocket communication

## Root Directory Structure

```
frontend/
├── src/
│   ├── components/           # Feature-based component organization
│   ├── context/             # React Context providers
│   ├── hooks/               # Custom React hooks
│   ├── pages/               # Route-based page components
│   ├── services/            # API and external service integrations
│   ├── types/               # TypeScript type definitions
│   ├── utils/               # Utility functions
│   └── lib/                 # Core library functions
├── public/                  # Static assets
├── dist/                    # Build output
├── index.html               # HTML template
├── vite.config.ts           # Vite configuration
├── tailwind.config.js       # Tailwind CSS configuration
├── tsconfig.json            # TypeScript configuration
├── package.json             # Node dependencies
└── ...                      # Configuration files
```

## Component Architecture

### Feature-First Organization

Components are organized by business domain rather than technical layers:

```
src/components/
├── Auth/                    # Authentication components
│   ├── LoginForm.tsx       # Login form with validation
│   ├── LogoutButton.tsx    # Logout functionality
│   └── AuthGuard.tsx       # Route protection
├── Layout/                  # App shell components
│   ├── AppLayout.tsx       # Main layout wrapper
│   ├── Navigation.tsx      # Sidebar navigation
│   ├── Header.tsx          # Top header bar
│   └── Breadcrumbs.tsx     # Navigation breadcrumbs
├── Users/                   # User management
│   ├── UserList.tsx        # User table with filtering
│   ├── UserForm.tsx        # Create/edit user form
│   ├── UserCard.tsx        # User display card
│   └── UserActions.tsx     # User action buttons
├── Extensions/              # SIP extension management
│   ├── ExtensionList.tsx   # Extension table
│   ├── ExtensionForm.tsx   # Extension configuration
│   ├── ExtensionCard.tsx   # Extension display
│   └── ExtensionPassword.tsx # Password management
├── RingGroups/              # Call distribution
│   ├── RingGroupList.tsx   # Group table
│   ├── RingGroupForm.tsx   # Group configuration
│   ├── RingGroupMembers.tsx # Member management
│   └── RingStrategySelect.tsx # Strategy selector
├── LiveCalls/               # Real-time call monitoring
│   ├── LiveCallList.tsx    # Active calls display
│   ├── CallStatusBadge.tsx # Status indicators
│   ├── CallActions.tsx     # Call control actions
│   └── CallTimer.tsx       # Call duration display
├── BusinessHours/           # Time-based routing
│   ├── BusinessHoursList.tsx # Schedule table
│   ├── BusinessHoursForm.tsx # Schedule configuration
│   ├── TimeRuleEditor.tsx   # Weekly rule editor
│   └── HolidayCalendar.tsx  # Holiday management
├── design-system/           # Shared UI components
│   ├── EmptyState.tsx      # No-data state component
│   ├── LoadingSpinner.tsx  # Loading indicators
│   ├── ConfirmDialog.tsx   # Confirmation dialogs
│   ├── StatCard.tsx        # Statistics display
│   └── ...                 # Additional design system
└── ui/                     # Radix UI primitives
    ├── button.tsx          # Button component
    ├── input.tsx           # Input field
    ├── dialog.tsx          # Modal dialogs
    ├── table.tsx           # Data tables
    └── ...                 # All Radix components
```

### Component Patterns

#### 1. Container/Presentational Pattern
```typescript
// ExtensionList.tsx (Container)
function ExtensionList() {
  const { extensions, isLoading } = useExtensions();
  return <ExtensionTable extensions={extensions} loading={isLoading} />;
}

// ExtensionTable.tsx (Presentational)
interface ExtensionTableProps {
  extensions: Extension[];
  loading: boolean;
}
function ExtensionTable({ extensions, loading }: ExtensionTableProps) {
  // Pure presentation logic
}
```

#### 2. Compound Components
```typescript
// RingGroupForm.tsx
function RingGroupForm({ ringGroup, onSubmit }) {
  return (
    <Form onSubmit={onSubmit}>
      <Form.Field name="name">
        <Form.Label>Name</Form.Label>
        <Form.Input />
        <Form.Error />
      </Form.Field>
      <Form.Field name="strategy">
        <Form.Label>Strategy</Form.Label>
        <RingStrategySelect />
        <Form.Error />
      </Form.Field>
    </Form>
  );
}
```

#### 3. Render Props Pattern
```typescript
// ConfirmDialog.tsx
function ConfirmDialog({ children, onConfirm, onCancel }) {
  const [open, setOpen] = useState(false);

  return (
    <>
      {children({ open: () => setOpen(true) })}
      <Dialog open={open} onOpenChange={setOpen}>
        <Dialog.Content>
          <Dialog.Header>Confirm Action</Dialog.Header>
          <Dialog.Footer>
            <Button onClick={onCancel}>Cancel</Button>
            <Button onClick={onConfirm}>Confirm</Button>
          </Dialog.Footer>
        </Dialog.Content>
      </Dialog>
    </>
  );
}

// Usage
<ConfirmDialog onConfirm={handleDelete}>
  {({ open }) => <Button onClick={open}>Delete</Button>}
</ConfirmDialog>
```

## State Management Strategy

### Multi-Layer State Management

#### 1. Server State (React Query)
- **Purpose**: API data, caching, synchronization
- **Library**: TanStack React Query v5
- **Features**:
  - Intelligent caching (5-minute stale time)
  - Background refetching
  - Optimistic updates
  - Error handling and retry logic

```typescript
// Custom hook for extensions
export function useExtensions(filters?: ExtensionFilters) {
  return useQuery({
    queryKey: ['extensions', filters],
    queryFn: () => extensionService.getAll(filters),
    staleTime: 5 * 60 * 1000, // 5 minutes
  });
}

// Mutation hook
export function useCreateExtension() {
  const queryClient = useQueryClient();

  return useMutation({
    mutationFn: extensionService.create,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['extensions'] });
    },
  });
}
```

#### 2. Client State (Zustand)
- **Purpose**: UI state, user preferences, modal states
- **Library**: Zustand with immer middleware
- **Features**:
  - Lightweight and simple
  - TypeScript support
  - Middleware for persistence

```typescript
// UI state store
interface UiState {
  sidebarOpen: boolean;
  theme: 'light' | 'dark';
  notifications: Notification[];
}

export const useUiStore = create<UiState>()(
  persist(
    (set) => ({
      sidebarOpen: true,
      theme: 'light',
      notifications: [],
    }),
    { name: 'ui-storage' }
  )
);
```

#### 3. Component State (React Hooks)
- **Purpose**: Local component state
- **Patterns**: useState, useReducer for complex state

### Context Providers

```
src/context/
├── AuthContext.tsx          # Authentication state
└── ThemeContext.tsx         # Theme management
```

**AuthContext Implementation:**
```typescript
interface AuthContextType {
  user: User | null;
  login: (credentials: LoginCredentials) => Promise<void>;
  logout: () => Promise<void>;
  isLoading: boolean;
}

export const AuthProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);

  // Authentication logic...

  return (
    <AuthContext.Provider value={{ user, login, logout, isLoading }}>
      {children}
    </AuthContext.Provider>
  );
};
```

## Custom Hooks

### Data Fetching Hooks

```
src/hooks/
├── useAuth.ts              # Authentication state
├── useExtensions.ts        # Extension data management
├── useRingGroups.ts        # Ring group operations
├── useCallLogs.ts          # Call history with pagination
├── useLiveCalls.ts         # Real-time call monitoring
├── useWebSocket.ts         # WebSocket connection management
└── useLocalStorage.ts      # Local storage utilities
```

**Custom Hook Pattern:**
```typescript
export function useExtensions(filters?: ExtensionFilters) {
  const { user } = useAuth();

  return useQuery({
    queryKey: ['extensions', user?.organization?.id, filters],
    queryFn: () => extensionService.getAll(filters),
    enabled: !!user, // Only fetch when authenticated
  });
}
```

### Real-Time Hooks

```typescript
// WebSocket connection hook
export function useWebSocketConnection() {
  const { user } = useAuth();
  const { isConnected, connect, disconnect } = useWebSocket();

  useEffect(() => {
    if (user && !isConnected) {
      connect();
    }
    return () => disconnect();
  }, [user, isConnected, connect, disconnect]);

  return { isConnected };
}

// Call presence hook
export function useCallPresence() {
  const [activeCalls, setActiveCalls] = useState<CallPresenceUpdate[]>([]);

  useWebSocket('call.*', (event, data) => {
    switch (event) {
      case 'call.initiated':
        setActiveCalls(prev => [...prev, data]);
        break;
      case 'call.ended':
        setActiveCalls(prev => prev.filter(call => call.call_id !== data.call_id));
        break;
    }
  });

  return { activeCalls };
}
```

## Service Layer

### API Service Architecture

```
src/services/
├── api/
│   ├── client.ts            # Axios client configuration
│   ├── extensions.ts        # Extension API calls
│   ├── ringGroups.ts        # Ring group operations
│   ├── callLogs.ts          # Call history API
│   └── ...                  # Feature-specific services
├── websocket/
│   ├── websocket.service.ts # Native WebSocket service
│   └── echo.service.ts      # Laravel Echo service
└── utils/
    ├── date.ts              # Date utilities
    ├── phone.ts             # Phone number formatting
    └── validation.ts        # Client-side validation
```

**API Client Configuration:**
```typescript
// client.ts
export const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  timeout: 30000,
});

// Request interceptor for authentication
apiClient.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response interceptor for error handling
apiClient.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      // Redirect to login
      window.location.href = '/login';
    }
    return Promise.reject(error);
  }
);
```

### Service Classes

```typescript
// extensions.ts
export class ExtensionService {
  async getAll(filters?: ExtensionFilters): Promise<PaginatedResponse<Extension>> {
    const response = await apiClient.get('/extensions', { params: filters });
    return response.data;
  }

  async create(data: CreateExtensionData): Promise<Extension> {
    const response = await apiClient.post('/extensions', data);
    return response.data.data;
  }

  async update(id: number, data: UpdateExtensionData): Promise<Extension> {
    const response = await apiClient.put(`/extensions/${id}`, data);
    return response.data.data;
  }

  async delete(id: number): Promise<void> {
    await apiClient.delete(`/extensions/${id}`);
  }
}

export const extensionService = new ExtensionService();
```

## Type Definitions

### API Types

```
src/types/
├── api/
│   ├── common.ts            # Shared API types
│   ├── auth.ts              # Authentication types
│   ├── users.ts             # User management types
│   ├── extensions.ts        # Extension types
│   ├── ring-groups.ts       # Ring group types
│   ├── call-logs.ts         # Call history types
│   └── ...                  # Additional API types
├── components/              # Component prop types
├── forms/                   # Form data types
└── websocket.ts             # WebSocket event types
```

**Type Definition Example:**
```typescript
// extensions.ts
export interface Extension {
  id: number;
  organization_id: number;
  user_id?: number;
  extension_number: string;
  type: ExtensionType;
  status: 'active' | 'inactive';
  voicemail_enabled: boolean;
  password?: string; // Hidden in API responses
  user?: User;
  created_at: string;
  updated_at: string;
}

export interface CreateExtensionData {
  extension_number: string;
  type: ExtensionType;
  user_id?: number;
  voicemail_enabled?: boolean;
}

export type ExtensionType =
  | 'user'
  | 'conference'
  | 'ring_group'
  | 'ivr'
  | 'ai_assistant'
  | 'custom_logic'
  | 'forward';
```

## Utility Functions

### Helper Utilities

```
src/utils/
├── cn.ts                   # Class name utility (clsx + tailwind)
├── formatters.ts           # Data formatting functions
├── validators.ts           # Client-side validation
└── constants.ts            # Application constants
```

**Class Name Utility:**
```typescript
// cn.ts
import { clsx, type ClassValue } from 'clsx';
import { twMerge } from 'tailwind-merge';

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

// Usage
<div className={cn('base-class', isActive && 'active-class', className)} />
```

## Page Components

### Route-Based Organization

```
src/pages/
├── Auth/
│   ├── Login.tsx           # Login page
│   └── ForgotPassword.tsx  # Password reset
├── Dashboard/
│   ├── Dashboard.tsx       # Main dashboard
│   └── widgets/            # Dashboard widgets
├── Users/
│   ├── Users.tsx           # User list page
│   ├── UserDetail.tsx      # User detail/edit
│   └── CreateUser.tsx      # User creation
├── Extensions/
│   ├── Extensions.tsx      # Extension management
│   └── ExtensionDetail.tsx # Extension configuration
├── RingGroups/
│   ├── RingGroups.tsx      # Ring group management
│   └── RingGroupDetail.tsx # Group configuration
├── LiveCalls/
│   └── LiveCalls.tsx       # Real-time monitoring
├── BusinessHours/
│   ├── BusinessHours.tsx   # Schedule management
│   └── BusinessHoursDetail.tsx # Schedule configuration
├── CallLogs/
│   ├── CallLogs.tsx        # Call history
│   └── CallDetail.tsx      # Individual call details
└── Settings/
    ├── Settings.tsx        # Organization settings
    └── CloudonixSettings.tsx # API configuration
```

### Page Component Pattern

```typescript
// Extensions.tsx
export default function Extensions() {
  const { user } = useAuth();
  const { extensions, isLoading, error } = useExtensions();

  if (!user) return <Navigate to="/login" />;

  return (
    <PageLayout title="Extensions">
      <div className="space-y-6">
        <div className="flex justify-between items-center">
          <h1 className="text-2xl font-bold">Extensions</h1>
          <Button onClick={() => navigate('/extensions/create')}>
            <Plus className="h-4 w-4 mr-2" />
            Create Extension
          </Button>
        </div>

        {error && <ErrorAlert error={error} />}

        <ExtensionTable
          extensions={extensions?.data || []}
          loading={isLoading}
        />
      </div>
    </PageLayout>
  );
}
```

## Design System

### Component Library

The application uses a comprehensive design system built on Radix UI primitives with Tailwind CSS:

- **Colors**: Consistent color palette with CSS custom properties
- **Typography**: Type scale with responsive sizing
- **Spacing**: Consistent spacing scale
- **Shadows**: Elevation system for depth
- **Borders**: Border radius and width system

### Key Design Components

#### Empty State Pattern
```typescript
interface EmptyStateProps {
  icon: React.ComponentType<{ className?: string }>;
  title: string;
  description: string;
  action?: React.ReactNode;
}

export function EmptyState({ icon: Icon, title, description, action }: EmptyStateProps) {
  return (
    <div className="text-center py-12">
      <Icon className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
      <h3 className="text-lg font-semibold mb-2">{title}</h3>
      <p className="text-muted-foreground mb-4">{description}</p>
      {action}
    </div>
  );
}
```

#### Loading States
```typescript
export function LoadingSpinner({ size = 'md' }: { size?: 'sm' | 'md' | 'lg' }) {
  const sizeClasses = {
    sm: 'h-4 w-4',
    md: 'h-6 w-6',
    lg: 'h-8 w-8',
  };

  return (
    <div className={cn('animate-spin rounded-full border-2 border-primary border-t-transparent', sizeClasses[size])} />
  );
}
```

## Performance Optimizations

### Code Splitting
- **Route-based splitting**: Each page is a separate chunk
- **Component lazy loading**: Heavy components loaded on demand
- **Vendor splitting**: Separate chunks for React, UI libraries

### Bundle Optimization
- **Tree shaking**: Unused code elimination
- **Asset optimization**: Image and font optimization
- **Compression**: Gzip compression for production builds

### Runtime Performance
- **Memoization**: React.memo for expensive components
- **Virtual scrolling**: For large data tables
- **Debounced search**: Prevent excessive API calls

## Testing Structure

```
src/
├── __tests__/              # Unit tests
├── __mocks__/              # Mock implementations
└── test-utils/             # Testing utilities
    ├── renderWithProviders.tsx
    ├── mockServer.ts       # MSW for API mocking
    └── ...                 # Additional test helpers
```

## Build Configuration

### Vite Configuration

```typescript
// vite.config.ts
import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
      '@components': path.resolve(__dirname, './src/components'),
      '@services': path.resolve(__dirname, './src/services'),
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks: {
          vendor: ['react', 'react-dom'],
          ui: ['@radix-ui/react-dialog', '@radix-ui/react-dropdown-menu'],
          router: ['react-router-dom'],
        },
      },
    },
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
});
```

This React structure provides a scalable, maintainable, and performant frontend foundation for the OpBX PBX management system with modern development practices and enterprise-grade architecture.