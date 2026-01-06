# Frontend Architecture Patterns

## Overview

The React frontend follows modern patterns with TypeScript, component composition, and intelligent state management. The architecture emphasizes reusability, performance, and developer experience.

## Core Architecture Principles

### 1. Component-Based Architecture

#### Feature-First Organization
Components organized by business domain:

```
components/
├── Users/          # User management components
├── Extensions/     # Extension components
├── RingGroups/     # Ring group components
├── LiveCalls/      # Real-time call components
├── design-system/  # Shared UI components
└── ui/            # Low-level primitives
```

#### Component Composition
Prefer composition over inheritance:

```tsx
// Good: Composable components
function UserCard({ user, actions, showDetails = true }) {
  return (
    <Card>
      <CardHeader>
        <UserHeader user={user} />
      </CardHeader>
      {showDetails && <UserDetails user={user} />}
      {actions && <CardFooter>{actions}</CardFooter>}
    </Card>
  );
}

// Usage
<UserCard
  user={user}
  actions={<EditButton onClick={handleEdit} />}
  showDetails={isExpanded}
/>
```

### 2. State Management Strategy

#### React Query for Server State
Server state managed with React Query:

```tsx
// Server state (API data)
const { data: users, isLoading, error } = useQuery({
  queryKey: ['users', filters],
  queryFn: () => usersApi.getAll(filters),
  staleTime: 5 * 60 * 1000, // 5 minutes
});

// Mutations with optimistic updates
const createUserMutation = useMutation({
  mutationFn: usersApi.create,
  onSuccess: () => {
    queryClient.invalidateQueries({ queryKey: ['users'] });
    toast.success('User created');
  },
});
```

#### Context for Client State
Client state managed with React Context:

```tsx
// Auth context for global state
const AuthContext = createContext<AuthContextType | null>(null);

function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  
  // Auth logic here
  
  return (
    <AuthContext.Provider value={{ user, login, logout, isLoading }}>
      {children}
    </AuthContext.Provider>
  );
}
```

#### Local Component State
Component state with useState/useReducer:

```tsx
// Simple state
const [isOpen, setIsOpen] = useState(false);

// Complex state with reducer
const [formState, dispatch] = useReducer(formReducer, initialState);
```

### 3. Custom Hooks Pattern

#### Data Fetching Hooks
Custom hooks encapsulate data logic:

```tsx
function useUsers(filters?: UserFilters) {
  return useQuery({
    queryKey: ['users', filters],
    queryFn: () => usersService.getAll(filters),
    staleTime: 5 * 60 * 1000,
  });
}

function useCreateUser() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: usersService.create,
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: ['users'] });
    },
  });
}
```

#### UI Logic Hooks
Hooks for component logic:

```tsx
function useLocalStorage<T>(key: string, initialValue: T) {
  const [storedValue, setStoredValue] = useState<T>(() => {
    try {
      const item = window.localStorage.getItem(key);
      return item ? JSON.parse(item) : initialValue;
    } catch (error) {
      return initialValue;
    }
  });
  
  const setValue = (value: T | ((val: T) => T)) => {
    try {
      const valueToStore = value instanceof Function ? value(storedValue) : value;
      setStoredValue(valueToStore);
      window.localStorage.setItem(key, JSON.stringify(valueToStore));
    } catch (error) {
      console.error(error);
    }
  };
  
  return [storedValue, setValue] as const;
}
```

#### Real-time Hooks
WebSocket integration hooks:

```tsx
function useWebSocket(url: string) {
  const [isConnected, setIsConnected] = useState(false);
  const [lastMessage, setLastMessage] = useState(null);
  const ws = useRef<WebSocket | null>(null);
  
  useEffect(() => {
    ws.current = new WebSocket(url);
    
    ws.current.onopen = () => setIsConnected(true);
    ws.current.onmessage = (event) => {
      setLastMessage(JSON.parse(event.data));
    };
    ws.current.onclose = () => setIsConnected(false);
    
    return () => ws.current?.close();
  }, [url]);
  
  const sendMessage = useCallback((message: any) => {
    ws.current?.send(JSON.stringify(message));
  }, []);
  
  return { isConnected, lastMessage, sendMessage };
}
```

### 4. Routing & Navigation

#### React Router Configuration
File-based routing with lazy loading:

