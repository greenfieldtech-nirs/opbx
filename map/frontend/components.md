# Frontend Component Architecture

## Overview

The React frontend uses a component-based architecture with clear separation between feature components, design system components, and UI primitives. Components follow consistent patterns for maintainability and reusability.

## Directory Structure

```
frontend/src/components/
├── Auth/                    # Authentication components
├── BusinessHours/          # Business hours management
├── CallLogs/              # Call history components
├── DIDs/                   # DID management components
├── Dashboard/             # Dashboard widgets
├── Extensions/            # Extension management
├── Layout/                # App shell components
├── LiveCalls/             # Real-time call display
├── PhoneNumbers/          # Phone number components
├── RingGroups/            # Ring group management
├── Users/                 # User management components
├── design-system/         # Shared design system
└── ui/                    # Low-level UI primitives
```

## Feature Components

### Authentication Components
**Location**: `frontend/src/components/Auth/`

#### ProtectedRoute
Role-based route protection with fallbacks.

```tsx
<ProtectedRoute requiredRole="admin">
  <AdminPage />
</ProtectedRoute>
```

**Features**:
- Role validation
- Redirect to login
- Permission checking
- Loading states

#### OwnerRoute
Owner-only access control.

```tsx
<OwnerRoute>
  <SettingsPage />
</OwnerRoute>
```

### Layout Components
**Location**: `frontend/src/components/Layout/`

#### AppLayout
Main application shell with navigation.

```tsx
<AppLayout>
  <DashboardContent />
</AppLayout>
```

**Features**:
- Responsive sidebar
- Header with user menu
- Breadcrumb navigation
- Mobile-friendly drawer

#### Sidebar
Navigation menu with role-based visibility.

```tsx
<Sidebar
  navigation={navigationItems}
  currentPath={location.pathname}
/>
```

**Features**:
- Collapsible on mobile
- Active state highlighting
- Icon-based navigation
- Role filtering

#### Header
Top navigation bar with user actions.

```tsx
<Header
  user={currentUser}
  onLogout={handleLogout}
/>
```

**Features**:
- User avatar and name
- Organization selector
- Notification bell
- Logout button

### User Management Components
**Location**: `frontend/src/components/Users/`

#### UserForm
Create/edit user form with validation.

```tsx
<UserForm
  user={editingUser}
  onSubmit={handleSubmit}
  onCancel={handleCancel}
/>
```

**Features**:
- Form validation
- Role selection
- Extension assignment
- Password management

#### UserTable
Data table for user listing.

```tsx
<UserTable
  users={users}
  onEdit={handleEdit}
  onDelete={handleDelete}
  loading={isLoading}
/>
```

**Features**:
- Sorting and filtering
- Bulk actions
- Status indicators
- Pagination

### Extension Components
**Location**: `frontend/src/components/Extensions/`

#### ExtensionCard
Extension information display card.

```tsx
<ExtensionCard
  extension={extension}
  onEdit={handleEdit}
  onRegeneratePassword={handleRegenerate}
/>
```

**Features**:
- Status indicators
- Quick actions
- SIP credentials display

#### ExtensionForm
Extension configuration form.

```tsx
<ExtensionForm
  extension={editingExtension}
  users={availableUsers}
  onSubmit={handleSubmit}
/>
```

**Features**:
- Extension number validation
- User assignment
- Type selection

### Ring Group Components
**Location**: `frontend/src/components/RingGroups/`

#### RingGroupForm
Ring group creation and editing.

```tsx
<RingGroupForm
  ringGroup={editingGroup}
  extensions={availableExtensions}
  onSubmit={handleSubmit}
/>
```

**Features**:
- Strategy selection
- Member assignment
- Priority ordering

#### RingGroupStrategySelector
Visual strategy selection interface.

```tsx
<RingGroupStrategySelector
  value={strategy}
  onChange={setStrategy}
/>
```

**Features**:
- Strategy explanations
- Visual representations
- Configuration previews

### Live Calls Components
**Location**: `frontend/src/components/LiveCalls/`

#### LiveCallList
Real-time active call display.

```tsx
<LiveCallList
  calls={activeCalls}
  onCallAction={handleCallAction}
/>
```

**Features**:
- Real-time updates
- Call state indicators
- Action buttons

#### LiveCallCard
Individual call information card.

```tsx
<LiveCallCard
  call={call}
  showControls={hasPermissions}
/>
```

**Features**:
- Call duration
- Participant info
- Status badges

