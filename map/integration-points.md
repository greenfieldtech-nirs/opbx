# Backend ↔ Frontend Integration

## Overview

OpBX implements a comprehensive integration layer between the Laravel backend and React frontend. This document outlines the key integration points, data synchronization patterns, and communication protocols.

## API Integration Layer

### RESTful API Communication

#### Base API Configuration
```typescript
// frontend/src/services/api.ts
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8000/api/v1',
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});
```

#### Authentication Integration
```typescript
// Automatic token attachment
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});
```

#### Global Error Handling
```typescript
// frontend/src/services/api.ts
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const { status, data } = error.response || {};
    
    switch (status) {
      case 401:
        // Token expired or invalid
        authService.logout();
        window.location.href = '/login';
        break;
        
      case 403:
        toast.error('You do not have permission to perform this action');
        break;
        
      case 422:
        // Validation errors
        if (data.errors) {
          Object.values(data.errors).forEach((messages: string[]) => {
            messages.forEach(message => toast.error(message));
          });
        }
        break;
        
      case 429:
        toast.error('Too many requests. Please try again later.');
        break;
        
      default:
        toast.error(data?.message || 'An unexpected error occurred');
    }
    
    return Promise.reject(error);
  }
);
```

### Resource Service Pattern

#### Generic CRUD Service
```typescript
// frontend/src/services/createResourceService.ts
export function createResourceService<T extends { id: string | number }>(
  resource: string
) {
  return {
    async getAll(params?: Record<string, any>): Promise<T[]> {
      const response = await api.get(`/${resource}`, { params });
      return response.data.data;
    },
    
    async getById(id: string | number): Promise<T> {
      const response = await api.get(`/${resource}/${id}`);
      return response.data.data;
    },
    
    async create(data: Partial<T>): Promise<T> {
      const response = await api.post(`/${resource}`, data);
      return response.data.data;
    },
    
    async update(id: string | number, data: Partial<T>): Promise<T> {
      const response = await api.put(`/${resource}/${id}`, data);
      return response.data.data;
    },
    
    async delete(id: string | number): Promise<void> {
      await api.delete(`/${resource}/${id}`);
    },
  };
}
```

#### Feature-Specific Services
```typescript
// frontend/src/services/users.service.ts
export const usersService = createResourceService<User>('users');

// Add feature-specific methods
export const extendedUsersService = {
  ...usersService,
  
  async restore(id: string | number): Promise<User> {
    const response = await api.post(`/users/${id}/restore`);
    return response.data.data;
  },
  
  async changePassword(id: string | number, password: string): Promise<void> {
    await api.post(`/users/${id}/change-password`, { password });
  },
};
```

## State Management Integration

### React Query for Server State

#### Query Client Configuration
```typescript
// frontend/src/lib/queryClient.ts
import { QueryClient } from '@tanstack/react-query';

export const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 5 * 60 * 1000, // 5 minutes
      cacheTime: 10 * 60 * 1000, // 10 minutes
      retry: (failureCount, error: any) => {
        // Don't retry on 4xx errors
        if (error?.response?.status >= 400 && error?.response?.status < 500) {
          return false;
        }
        return failureCount < 3;
      },
      refetchOnWindowFocus: false,
    },
    mutations: {
      retry: false,
    },
  },
});
```