```tsx
// Router configuration
const router = createBrowserRouter([
  {
    path: '/',
    element: <AppLayout />,
    children: [
      {
        index: true,
        element: <Navigate to="/dashboard" replace />,
      },
      {
        path: 'dashboard',
        element: (
          <Suspense fallback={<LoadingSpinner />}>
            <DashboardPage />
          </Suspense>
        ),
      },
      {
        path: 'users',
        element: (
          <ProtectedRoute requiredRole="admin">
            <Suspense fallback={<LoadingSpinner />}>
              <UsersPage />
            </Suspense>
          </ProtectedRoute>
        ),
      },
      // ... more routes
    ],
  },
]);
```

#### Route Guards
Authentication and authorization guards:

```tsx
function ProtectedRoute({ 
  children, 
  requiredRole 
}: { 
  children: ReactNode; 
  requiredRole?: Role 
}) {
  const { user, isLoading } = useAuth();
  
  if (isLoading) {
    return <LoadingSpinner />;
  }
  
  if (!user) {
    return <Navigate to="/login" replace />;
  }
  
  if (requiredRole && !user.roles.includes(requiredRole)) {
    return <Navigate to="/unauthorized" replace />;
  }
  
  return <>{children}</>;
}
```

### 5. Form Management

#### React Hook Form Integration
Forms managed with react-hook-form:

```tsx
function UserForm({ user, onSubmit }: UserFormProps) {
  const {
    register,
    handleSubmit,
    formState: { errors, isSubmitting },
    reset,
  } = useForm<UserFormData>({
    defaultValues: user,
  });
  
  const onFormSubmit = async (data: UserFormData) => {
    try {
      await onSubmit(data);
      reset();
    } catch (error) {
      // Handle error
    }
  };
  
  return (
    <form onSubmit={handleSubmit(onFormSubmit)}>
      <Input
        {...register('name', { required: 'Name is required' })}
        error={!!errors.name}
        helperText={errors.name?.message}
      />
      <Button type="submit" disabled={isSubmitting}>
        {isSubmitting ? 'Saving...' : 'Save'}
      </Button>
    </form>
  );
}
```

#### Form Validation
Schema-based validation with Zod:

```tsx
import { z } from 'zod';

const userSchema = z.object({
  name: z.string().min(2, 'Name must be at least 2 characters'),
  email: z.string().email('Invalid email address'),
  role: z.enum(['owner', 'admin', 'agent', 'user']),
});

type UserFormData = z.infer<typeof userSchema>;

function UserForm({ onSubmit }: { onSubmit: (data: UserFormData) => void }) {
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<UserFormData>({
    resolver: zodResolver(userSchema),
  });
  
  return (
    <form onSubmit={handleSubmit(onSubmit)}>
      {/* Form fields */}
    </form>
  );
}
```

### 6. Error Handling

#### Error Boundaries
Component-level error catching:

```tsx
class ErrorBoundary extends Component {
  constructor(props: ErrorBoundaryProps) {
    super(props);
    this.state = { hasError: false, error: null };
  }
  
  static getDerivedStateFromError(error: Error) {
    return { hasError: true, error };
  }
  
  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    // Log error to service
    errorReportingService.captureException(error, { errorInfo });
  }
  
  render() {
    if (this.state.hasError) {
      return <ErrorFallback error={this.state.error} />;
    }
    
    return this.props.children;
  }
}
```

#### Global Error Handling
API error handling with toast notifications:

```tsx
// In API service
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const message = error.response?.data?.message || 'An error occurred';
    
    toast.error(message);
    
    if (error.response?.status === 401) {
      authService.logout();
    }
    
    return Promise.reject(error);
  }
);
```

### 7. Performance Optimization

#### Code Splitting
Route-based code splitting:

```tsx
const UsersPage = lazy(() => import('./pages/Users'));
const ExtensionsPage = lazy(() => import('./pages/Extensions'));

function App() {
  return (
    <Suspense fallback={<PageSkeleton />}>
      <Routes>
        <Route path="/users" element={<UsersPage />} />
        <Route path="/extensions" element={<ExtensionsPage />} />
      </Routes>
    </Suspense>
  );
}
```

#### Virtualization
Large lists with virtualization:

```tsx
import { FixedSizeList as List } from 'react-window';

function VirtualizedUserList({ users }: { users: User[] }) {
  const Row = ({ index, style }: { index: number; style: React.CSSProperties }) => (
    <div style={style}>
      <UserCard user={users[index]} />
    </div>
  );
  
  return (
    <List
      height={400}
      itemCount={users.length}
      itemSize={80}
      width="100%"
    >
      {Row}
    </List>
  );
}
```

#### Memoization
Prevent unnecessary re-renders:

```tsx
const UserCard = memo(function UserCard({ user }: { user: User }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{user.name}</CardTitle>
        <CardDescription>{user.email}</CardDescription>
      </CardHeader>
    </Card>
  );
});

const UserList = memo(function UserList({ users }: { users: User[] }) {
  const sortedUsers = useMemo(() => 
    [...users].sort((a, b) => a.name.localeCompare(b.name)),
    [users]
  );
  
  return (
    <div>
      {sortedUsers.map(user => (
        <UserCard key={user.id} user={user} />
      ))}
    </div>
  );
});
```

### 8. TypeScript Integration

#### Strict Type Checking
Comprehensive type safety:

```tsx
interface User {
  id: string;
  name: string;
  email: string;
  role: Role;
  extension?: Extension;
  createdAt: Date;
  updatedAt: Date;
}

interface ApiResponse<T> {
  data: T;
  meta?: {
    pagination?: PaginationMeta;
  };
  message?: string;
}

type Role = 'owner' | 'admin' | 'agent' | 'user';

type UserFilters = {
  role?: Role;
  search?: string;
  page?: number;
  perPage?: number;
};
```

#### Generic Components
Type-safe reusable components:

```tsx
interface DataTableProps<T> {
  data: T[];
  columns: Column<T>[];
  onRowClick?: (row: T) => void;
  loading?: boolean;
}

function DataTable<T extends { id: string | number }>({
  data,
  columns,
  onRowClick,
  loading,
}: DataTableProps<T>) {
  // Implementation
}
```

### 9. Testing Strategy

#### Component Testing
Jest + React Testing Library:

```tsx
import { render, screen, fireEvent } from '@testing-library/react';
import { UserCard } from './UserCard';

describe('UserCard', () => {
  it('displays user information', () => {
    const user = { id: '1', name: 'John Doe', email: 'john@example.com' };
    
    render(<UserCard user={user} />);
    
    expect(screen.getByText('John Doe')).toBeInTheDocument();
    expect(screen.getByText('john@example.com')).toBeInTheDocument();
  });
  
  it('calls onEdit when edit button is clicked', () => {
    const user = { id: '1', name: 'John Doe', email: 'john@example.com' };
    const onEdit = jest.fn();
    
    render(<UserCard user={user} onEdit={onEdit} />);
    
    fireEvent.click(screen.getByRole('button', { name: /edit/i }));
    
    expect(onEdit).toHaveBeenCalledWith(user);
  });
});
```

#### Custom Hook Testing
Hook testing with renderHook:

```tsx
import { renderHook, act } from '@testing-library/react';
import { useCounter } from './useCounter';

describe('useCounter', () => {
  it('increments counter', () => {
    const { result } = renderHook(() => useCounter(0));
    
    act(() => {
      result.current.increment();
    });
    
    expect(result.current.count).toBe(1);
  });
});
```

### 10. Accessibility (a11y)

#### ARIA Attributes
Proper accessibility attributes:

```tsx
function AccessibleForm() {
  const [errors, setErrors] = useState<Record<string, string>>({});
  
  return (
    <form role="form" aria-labelledby="form-title">
      <h2 id="form-title">User Information</h2>
      
      <div>
        <label htmlFor="name">Name</label>
        <input
          id="name"
          type="text"
          aria-describedby={errors.name ? "name-error" : undefined}
          aria-invalid={!!errors.name}
        />
        {errors.name && (
          <div id="name-error" role="alert">
            {errors.name}
          </div>
        )}
      </div>
      
      <button type="submit" aria-disabled={isSubmitting}>
        {isSubmitting ? 'Submitting...' : 'Submit'}
      </button>
    </form>
  );
}
```

#### Keyboard Navigation
Full keyboard support:

```tsx
function KeyboardNavigableList({ items, onSelect }: ListProps) {
  const [focusedIndex, setFocusedIndex] = useState(0);
  
  const handleKeyDown = (event: KeyboardEvent) => {
    switch (event.key) {
      case 'ArrowDown':
        setFocusedIndex(prev => Math.min(prev + 1, items.length - 1));
        break;
      case 'ArrowUp':
        setFocusedIndex(prev => Math.max(prev - 1, 0));
        break;
      case 'Enter':
        onSelect(items[focusedIndex]);
        break;
    }
  };
  
  return (
    <ul role="listbox" onKeyDown={handleKeyDown} tabIndex={0}>
      {items.map((item, index) => (
        <li
          key={item.id}
          role="option"
          aria-selected={index === focusedIndex}
          className={index === focusedIndex ? 'focused' : ''}
        >
          {item.name}
        </li>
      ))}
    </ul>
  );
}
```

This architecture provides a solid foundation for building maintainable, accessible, and performant React applications with clear patterns and best practices.