### Business Hours Components
**Location**: `frontend/src/components/BusinessHours/`

#### BusinessHoursForm
Time-based routing configuration.

```tsx
<BusinessHoursForm
  rule={editingRule}
  onSubmit={handleSubmit}
/>
```

**Features**:
- Schedule builder
- Timezone selection
- Exception handling

#### ScheduleBuilder
Visual time range configuration.

```tsx
<ScheduleBuilder
  schedule={schedule}
  onChange={setSchedule}
/>
```

**Features**:
- Drag-and-drop time selection
- Day-of-week toggles
- Time validation

## Design System Components

### Shared Components
**Location**: `frontend/src/components/design-system/`

#### EmptyState (MANDATORY)
Consistent empty state pattern used across all feature pages.

```tsx
<EmptyState
  icon={<FeatureIcon />}
  title="No items found"
  description="Get started by creating your first item"
  action={
    <Button onClick={openCreateDialog}>
      Create Item
    </Button>
  }
/>
```

**Required Elements**:
1. Large icon (h-12 w-12 mx-auto text-muted-foreground mb-4)
2. Heading (text-lg font-semibold mb-2)
3. Contextual message (text-muted-foreground mb-4)
4. Optional CTA button (when no filters active)

**Filter-Aware Messages**:
```tsx
<p className="text-muted-foreground mb-4">
  {hasActiveFilters
    ? 'Try adjusting your filters'
    : 'Get started by creating your first item'}
</p>
```

#### LoadingSpinner
Consistent loading indicators.

```tsx
<LoadingSpinner size="sm" />
<LoadingSpinner size="md" />
<LoadingSpinner size="lg" />
```

#### ConfirmDialog
Confirmation dialogs for destructive actions.

```tsx
<ConfirmDialog
  open={showConfirm}
  title="Delete Item"
  description="This action cannot be undone"
  onConfirm={handleDelete}
  onCancel={() => setShowConfirm(false)}
/>
```

#### StatCard
Statistics display cards for dashboard.

```tsx
<StatCard
  title="Total Users"
  value={userCount}
  icon={<UserIcon />}
  trend="+12%"
/>
```

#### CallStatusBadge
Call state indicators with colors.

```tsx
<CallStatusBadge status="ringing" />
<CallStatusBadge status="connected" />
<CallStatusBadge status="completed" />
```

#### ActiveCallCard
Active call information display.

```tsx
<ActiveCallCard
  call={call}
  showDuration={true}
/>
```

#### ExtensionCard
Extension status and information.

```tsx
<ExtensionCard
  extension={extension}
  showActions={true}
/>
```

## UI Primitives

### Form Components
**Location**: `frontend/src/components/ui/`

#### Button
Consistent button component with variants.

```tsx
<Button variant="default">Primary</Button>
<Button variant="destructive">Delete</Button>
<Button variant="outline">Secondary</Button>
<Button variant="ghost">Ghost</Button>
```

#### Input
Text input with validation states.

```tsx
<Input
  placeholder="Enter value"
  error={hasError}
  helperText={errorMessage}
/>
```

#### Select
Dropdown selection component.

```tsx
<Select value={value} onValueChange={setValue}>
  <SelectTrigger>
    <SelectValue placeholder="Select option" />
  </SelectTrigger>
  <SelectContent>
    <SelectItem value="option1">Option 1</SelectItem>
    <SelectItem value="option2">Option 2</SelectItem>
  </SelectContent>
</Select>
```

#### Textarea
Multi-line text input.

```tsx
<Textarea
  placeholder="Enter description"
  rows={4}
/>
```

### Data Display

#### Table
Data table with sorting and pagination.

```tsx
<Table>
  <TableHeader>
    <TableRow>
      <TableHead>Name</TableHead>
      <TableHead>Email</TableHead>
    </TableRow>
  </TableHeader>
  <TableBody>
    {items.map(item => (
      <TableRow key={item.id}>
        <TableCell>{item.name}</TableCell>
        <TableCell>{item.email}</TableCell>
      </TableRow>
    ))}
  </TableBody>
</Table>
```

#### Card
Content container with header and body.

```tsx
<Card>
  <CardHeader>
    <CardTitle>Card Title</CardTitle>
    <CardDescription>Card description</CardDescription>
  </CardHeader>
  <CardContent>
    <p>Card content</p>
  </CardContent>
</Card>
```

#### Badge
Status and label indicators.