#### Custom Data Hooks
```typescript
// frontend/src/hooks/useUsers.ts
export function useUsers(filters?: UserFilters) {
  return useQuery({
    queryKey: ['users', filters],
    queryFn: () => usersService.getAll(filters),
    select: (data) => data, // Optional data transformation
  });
}

export function useUser(id: string | number) {
  return useQuery({
    queryKey: ['users', id],
    queryFn: () => usersService.getById(id),
    enabled: !!id, // Only run if id is provided
  });
}

export function useCreateUser() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: usersService.create,
    onSuccess: (newUser) => {
      // Update cache optimistically
      queryClient.setQueryData(['users'], (old: User[] | undefined) => 
        old ? [...old, newUser] : [newUser]
      );
      
      // Invalidate related queries
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}

export function useUpdateUser() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<User> }) => 
      usersService.update(id, data),
    
    onMutate: async ({ id, data }) => {
      // Cancel outgoing refetches
      await queryClient.cancelQueries({ queryKey: ['users', id] });
      
      // Snapshot previous value
      const previousUser = queryClient.getQueryData(['users', id]);
      
      // Optimistically update
      queryClient.setQueryData(['users', id], (old: User | undefined) => 
        old ? { ...old, ...data } : undefined
      );
      
      return { previousUser };
    },
    
    onError: (err, variables, context) => {
      // Revert on error
      if (context?.previousUser) {
        queryClient.setQueryData(['users', variables.id], context.previousUser);
      }
    },
    
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}

export function useDeleteUser() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: usersService.delete,
    onSuccess: (deletedId) => {
      // Remove from cache
      queryClient.setQueryData(['users'], (old: User[] | undefined) => 
        old ? old.filter(user => user.id !== deletedId) : undefined
      );
      
      // Invalidate related queries
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}
```

### Authentication Context

#### Auth Context Provider
```typescript
// frontend/src/context/AuthContext.tsx
interface AuthContextType {
  user: User | null;
  isLoading: boolean;
  login: (credentials: LoginCredentials) => Promise<void>;
  logout: () => void;
  hasRole: (role: Role) => boolean;
  hasPermission: (permission: string) => boolean;
}

const AuthContext = createContext<AuthContextType | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  
  useEffect(() => {
    // Check for existing session
    const token = localStorage.getItem('auth_token');
    const savedUser = localStorage.getItem('user');
    
    if (token && savedUser) {
      setUser(JSON.parse(savedUser));
    }
    
    setIsLoading(false);
  }, []);
  
  const login = async (credentials: LoginCredentials) => {
    const response = await authService.login(credentials);
    setUser(response.user);
  };
  
  const logout = () => {
    authService.logout();
    setUser(null);
  };
  
  const hasRole = (role: Role) => {
    return user?.role === role;
  };
  
  const hasPermission = (permission: string) => {
    // Implement permission checking logic
    return user?.permissions?.includes(permission) ?? false;
  };
  
  return (
    <AuthContext.Provider value={{
      user,
      isLoading,
      login,
      logout,
      hasRole,
      hasPermission,
    }}>
      {children}
    </AuthContext.Provider>
  );
}
```

#### Auth Hook
```typescript
// frontend/src/hooks/useAuth.ts
export function useAuth() {
  const context = useContext(AuthContext);
  
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  
  return context;
}
```

## Real-time Integration

### WebSocket Connection

#### Echo Configuration
```typescript
// frontend/src/services/echo.ts
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

const echo = new Echo({
  broadcaster: 'pusher',
  key: import.meta.env.VITE_PUSHER_APP_KEY,
  cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER,
  wsHost: import.meta.env.VITE_WEBSOCKET_HOST,
  wsPort: import.meta.env.VITE_WEBSOCKET_PORT,
  wssPort: import.meta.env.VITE_WEBSOCKET_PORT,
  forceTLS: false,
  disableStats: true,
  enabledTransports: ['ws', 'wss'],
  auth: {
    headers: {
      Authorization: `Bearer ${localStorage.getItem('auth_token')}`,
    },
  },
});

export default echo;
```

#### WebSocket Hook
```typescript
// frontend/src/hooks/useWebSocket.ts
export function useWebSocket(channelName: string) {
  const [isConnected, setIsConnected] = useState(false);
  const [lastMessage, setLastMessage] = useState<any>(null);
  
  useEffect(() => {
    const channel = window.Echo.private(channelName);
    
    channel.listen('.message', (event: any) => {
      setLastMessage(event);
    });
    
    // Connection status
    const connectionChannel = window.Echo.connector.pusher.connection;
    connectionChannel.bind('connected', () => setIsConnected(true));
    connectionChannel.bind('disconnected', () => setIsConnected(false));
    
    return () => {
      channel.stopListening('.message');
    };
  }, [channelName]);
  
  return { isConnected, lastMessage };
}
```

