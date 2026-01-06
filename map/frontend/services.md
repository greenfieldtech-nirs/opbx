# Frontend Services Layer

## Overview

The frontend services layer provides a clean API abstraction with consistent patterns for data fetching, caching, and error handling. Services use Axios for HTTP communication and React Query for intelligent caching.

## Core Infrastructure

### API Client
**Location**: `frontend/src/services/api.ts`

Centralized HTTP client configuration.

**Features**:
- Axios instance with interceptors
- Automatic Bearer token injection
- Global error handling
- Request/response logging
- Timeout configuration

**Configuration**:
```typescript
const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL,
  timeout: 10000,
  headers: {
    'Content-Type': 'application/json',
  },
});

// Request interceptor for auth
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  return config;
});

// Response interceptor for errors
api.interceptors.response.use(
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

### Authentication Service
**Location**: `frontend/src/services/auth.service.ts`

Authentication operations and token management.

**Methods**:
```typescript
export const authService = {
  async login(credentials: LoginCredentials): Promise<AuthResponse> {
    const response = await api.post('/auth/login', credentials);
    const { token, user } = response.data;
    
    localStorage.setItem('auth_token', token);
    localStorage.setItem('user', JSON.stringify(user));
    
    return response.data;
  },
  
  logout(): void {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('user');
    window.location.href = '/login';
  },
  
  getCurrentUser(): User | null {
    const user = localStorage.getItem('user');
    return user ? JSON.parse(user) : null;
  },
  
  isAuthenticated(): boolean {
    return !!localStorage.getItem('auth_token');
  }
};
```

## Feature Services

### Generic CRUD Service
**Location**: `frontend/src/services/createResourceService.ts`

Factory for consistent CRUD operations.

**Pattern**:
```typescript
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
    
    async create(data: Omit<T, 'id'>): Promise<T> {
      const response = await api.post(`/${resource}`, data);
      return response.data.data;
    },
    
    async update(id: string | number, data: Partial<T>): Promise<T> {
      const response = await api.put(`/${resource}/${id}`, data);
      return response.data.data;
    },
    
    async delete(id: string | number): Promise<void> {
      await api.delete(`/${resource}/${id}`);
    }
  };
}
```

**Usage**:
```typescript
export const usersService = createResourceService<User>('users');
export const extensionsService = createResourceService<Extension>('extensions');
```

### Users Service
**Location**: `frontend/src/services/users.service.ts`

User management with extended functionality.

**Additional Methods**:
```typescript
export const usersService = {
  ...createResourceService<User>('users'),
  
  async restore(id: string | number): Promise<User> {
    const response = await api.post(`/users/${id}/restore`);
    return response.data.data;
  },
  
  async changePassword(id: string | number, password: string): Promise<void> {
    await api.post(`/users/${id}/change-password`, { password });
  }
};
```

### Extensions Service
**Location**: `frontend/src/services/extensions.service.ts`

Extension management with SIP operations.

**Additional Methods**:
```typescript
export const extensionsService = {
  ...createResourceService<Extension>('extensions'),
  
  async regeneratePassword(id: string | number): Promise<{ password: string }> {
    const response = await api.post(`/extensions/${id}/regenerate-password`);
    return response.data.data;
  },
  
  async getAvailableExtensions(): Promise<Extension[]> {
    const response = await api.get('/extensions/available');
    return response.data.data;
  }
};
```

### Ring Groups Service
**Location**: `frontend/src/services/ringGroups.service.ts`

Ring group management with member operations.

**Additional Methods**:
```typescript
export const ringGroupsService = {
  ...createResourceService<RingGroup>('ring-groups'),
  
  async addMember(groupId: string | number, extensionId: string | number, priority: number): Promise<void> {
    await api.post(`/ring-groups/${groupId}/members`, {
      extension_id: extensionId,
      priority
    });
  },
  
  async removeMember(groupId: string | number, memberId: string | number): Promise<void> {
    await api.delete(`/ring-groups/${groupId}/members/${memberId}`);
  },
  
  async reorderMembers(groupId: string | number, memberOrder: Array<{ id: number; priority: number }>): Promise<void> {
    await api.put(`/ring-groups/${groupId}/members/reorder`, {
      members: memberOrder
    });
  }
};
```

### Business Hours Service
**Location**: `frontend/src/services/businessHours.service.ts`

Time-based routing configuration.

**Additional Methods**:
```typescript
export const businessHoursService = {
  ...createResourceService<BusinessHours>('business-hours'),
  
  async getSchedule(ruleId: string | number): Promise<BusinessHoursSchedule[]> {
    const response = await api.get(`/business-hours/${ruleId}/schedule`);
    return response.data.data;
  },
  
  async updateSchedule(ruleId: string | number, schedule: BusinessHoursSchedule[]): Promise<void> {
    await api.put(`/business-hours/${ruleId}/schedule`, { schedule });
  }
};
```

### Call Logs Service
**Location**: `frontend/src/services/callLogs.service.ts`

Call history with advanced filtering.

**Additional Methods**:
```typescript
export const callLogsService = {
  async getCallLogs(params: CallLogFilters): Promise<PaginatedResponse<CallLog>> {
    const response = await api.get('/call-logs', { params });
    return response.data;
  },
  
  async getCallDetailRecords(params: CallLogFilters): Promise<PaginatedResponse<CallDetailRecord>> {
    const response = await api.get('/call-detail-records', { params });
    return response.data;
  },
  
  async exportCallLogs(params: CallLogFilters): Promise<Blob> {
    const response = await api.get('/call-logs/export', {
      params,
      responseType: 'blob'
    });
    return response.data;
  }
};
```

## Real-time Services

### WebSocket Service
**Location**: `frontend/src/services/websocket.service.ts`

WebSocket connection management for real-time updates.

**Features**:
- Automatic reconnection
- Connection state management
- Event subscription
- Heartbeat monitoring

**Implementation**:
```typescript
class WebSocketService {
  private ws: WebSocket | null = null;
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 5;
  private reconnectInterval = 1000;
  