```tsx
<Badge variant="default">Default</Badge>
<Badge variant="secondary">Secondary</Badge>
<Badge variant="destructive">Error</Badge>
<Badge variant="outline">Outline</Badge>
```

### Layout Components

#### Dialog
Modal dialog component.

```tsx
<Dialog open={open} onOpenChange={setOpen}>
  <DialogContent>
    <DialogHeader>
      <DialogTitle>Dialog Title</DialogTitle>
      <DialogDescription>Dialog description</DialogDescription>
    </DialogHeader>
    <div>Dialog content</div>
    <DialogFooter>
      <Button variant="outline">Cancel</Button>
      <Button>Confirm</Button>
    </DialogFooter>
  </DialogContent>
</Dialog>
```

#### Tabs
Tab navigation component.

```tsx
<Tabs value={activeTab} onValueChange={setActiveTab}>
  <TabsList>
    <TabsTrigger value="tab1">Tab 1</TabsTrigger>
    <TabsTrigger value="tab2">Tab 2</TabsTrigger>
  </TabsList>
  <TabsContent value="tab1">Tab 1 content</TabsContent>
  <TabsContent value="tab2">Tab 2 content</TabsContent>
</Tabs>
```

#### Accordion
Collapsible content sections.

```tsx
<Accordion type="single" collapsible>
  <AccordionItem value="item-1">
    <AccordionTrigger>Section 1</AccordionTrigger>
    <AccordionContent>Content 1</AccordionContent>
  </AccordionItem>
</Accordion>
```

### Feedback Components

#### Alert
Status messages and notifications.

```tsx
<Alert>
  <AlertCircle className="h-4 w-4" />
  <AlertTitle>Alert Title</AlertTitle>
  <AlertDescription>Alert description</AlertDescription>
</Alert>
```

#### Toast
Non-intrusive notifications.

```tsx
// Usage with toast hook
const { toast } = useToast();

toast({
  title: "Success",
  description: "Operation completed successfully",
});
```

#### Skeleton
Loading state placeholders.

```tsx
<Skeleton className="h-4 w-[250px]" />
<Skeleton className="h-4 w-[200px]" />
<Skeleton className="h-4 w-[150px]" />
```

## Component Patterns

### Composition over Inheritance
Components use composition for flexibility:

```tsx
function UserCard({ user, actions }) {
  return (
    <Card>
      <CardHeader>
        <CardTitle>{user.name}</CardTitle>
        <CardDescription>{user.email}</CardDescription>
      </CardHeader>
      <CardContent>
        {actions && <div className="flex gap-2">{actions}</div>}
      </CardContent>
    </Card>
  );
}

// Usage
<UserCard
  user={user}
  actions={
    <>
      <Button onClick={handleEdit}>Edit</Button>
      <Button variant="destructive" onClick={handleDelete}>Delete</Button>
    </>
  }
/>
```

### Controlled Components
Form components are controlled:

```tsx
function UserForm({ user, onSubmit }) {
  const [formData, setFormData] = useState(user);
  
  const handleSubmit = (e) => {
    e.preventDefault();
    onSubmit(formData);
  };
  
  return (
    <form onSubmit={handleSubmit}>
      <Input
        value={formData.name}
        onChange={(e) => setFormData({...formData, name: e.target.value})}
      />
    </form>
  );
}
```

### Custom Hooks Integration
Components use custom hooks for logic:

```tsx
function UsersPage() {
  const { users, createUser, updateUser, deleteUser } = useUsers();
  const { toast } = useToast();
  
  const handleCreate = async (data) => {
    try {
      await createUser(data);
      toast({ title: "User created successfully" });
    } catch (error) {
      toast({ title: "Failed to create user", variant: "destructive" });
    }
  };
  
  return <UsersTable users={users} onCreate={handleCreate} />;
}
```

### Accessibility
All components include accessibility features:

```tsx
<Button aria-label="Delete user">
  <TrashIcon aria-hidden="true" />
</Button>

<Input
  aria-describedby="email-error"
  aria-invalid={hasError}
/>
<div id="email-error" role="alert">
  {errorMessage}
</div>
```

### Theming
Components support theme variants:

```tsx
// Theme-aware styling
<div className="bg-background text-foreground">
  <Button className="bg-primary text-primary-foreground hover:bg-primary/90">
    Themed Button
  </Button>
</div>
```

This component architecture provides a scalable, maintainable foundation for the PBX management interface with consistent patterns and comprehensive design system coverage.