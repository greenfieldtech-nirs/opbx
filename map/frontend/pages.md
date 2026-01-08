# Frontend Pages Structure

## Overview

The React frontend provides a comprehensive PBX management interface with role-based access control and real-time features. Pages are organized by functionality with consistent navigation and authentication guards.

## Core Application Pages

### Dashboard
**Location**: `frontend/src/pages/Dashboard.tsx`

Main landing page with system overview and key metrics.

**Features**:
- Active call count and status
- Recent call history
- System health indicators
- Quick action buttons
- Real-time call presence updates

**Components Used**:
- `StatCard` - Metric display cards
- `ActiveCallCard` - Live call information
- `CallStatusBadge` - Call state indicators

### Authentication

#### Login Page
**Location**: `frontend/src/pages/Login.tsx`

User authentication with organization selection.

**Features**:
- Email/password authentication
- Organization context selection
- Remember me functionality
- Error handling and validation
- Redirect after login

**Components Used**:
- Form validation with react-hook-form
- Loading states
- Error alerts

### User Management

#### Users Page
**Location**: `frontend/src/pages/Users.tsx`

Complete user management with CRUD operations.

**Features**:
- User listing with search and filters
- Create/edit user forms
- Role assignment (Owner/Admin/Agent/User)
- Extension assignment
- Bulk operations
- Soft delete and restore

**Components Used**:
- `DataTable` - User listing with sorting
- `UserForm` - Create/edit form
- `ConfirmDialog` - Delete confirmations
- `EmptyState` - No users state

**Permissions**:
- Owner/Admin: Full CRUD
- Agent: View only
- User: View own profile only

### Extension Management

#### Extensions Page
**Location**: `frontend/src/pages/Extensions.tsx`

SIP extension configuration and management.

**Features**:
- Extension listing with status
- Create/edit extension forms
- SIP password management
- Bulk password regeneration
- Extension number validation
- Cloudonix synchronization status

**Components Used**:
- `ExtensionCard` - Extension display
- `ExtensionForm` - Configuration form
- `ConfirmDialog` - Delete confirmations

### Phone Numbers (DIDs)

#### PhoneNumbers Page
**Location**: `frontend/src/pages/PhoneNumbers.tsx`

DID assignment and routing configuration.

**Features**:
- Phone number listing
- Routing configuration (extension/ring group/business hours)
- Bulk assignment
- Number portability
- Routing validation

**Components Used**:
- `PhoneNumberDialog` - Assignment form
- `RoutingSelector` - Destination selection

### Ring Groups

#### RingGroups Page
**Location**: `frontend/src/pages/RingGroups.tsx`

Call distribution group management.

**Features**:
- Ring group listing with member counts
- Strategy selection (simultaneous/round-robin)
- Member management with priorities
- Ring timeout configuration
- Member availability status

**Components Used**:
- `RingGroupForm` - Group configuration
- `RingGroupStrategySelector` - Strategy UI
- `MemberList` - Extension assignment

### Business Hours

#### BusinessHours Page
**Location**: `frontend/src/pages/BusinessHours.tsx`

Time-based routing configuration.

**Features**:
- Business hours rule listing
- Schedule builder with time ranges
- Day-of-week configuration
- Exception handling
- Timezone management

**Components Used**:
- `BusinessHoursForm` - Rule creation
- `ScheduleBuilder` - Time range UI
- `TimezoneSelector` - Timezone selection

### Call Management

#### CallLogs Page
**Location**: `frontend/src/pages/CallLogs.tsx`

Historical call records and search.

**Features**:
- Call history with filtering
- Date range selection
- Search by number or extension
- Call detail view
- Export functionality
- Pagination with large datasets

**Components Used**:
- `CallLogTable` - Data table with sorting
- `CallDetailModal` - Detailed call view
- `DateRangePicker` - Date filtering

#### LiveCalls Page
**Location**: `frontend/src/pages/LiveCalls.tsx`

Real-time active call monitoring.

**Features**:
- Live call listing
- Call state updates (ringing, connected, etc.)
- Call control actions (if permitted)
- Real-time statistics
- Call duration tracking

**Components Used**:
- `LiveCallList` - Real-time call display
- `LiveCallCard` - Individual call details
- `CallControls` - Action buttons

### Advanced Features

#### ConferenceRooms Page
**Location**: `frontend/src/pages/ConferenceRooms.tsx`

Meeting room configuration.

**Features**:
- Conference room listing
- PIN management
- Participant limits
- Extension assignment
- Active conference monitoring

**Components Used**:
- `ConferenceRoomForm` - Room configuration
- `PinGenerator` - Secure PIN creation

#### IVRMenus Page
**Location**: `frontend/src/pages/IVRMenus.tsx`

Interactive voice response configuration.

**Features**:
- IVR menu listing
- Menu option configuration
- DTMF digit mapping
- Destination selection
- Voice prompt management