  connect(url: string): void {
    try {
      this.ws = new WebSocket(url);
      
      this.ws.onopen = () => {
        this.reconnectAttempts = 0;
        this.emit('connected');
      };
      
      this.ws.onmessage = (event) => {
        const data = JSON.parse(event.data);
        this.emit(data.event, data.payload);
      };
      
      this.ws.onclose = () => {
        this.handleReconnect();
      };
      
      this.ws.onerror = (error) => {
        console.error('WebSocket error:', error);
      };
    } catch (error) {
      this.handleReconnect();
    }
  }
  
  private handleReconnect(): void {
    if (this.reconnectAttempts < this.maxReconnectAttempts) {
      this.reconnectAttempts++;
      setTimeout(() => {
        this.connect(this.url);
      }, this.reconnectInterval * this.reconnectAttempts);
    } else {
      this.emit('connectionFailed');
    }
  }
}
```

### Echo Service
**Location**: `frontend/src/services/echo.service.ts`

Laravel Echo integration for broadcasting.

**Features**:
- Private channel subscription
- Presence channel support
- Event listening
- Authentication handling

### Session Updates Service
**Location**: `frontend/src/services/sessionUpdates.service.ts`

Real-time call state monitoring.

**Methods**:
```typescript
export const sessionUpdatesService = {
  subscribeToCallUpdates(callId: string, callback: (update: SessionUpdate) => void): () => void {
    // Subscribe to WebSocket events
    const unsubscribe = websocketService.on(`call.${callId}.update`, callback);
    return unsubscribe;
  },
  
  subscribeToPresenceUpdates(callback: (presence: PresenceUpdate) => void): () => void {
    const unsubscribe = websocketService.on('presence.update', callback);
    return unsubscribe;
  }
};
```

## Utility Services

### Sentry Service
**Location**: `frontend/src/services/sentry.service.ts`

Error tracking and monitoring.

**Features**:
- Error capture and reporting
- User context attachment
- Performance monitoring
- Release tracking

### Cloudonix Service
**Location**: `frontend/src/services/cloudonix.service.ts`

Cloudonix API integration for frontend operations.

**Methods**:
```typescript
export const cloudonixService = {
  async getDomains(): Promise<Domain[]> {
    const response = await api.get('/cloudonix/domains');
    return response.data.data;
  },
  
  async syncExtensions(): Promise<void> {
    await api.post('/cloudonix/sync-extensions');
  },
  
  async testConnection(): Promise<ConnectionTestResult> {
    const response = await api.get('/cloudonix/test-connection');
    return response.data.data;
  }
};
```

## Service Patterns

### Error Handling
Consistent error handling across services:

```typescript
export const handleApiError = (error: AxiosError): never => {
  if (error.response?.status === 422) {
    // Validation error
    throw new ValidationError(error.response.data.errors);
  }
  
  if (error.response?.status === 403) {
    // Permission error
    throw new PermissionError(error.response.data.message);
  }
  
  if (error.response?.status >= 500) {
    // Server error
    throw new ServerError('Internal server error');
  }
  
  // Network error
  throw new NetworkError('Network connection failed');
};
```

### Request/Response Transformation
Data transformation for API consistency:

```typescript
export const transformUserResponse = (user: ApiUser): User => ({
  id: user.id,
  name: user.name,
  email: user.email,
  role: user.role,
  extension: user.extension ? transformExtensionResponse(user.extension) : null,
  createdAt: new Date(user.created_at),
  updatedAt: new Date(user.updated_at),
});

export const transformUserRequest = (user: User): ApiUserRequest => ({
  name: user.name,
  email: user.email,
  role: user.role,
  extension_id: user.extension?.id,
});
```

### Pagination Handling
Consistent pagination across list endpoints:

```typescript
export interface PaginatedResponse<T> {
  data: T[];
  meta: {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number;
    to: number;
  };
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
}
```

### Caching Strategy
React Query integration for intelligent caching:

```typescript
// In custom hooks
export const useUsers = (params?: UserFilters) => {
  return useQuery({
    queryKey: ['users', params],
    queryFn: () => usersService.getAll(params),
    staleTime: 5 * 60 * 1000, // 5 minutes
    cacheTime: 10 * 60 * 1000, // 10 minutes
  });
};

export const useCreateUser = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: usersService.create,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
};
```

### Optimistic Updates
Optimistic UI updates for better UX:

```typescript
export const useUpdateUser = () => {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: Partial<User> }) => 
      usersService.update(id, data),
    
    onMutate: async ({ id, data }) => {
      // Cancel outgoing refetches
      await queryClient.cancelQueries({ queryKey: ['users'] });
      
      // Snapshot previous value
      const previousUsers = queryClient.getQueryData(['users']);
      
      // Optimistically update
      queryClient.setQueryData(['users'], (old: User[]) => 
        old.map(user => user.id === id ? { ...user, ...data } : user)
      );
      
      return { previousUsers };
    },
    
    onError: (err, variables, context) => {
      // Revert on error
      if (context?.previousUsers) {
        queryClient.setQueryData(['users'], context.previousUsers);
      }
    },
    
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
};
```

This service layer provides a robust, consistent API for the React frontend with proper error handling, caching, and real-time capabilities.