### Real-time Call Presence

#### Call Presence Hook
```typescript
// frontend/src/hooks/useCallPresence.ts
export function useCallPresence() {
  const [calls, setCalls] = useState<ActiveCall[]>([]);
  
  useEffect(() => {
    // Listen for call state updates
    const channel = window.Echo.private('calls');
    
    channel.listen('.call.state.updated', (event: CallStateEvent) => {
      setCalls(prevCalls => 
        prevCalls.map(call => 
          call.id === event.call_id 
            ? { ...call, state: event.state, updatedAt: new Date(event.timestamp) }
            : call
        )
      );
    });
    
    channel.listen('.call.started', (event: CallStartedEvent) => {
      setCalls(prevCalls => [...prevCalls, event.call]);
    });
    
    channel.listen('.call.ended', (event: CallEndedEvent) => {
      setCalls(prevCalls => 
        prevCalls.filter(call => call.id !== event.call_id)
      );
    });
    
    // Initial load
    const loadActiveCalls = async () => {
      try {
        const activeCalls = await callLogsService.getActiveCalls();
        setCalls(activeCalls);
      } catch (error) {
        console.error('Failed to load active calls:', error);
      }
    };
    
    loadActiveCalls();
    
    return () => {
      channel.stopListening('.call.state.updated');
      channel.stopListening('.call.started');
      channel.stopListening('.call.ended');
    };
  }, []);
  
  return calls;
}
```

## Form Integration

### React Hook Form with API

#### Form Hook
```typescript
// frontend/src/hooks/useFormWithApi.ts
export function useFormWithApi<T extends Record<string, any>>(
  initialValues: T,
  validationSchema?: any
) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
    reset,
    setError,
    watch,
    control,
  } = useForm<T>({
    defaultValues: initialValues,
    resolver: validationSchema ? zodResolver(validationSchema) : undefined,
  });
  
  const submitForm = useCallback(async (
    data: T, 
    submitFn: (data: T) => Promise<any>,
    options?: {
      onSuccess?: (result: any) => void;
      onError?: (error: any) => void;
      successMessage?: string;
    }
  ) => {
    try {
      const result = await submitFn(data);
      
      if (options?.successMessage) {
        toast.success(options.successMessage);
      }
      
      options?.onSuccess?.(result);
      reset();
      
    } catch (error: any) {
      if (error.response?.status === 422 && error.response?.data?.errors) {
        // Set field errors
        Object.entries(error.response.data.errors).forEach(([field, messages]) => {
          setError(field as keyof T, { 
            message: (messages as string[])[0] 
          });
        });
      }
      
      options?.onError?.(error);
    }
  }, [reset, setError]);
  
  return {
    register,
    handleSubmit,
    errors,
    isSubmitting,
    reset,
    setError,
    watch,
    control,
    submitForm,
  };
}
```

#### Form Component Example
```tsx
function UserForm({ user, onSubmit, onCancel }: UserFormProps) {
  const { register, handleSubmit, errors, isSubmitting, submitForm } = useFormWithApi(
    user || { name: '', email: '', role: 'user' },
    userSchema
  );
  
  const onFormSubmit = (data: UserFormData) => {
    submitForm(
      data,
      user ? (data) => usersService.update(user.id, data) : usersService.create,
      {
        successMessage: user ? 'User updated successfully' : 'User created successfully',
        onSuccess: onSubmit,
      }
    );
  };
  
  return (
    <Form onSubmit={handleSubmit(onFormSubmit)}>
      <Input
        label="Name"
        {...register('name')}
        error={errors.name?.message}
      />
      <Input
        label="Email"
        type="email"
        {...register('email')}
        error={errors.email?.message}
      />
      <Select {...register('role')}>
        <SelectItem value="user">User</SelectItem>
        <SelectItem value="admin">Admin</SelectItem>
      </Select>
      
      <div className="flex gap-2">
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Saving...' : 'Save'}
        </Button>
        <Button type="button" variant="outline" onClick={onCancel}>
          Cancel
        </Button>
      </div>
    </Form>
  );
}
```