**Components Used**:
- `IvrMenuForm` - Menu creation
- `OptionBuilder` - DTMF configuration
- `DestinationSelector` - Routing targets

#### Recordings Page
**Location**: `frontend/src/pages/Recordings.tsx`

Call recording management with MinIO storage integration.

**Features**:
- Recording listing with real-time playback
- Search and filtering by type/status
- Secure download with correct filenames
- Support for uploaded files (WAV/MP3) and remote URLs
- MinIO S3-compatible storage backend
- Temporary signed URLs for secure access

**Components Used**:
- `RecordingPlayer` - Audio playback using temporary URLs
- `RecordingTable` - File listing with metadata
- `DownloadButton` - Secure file download with proper filenames

**Storage Architecture**:
- Files stored in MinIO object storage
- Temporary signed URLs for playback (10-minute expiry)
- Temporary signed URLs for download (30-minute expiry)
- Support for both uploaded files and remote URL references
- Automatic filename preservation and MIME type detection

### Administration

#### Settings Page
**Location**: `frontend/src/pages/Settings.tsx`

Organization-level configuration (Owner only).

**Features**:
- Cloudonix integration settings
- Organization preferences
- API key management
- Webhook configuration
- System health checks

**Components Used**:
- `SettingsForm` - Configuration forms
- `ApiKeyManager` - Key rotation
- `HealthCheck` - System status

## Page Architecture Patterns

### Layout Structure
All pages follow consistent layout:

```tsx
// Page component structure
function UsersPage() {
  return (
    <PageLayout>
      <PageHeader title="Users" actions={<CreateButton />} />
      <PageContent>
        <Filters />
        <DataTable />
        <Pagination />
      </PageContent>
    </PageLayout>
  );
}
```

### Authentication Guards
Role-based page access:

```tsx
// Owner-only pages
function SettingsPage() {
  return (
    <OwnerRoute>
      <SettingsContent />
    </OwnerRoute>
  );
}

// Feature-based access
function LiveCallsPage() {
  return (
    <ProtectedRoute requiredRole="agent">
      <LiveCallsContent />
    </ProtectedRoute>
  );
}
```

### Data Loading Patterns
Consistent data fetching:

```tsx
function UsersPage() {
  const { data: users, isLoading } = useUsers();
  
  if (isLoading) return <LoadingSpinner />;
  
  return (
    <UsersTable users={users} />
  );
}
```

### Error Handling
Global error boundaries with fallbacks:

```tsx
function UsersPage() {
  const { data, error } = useUsers();
  
  if (error) {
    return <ErrorState message="Failed to load users" />;
  }
  
  return <UsersTable users={data} />;
}
```

### Real-time Updates
WebSocket integration for live data:

```tsx
function LiveCallsPage() {
  const { calls } = useCallPresence();
  
  // Automatic UI updates when calls change
  return <LiveCallList calls={calls} />;
}
```

## Navigation Structure

### Sidebar Navigation
Role-based menu items:

```tsx
const navigation = [
  { name: 'Dashboard', href: '/dashboard', icon: HomeIcon },
  { name: 'Users', href: '/users', roles: ['owner', 'admin'] },
  { name: 'Extensions', href: '/extensions', roles: ['owner', 'admin', 'agent'] },
  // ... more items
];
```

### Breadcrumb Navigation
Context-aware breadcrumbs:

```tsx
function UsersPage() {
  return (
    <Breadcrumb>
      <BreadcrumbItem href="/dashboard">Dashboard</BreadcrumbItem>
      <BreadcrumbItem current>Users</BreadcrumbItem>
    </Breadcrumb>
  );
}
```

## Responsive Design

### Breakpoint Strategy
Mobile-first responsive design:

```tsx
// Component responsiveness
function UsersTable({ users }) {
  return (
    <div className="hidden md:block">
      <DesktopTable users={users} />
    </div>
    <div className="md:hidden">
      <MobileCards users={users} />
    </div>
  );
}
```

### Mobile Optimizations
- Touch-friendly interfaces
- Collapsible navigation
- Optimized forms for mobile
- Swipe gestures where appropriate

## Performance Considerations

### Code Splitting
Lazy-loaded pages for better initial load:

```tsx
const UsersPage = lazy(() => import('./pages/Users'));
const RingGroupsPage = lazy(() => import('./pages/RingGroups'));

// In router
<Route path="/users" element={<UsersPage />} />
```

### Virtualization
Large lists use virtualization:

```tsx
function CallLogsPage() {
  return (
    <VirtualizedTable
      items={callLogs}
      itemHeight={50}
      containerHeight={600}
    />
  );
}
```

### Caching Strategy
React Query for intelligent caching:

```tsx
const { data: users } = useQuery({
  queryKey: ['users', filters],
  queryFn: () => usersApi.getAll(filters),
  staleTime: 5 * 60 * 1000, // 5 minutes
});
```

This page structure provides a comprehensive, user-friendly interface for PBX management with proper security, performance, and user experience considerations.