## Error Boundary Integration

### Global Error Boundary
```tsx
// frontend/src/components/ErrorBoundary.tsx
class ErrorBoundary extends Component {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false, error: null, errorInfo: null };
  }
  
  static getDerivedStateFromError(error: Error) {
    return { hasError: true, error };
  }
  
  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    // Log to error reporting service
    if (window.Sentry) {
      window.Sentry.captureException(error, { errorInfo });
    }
    
    this.setState({ errorInfo });
  }
  
  render() {
    if (this.state.hasError) {
      return (
        <div className="min-h-screen flex items-center justify-center">
          <div className="text-center">
            <h1 className="text-2xl font-bold text-red-600 mb-4">
              Something went wrong
            </h1>
            <p className="text-gray-600 mb-4">
              We're sorry, but something unexpected happened.
            </p>
            <Button onClick={() => window.location.reload()}>
              Reload Page
            </Button>
          </div>
        </div>
      );
    }
    
    return this.props.children;
  }
}
```

## Loading States Integration

### Suspense Integration
```tsx
// frontend/src/App.tsx
const router = createBrowserRouter([
  {
    path: '/',
    element: <AppLayout />,
    children: [
      {
        path: 'users',
        element: (
          <Suspense fallback={<PageSkeleton />}>
            <UsersPage />
          </Suspense>
        ),
      },
    ],
  },
]);
```

### Loading Components
```tsx
// frontend/src/components/design-system/LoadingSpinner.tsx
export function LoadingSpinner({ size = 'md' }: { size?: 'sm' | 'md' | 'lg' }) {
  const sizeClasses = {
    sm: 'h-4 w-4',
    md: 'h-8 w-8',
    lg: 'h-12 w-12',
  };
  
  return (
    <div className={`animate-spin rounded-full border-2 border-gray-300 border-t-blue-600 ${sizeClasses[size]}`} />
  );
}

// frontend/src/components/design-system/PageSkeleton.tsx
export function PageSkeleton() {
  return (
    <div className="space-y-4">
      <div className="h-8 bg-gray-200 rounded animate-pulse" />
      <div className="h-4 bg-gray-200 rounded animate-pulse w-3/4" />
      <div className="h-4 bg-gray-200 rounded animate-pulse w-1/2" />
    </div>
  );
}
```

## Notification Integration

### Toast Notifications
```typescript
// frontend/src/lib/toast.ts
import { toast as sonnerToast } from 'sonner';

export const toast = {
  success: (message: string) => sonnerToast.success(message),
  error: (message: string) => sonnerToast.error(message),
  info: (message: string) => sonnerToast.info(message),
  warning: (message: string) => sonnerToast.warning(message),
};
```

### Toast Provider
```tsx
// frontend/src/App.tsx
import { Toaster } from 'sonner';

function App() {
  return (
    <AuthProvider>
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
        <Toaster position="top-right" />
      </QueryClientProvider>
    </AuthProvider>
  );
}
```

## Type Safety Integration

### API Type Generation
```typescript
// Generated types from Laravel API resources
export interface User {
  id: string;
  name: string;
  email: string;
  role: 'owner' | 'admin' | 'agent' | 'user';
  extension?: Extension;
  created_at: string;
  updated_at: string;
}

export interface ApiResponse<T> {
  data: T;
  meta?: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
  };
  message?: string;
}
```

### Form Schema Validation
```typescript
// frontend/src/schemas/user.ts
import { z } from 'zod';

export const userSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  email: z.string().email('Invalid email address'),
  role: z.enum(['owner', 'admin', 'agent', 'user']),
  extension_number: z.string().optional(),
});

export type UserFormData = z.infer<typeof userSchema>;
```

This integration layer ensures seamless communication between the React frontend and Laravel backend, with proper error handling, real-time updates, and type safety throughout the application.