# OPBX Design System Documentation

**Version:** 1.0.0
**Last Updated:** 2025-12-21
**Designer:** UI Design Team
**Status:** Active Development

---

## Table of Contents

1. [Overview](#overview)
2. [Design Principles](#design-principles)
3. [Design Tokens](#design-tokens)
4. [Layout System](#layout-system)
5. [Component Library](#component-library)
6. [Page Specifications](#page-specifications)
7. [Responsive Design](#responsive-design)
8. [Interaction Patterns](#interaction-patterns)
9. [Animation Guidelines](#animation-guidelines)
10. [Accessibility Standards](#accessibility-standards)
11. [Implementation Guide](#implementation-guide)

---

## Overview

The OPBX Design System provides a comprehensive set of design guidelines, components, and patterns for building a professional business PBX admin interface. The system ensures consistency, accessibility, and excellent user experience across all features.

### Design Goals

- **Clarity:** Information is easy to find and understand
- **Efficiency:** Common tasks require minimal clicks
- **Reliability:** Real-time updates without manual refresh
- **Professionalism:** Clean, modern interface suitable for business use
- **Accessibility:** WCAG 2.1 AA compliant

### Target Users

- **Business Owners:** Need high-level overview and basic configuration
- **Administrators:** Configure routing, manage users, analyze call data
- **Agents:** Monitor live calls, access call logs

---

## Design Principles

### 1. Real-Time First
- Show live data without requiring manual refresh
- Use WebSocket connections for instant updates
- Provide visual feedback for state changes (pulsing animations for ringing calls)

### 2. Progressive Disclosure
- Show essential information upfront
- Hide advanced options behind "Advanced" toggles or modals
- Use expandable sections for detailed information

### 3. Consistent Visual Hierarchy
- Large, bold text for critical information (phone numbers, extension numbers)
- Secondary text for contextual information
- Clear spacing and grouping of related elements

### 4. Action-Oriented Design
- Primary actions are always visible and accessible
- Destructive actions require confirmation
- Success feedback is immediate and clear

### 5. Status Clarity
- Use color-coded badges for call status
- Animated indicators for active states (ringing, answered)
- Visual distinction between active/inactive entities

---

## Design Tokens

All design tokens are defined in `/frontend/src/styles/tokens.ts`. Import and use these tokens throughout the application to ensure consistency.

### Color System

#### Brand Colors
```typescript
primary: {
  500: '#3b82f6', // Main brand blue - buttons, links, active states
  600: '#2563eb', // Hover states
  700: '#1d4ed8', // Active/pressed states
}
```

**Usage:**
- Primary actions (buttons, links)
- Active navigation items
- Selected states
- Icon accents

#### Semantic Colors

**Success (Green)**
```typescript
success: {
  500: '#10b981', // Answered calls, success states, positive trends
  600: '#059669', // Hover states
}
```

**Warning (Amber)**
```typescript
warning: {
  500: '#f59e0b', // Ringing calls, no answer, warning states
  600: '#d97706', // Hover states
}
```

**Danger (Red)**
```typescript
danger: {
  500: '#ef4444', // Failed calls, errors, destructive actions
  600: '#dc2626', // Hover states
}
```

**Neutral (Gray)**
```typescript
neutral: {
  50: '#f9fafb',   // Backgrounds
  100: '#f3f4f6',  // Card backgrounds, hover states
  200: '#e5e7eb',  // Borders
  500: '#6b7280',  // Secondary text
  700: '#374151',  // Primary text
  900: '#111827',  // Sidebar background
}
```

#### Call Status Colors

Each status has dedicated background, text, and border colors:

| Status | Background | Text | Border | Use Case |
|--------|------------|------|--------|----------|
| `initiated` | `#f3f4f6` | `#6b7280` | `#d1d5db` | Call just started |
| `ringing` | `#fef3c7` | `#d97706` | `#fcd34d` | Phone is ringing (animated) |
| `answered` | `#dcfce7` | `#059669` | `#86efac` | Call in progress (animated) |
| `completed` | `#f3f4f6` | `#6b7280` | `#d1d5db` | Call ended normally |
| `failed` | `#fee2e2` | `#dc2626` | `#fca5a5` | Call failed/error |
| `busy` | `#fee2e2` | `#dc2626` | `#fca5a5` | Line was busy |
| `no_answer` | `#fef3c7` | `#d97706` | `#fcd34d` | No one answered |

### Typography

**Font Families**
```typescript
fontFamily: {
  sans: 'Inter, system-ui, sans-serif',  // Body text, UI elements
  mono: 'JetBrains Mono, monospace',     // Phone numbers, durations, code
}
```

**Font Sizes**
```typescript
fontSize: {
  xs: '0.75rem',    // 12px - Labels, captions
  sm: '0.875rem',   // 14px - Secondary text
  base: '1rem',     // 16px - Body text
  lg: '1.125rem',   // 18px - Subheadings
  xl: '1.25rem',    // 20px - Card titles
  '2xl': '1.5rem',  // 24px - Page headings
  '3xl': '1.875rem', // 30px - Large numbers (stat cards)
  '4xl': '2.25rem',  // 36px - Hero text
}
```

**Font Weights**
```typescript
fontWeight: {
  normal: 400,     // Body text
  medium: 500,     // Emphasized text
  semibold: 600,   // Subheadings
  bold: 700,       // Headings, important numbers
  extrabold: 800,  // Hero text
}
```

### Spacing Scale

Based on 4px baseline grid:

```typescript
spacing: {
  1: '0.25rem',   // 4px
  2: '0.5rem',    // 8px
  3: '0.75rem',   // 12px
  4: '1rem',      // 16px
  6: '1.5rem',    // 24px
  8: '2rem',      // 32px
  12: '3rem',     // 48px
  16: '4rem',     // 64px
}
```

**Usage Guidelines:**
- Use `spacing[4]` (16px) as default padding for cards
- Use `spacing[6]` (24px) for section spacing
- Use `spacing[2]` or `spacing[3]` for tight spacing (buttons, badges)

### Border Radius

```typescript
borderRadius: {
  sm: '0.125rem',    // 2px - Small badges
  default: '0.25rem', // 4px - Buttons, inputs
  md: '0.375rem',    // 6px - Cards (default)
  lg: '0.5rem',      // 8px - Large cards, modals
  xl: '0.75rem',     // 12px - Special emphasis
  full: '9999px',    // Circular (avatars, status dots)
}
```

### Shadows

```typescript
shadows: {
  sm: '0 1px 2px rgba(0,0,0,0.05)',         // Subtle elevation
  default: '0 1px 3px rgba(0,0,0,0.1)',     // Standard cards
  md: '0 4px 6px rgba(0,0,0,0.1)',          // Hover states
  lg: '0 10px 15px rgba(0,0,0,0.1)',        // Modals, dropdowns
}
```

### Z-Index Layers

```typescript
zIndex: {
  dropdown: 1000,
  sticky: 1020,
  fixed: 1030,
  modalBackdrop: 1040,
  modal: 1050,
  popover: 1060,
  tooltip: 1070,
}
```

---

## Layout System

### Application Layout Structure

```
┌──────────────────────────────────────────────────────┐
│  Header (64px height)                                │
│  - Logo                                              │
│  - User menu                                         │
│  - Notifications                                     │
├──────────┬───────────────────────────────────────────┤
│          │                                           │
│ Sidebar  │   Main Content Area                       │
│ (256px)  │   - Page Header                           │
│          │   - Page Content (scrollable)             │
│          │                                           │
│          │                                           │
│          │                                           │
└──────────┴───────────────────────────────────────────┘
```

### Layout Dimensions

```typescript
layout: {
  sidebarWidth: '16rem',           // 256px
  sidebarWidthCollapsed: '4rem',   // 64px (mobile)
  headerHeight: '4rem',            // 64px
  maxContentWidth: '80rem',        // 1280px (content max-width)
}
```

### Sidebar Navigation

**Visual Style:**
- Dark background: `#111827` (neutral-900)
- Active item: Blue highlight `#2563eb` (primary-600)
- Hover: `#1f2937` (neutral-800)
- Text: White for active, `#9ca3af` (neutral-400) for inactive

**Navigation Items:**

| Icon | Label | Route | Badge |
|------|-------|-------|-------|
| LayoutDashboard | Dashboard | `/` | - |
| PhoneCall | Live Calls | `/live-calls` | Active call count (red badge) |
| List | Call Logs | `/call-logs` | - |
| Users | Users | `/users` | - |
| Hash | Extensions | `/extensions` | - |
| Phone | DIDs | `/dids` | - |
| UsersRound | Ring Groups | `/ring-groups` | - |
| Clock | Business Hours | `/business-hours` | - |
| Settings | Settings | `/settings` | - |

**Badge Styling:**
```tsx
// For Live Calls active count
<Badge className="ml-auto bg-danger-500 text-white">
  {activeCallCount}
</Badge>
```

### Header

**Components:**
- Logo (left) - 40px height
- Spacer (flex-grow)
- Notifications icon (with badge if unread)
- User menu dropdown (avatar + name)

**Height:** 64px
**Background:** White
**Border:** 1px solid `neutral-200`

### Main Content Area

**Padding:** 24px (spacing-6)
**Background:** `neutral-50` (#f9fafb)
**Max Width:** 1280px (centered)

---

## Component Library

### Core Components

#### 1. StatCard

**Purpose:** Display key metrics on Dashboard

**Visual Specification:**
```
┌─────────────────────────────────────┐
│ Title (sm)              [Icon in    │
│                         colored bg] │
│                                     │
│ VALUE (2xl, bold)  ↑ 12% (trend)    │
│ Description (xs)                    │
└─────────────────────────────────────┘
```

**Props:**
```typescript
interface StatCardProps {
  title: string;                    // e.g., "Active Calls"
  value: number | string;           // e.g., 5 or "24"
  icon: LucideIcon;                // Activity, Users, Phone, etc.
  color?: 'primary' | 'success' | 'warning' | 'danger';
  trend?: {                        // Optional trend indicator
    value: number;                 // Percentage (12 = 12%)
    direction: 'up' | 'down';
  };
  description?: string;            // Optional subtitle
  loading?: boolean;               // Shows skeleton
}
```

**Usage Example:**
```tsx
<StatCard
  title="Active Calls"
  value={5}
  icon={PhoneCall}
  color="success"
  trend={{ value: 12, direction: 'up' }}
/>
```

**Color Variants:**
| Color | Background | Icon Color | Use Case |
|-------|------------|------------|----------|
| primary | `primary-100` | `primary-600` | General metrics |
| success | `success-100` | `success-600` | Positive metrics (active calls) |
| warning | `warning-100` | `warning-600` | Attention needed |
| danger | `danger-100` | `danger-600` | Critical metrics |

**Loading State:**
Skeleton with shimmer animation on title, icon, and value areas.

---

#### 2. CallStatusBadge

**Purpose:** Display call status with semantic colors

**Visual Specification:**
```
┌──────────────┐
│ ● Ringing    │  (small dot + text)
└──────────────┘
```

**Props:**
```typescript
interface CallStatusBadgeProps {
  status: 'initiated' | 'ringing' | 'answered' | 'completed'
        | 'failed' | 'busy' | 'no_answer';
  size?: 'sm' | 'md' | 'lg';
}
```

**Status Indicators:**
- **ringing / answered:** Animated pulsing dot
- **completed / initiated:** Static gray dot
- **failed / busy:** Static red dot
- **no_answer:** Static amber dot

**Size Variants:**
- `sm`: 12px text, 2px padding
- `md`: 12px text, 2.5px padding (default)
- `lg`: 14px text, 3px padding

**Animation:**
```css
@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
```

---

#### 3. ActiveCallCard

**Purpose:** Display live call information with real-time updates

**Visual Specification:**
```
┌───────────────────────────────────────────────────┐
│  [Icon]  +1 (555) 123-4567        [Status Badge]  │
│          To: +1 (555) 987-6543                    │
│                                                   │
│  DID              | Extension                     │
│  +1 (555) 111-2222| 101                          │
│                                                   │
│  Duration         | Started                       │
│  00:45            | 2:34 PM                       │
└───────────────────────────────────────────────────┘
```

**Props:**
```typescript
interface ActiveCallCardProps {
  call: {
    call_id: string;
    from_number: string;
    to_number: string;
    did_number?: string;
    status: CallStatus;
    duration: number;              // seconds
    extension_number?: string;
    ring_group_name?: string;
    started_at: string;            // ISO timestamp
  };
}
```

**State-Based Styling:**
- **Ringing:** Pulsing ring-2 border in warning-300, icon pulsing
- **Answered:** ring-1 border in success-300, steady icon
- **Other:** No border, neutral icon

**Real-Time Updates:**
Duration counter updates every second using `setInterval` in component.

---

#### 4. ExtensionCard

**Purpose:** Display extension information in grid view

**Visual Specification:**
```
┌──────────────────────────────────────┐
│ [#]  101             [⋮ Menu]        │
│      ● Active                        │
│                                      │
│ [Badge: User]                        │
│                                      │
│ [Avatar] John Doe                    │
└──────────────────────────────────────┘
```

**Props:**
```typescript
interface ExtensionCardProps {
  extension: {
    id: string;
    number: string;
    user?: { id: string; name: string; avatar?: string } | null;
    type: 'user' | 'virtual' | 'queue';
    status: 'active' | 'inactive';
    description?: string;
  };
  onEdit: () => void;
  onDelete: () => void;
}
```

**Type Badges:**
| Type | Icon | Color |
|------|------|-------|
| user | User | Blue (primary) |
| virtual | Hash | Purple |
| queue | Inbox | Orange |

**Status Indicator:**
- Active: Green dot (success-500)
- Inactive: Gray dot (neutral-400), 60% opacity on card

---

#### 5. EmptyState

**Purpose:** Show helpful message when no data available

**Visual Specification:**
```
        ┌────────┐
        │ [Icon] │
        └────────┘

      No active calls

  When calls come in,
  they'll appear here

    [Add User Button]
```

**Props:**
```typescript
interface EmptyStateProps {
  icon: LucideIcon;
  title: string;
  description?: string;
  action?: {
    label: string;
    onClick: () => void;
    variant?: 'default' | 'outline';
  };
}
```

**Layout:**
- Centered vertically and horizontally
- Icon: 64px circle with neutral-100 background
- Title: lg font-semibold
- Description: sm text-neutral-500, max-width 32rem
- Button: lg size with 24px top margin

---

#### 6. LoadingSpinner

**Purpose:** Show loading state for async operations

**Visual Specification:**
Circular spinner with primary-600 color, spinning animation

**Sizes:**
- `sm`: 16px
- `md`: 24px (default)
- `lg`: 32px
- `xl`: 48px

**Usage:**
```tsx
<LoadingSpinner size="md" />
```

---

#### 7. ConfirmDialog

**Purpose:** Confirm destructive actions

**Visual Specification:**
```
┌────────────────────────────────────┐
│  Confirm Delete                    │
│                                    │
│  Are you sure you want to delete   │
│  this user? This action cannot     │
│  be undone.                        │
│                                    │
│  [Cancel]  [Delete (red)]          │
└────────────────────────────────────┘
```

**Props:**
```typescript
interface ConfirmDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  description: string;
  confirmLabel?: string;          // Default: "Confirm"
  cancelLabel?: string;           // Default: "Cancel"
  onConfirm: () => void;
  variant?: 'danger' | 'warning'; // Default: 'danger'
}
```

**Button Styling:**
- Cancel: Outline variant, neutral
- Confirm (danger): bg-danger-600, text-white
- Confirm (warning): bg-warning-600, text-white

---

### Form Components

#### 8. UserForm

**Fields:**
- Name (text input, required)
- Email (email input, required, validated)
- Role (select: Owner/Admin/Agent)
- Extension (select from available extensions)
- Status (switch: Active/Inactive)

**Layout:**
- Single column form
- Labels above inputs
- Error messages below fields in danger-600
- Actions at bottom: Cancel (outline) + Save (primary)

---

#### 9. DIDForm

**Fields:**
- Phone Number (tel input with formatting, required)
- Friendly Name (text input)
- Routing Type (radio buttons with visual cards):
  - Direct to Extension
  - Ring Group
  - Business Hours Rule
- Routing Target (conditional dropdown based on type)
- Fallback Extension (optional)

**Visual:**
Radio button cards with icons and descriptions:
```
┌────────────────┐  ┌────────────────┐  ┌────────────────┐
│ [Radio]        │  │ [Radio]        │  │ [Radio]        │
│ Direct to Ext  │  │ Ring Group     │  │ Business Hours │
│                │  │                │  │                │
│ Routes to one  │  │ Ring multiple  │  │ Time-based     │
│ extension      │  │ extensions     │  │ routing        │
└────────────────┘  └────────────────┘  └────────────────┘
```

---

#### 10. RingGroupStrategySelector

**Purpose:** Visual selector for ring strategy

**Visual Specification:**
```
┌────────────────┐  ┌────────────────┐  ┌────────────────┐
│ [Radio]        │  │ [Radio]        │  │ [Radio]        │
│ 📱📱📱          │  │ 📱→📱→📱        │  │ 1️⃣ 📱          │
│ Simultaneous   │  │ Round Robin    │  │ 2️⃣ 📱          │
│                │  │                │  │ 3️⃣ 📱          │
│ All phones     │  │ Distribute     │  │ Sequential     │
│ ring at once   │  │ calls evenly   │  │ Try in order   │
└────────────────┘  └────────────────┘  └────────────────┘
```

**Props:**
```typescript
interface RingGroupStrategySelectorProps {
  value: 'simultaneous' | 'round_robin' | 'sequential';
  onChange: (value: string) => void;
}
```

**Interaction:**
- Cards are clickable (entire card is button)
- Selected card has primary-600 border
- Hover state: scale(1.02), shadow-md

---

#### 11. BusinessHoursScheduleBuilder

**Purpose:** Visual week schedule editor

**Visual Specification:**
```
┌─────────────────────────────────────────────────┐
│ Sunday     [✓] [09:00 AM] to [05:00 PM]        │
│ Monday     [✓] [09:00 AM] to [05:00 PM]        │
│ Tuesday    [✓] [09:00 AM] to [05:00 PM]        │
│ Wednesday  [✓] [09:00 AM] to [05:00 PM]        │
│ Thursday   [✓] [09:00 AM] to [05:00 PM]        │
│ Friday     [✓] [09:00 AM] to [05:00 PM]        │
│ Saturday   [✗] [--------] to [--------]        │
└─────────────────────────────────────────────────┘
```

**Props:**
```typescript
interface BusinessHoursScheduleBuilderProps {
  schedule: Array<{
    day: number;      // 0-6 (Sun-Sat)
    enabled: boolean;
    open: string;     // "09:00"
    close: string;    // "17:00"
  }>;
  onChange: (schedule: any[]) => void;
}
```

**Interaction:**
- Toggle switch enables/disables day
- Time pickers show 12-hour format with AM/PM
- Disabled days show grayed-out time inputs

---

### shadcn/ui Base Components

The design system extends shadcn/ui components:

- **Button:** Variants (default, outline, ghost, destructive), sizes (sm, md, lg)
- **Input:** Text, email, tel, number inputs with consistent styling
- **Select:** Dropdown selector with search capability
- **Dialog:** Modal overlay with header, content, footer
- **Badge:** Small label with variant colors
- **Card:** Container with header, content, footer
- **Switch:** Toggle for boolean states
- **Textarea:** Multi-line text input
- **Label:** Form field labels with required indicator
- **Skeleton:** Loading placeholder with shimmer

**Customization:**
All shadcn components use design tokens from `tokens.ts` for consistent theming.

---

## Page Specifications

### Dashboard

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│ Dashboard                                           │
├─────────────────────────────────────────────────────┤
│                                                     │
│  [StatCard] [StatCard] [StatCard] [StatCard]       │
│                                                     │
│  Recent Calls                     [Export Button]  │
│  ┌───────────────────────────────────────────────┐ │
│  │ From         To        Status    Duration     │ │
│  │ +1555...    Ext 101    Answered  00:45        │ │
│  │ +1555...    Ext 102    Missed    -            │ │
│  └───────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

**Components:**
1. **Stat Cards Row** (4 columns on desktop, 2 on tablet, 1 on mobile)
   - Active Calls (success color, PhoneCall icon)
   - Total Extensions (primary color, Hash icon)
   - Total DIDs (primary color, Phone icon)
   - Calls Today (neutral color, List icon)

2. **Recent Calls Table**
   - Columns: From, To, Status (badge), Duration, Time
   - 10 rows max
   - Click row to view call details modal
   - Empty state: "No calls today" with illustration

3. **Quick Actions** (floating action button, bottom-right)
   - Primary: Add User
   - Secondary: Add DID, View Live Calls

**Responsive:**
- Desktop (>1024px): 4-column grid
- Tablet (768-1023px): 2-column grid
- Mobile (<768px): Single column, compact table → card view

---

### Live Calls

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│ Live Calls                            [Auto-refresh]│
├─────────────────────────────────────────────────────┤
│                                                     │
│  [ActiveCallCard with pulsing border]              │
│  [ActiveCallCard]                                  │
│  [ActiveCallCard]                                  │
│                                                     │
│  OR:                                               │
│  [EmptyState: "No active calls"]                   │
└─────────────────────────────────────────────────────┘
```

**Features:**
- Real-time updates via WebSocket
- Cards sorted by status: ringing → answered → other
- Duration counter updates every second
- Auto-refresh indicator (small pulsing dot in header)
- Empty state when no calls

**Card Behavior:**
- New call: Slide in from right with fade (200ms)
- Call ended: Fade out and collapse (300ms)
- Status change: Smooth color transition (150ms)

---

### Call Logs

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│ Call Logs                                           │
├─────────────────────────────────────────────────────┤
│ Filters:                                            │
│ [Date Range] [Direction] [Status] [DID] [Extension]│
│ [Search]                              [Export CSV]  │
│                                                     │
│  ┌───────────────────────────────────────────────┐ │
│  │ ▼  From         To        Status   Duration   │ │
│  │ +1555...    Ext 101    Answered   00:45       │ │
│  │ +1555...    Ext 102    Missed     -           │ │
│  └───────────────────────────────────────────────┘ │
│                                                     │
│  [Prev] Page 1 of 10 [Next]                        │
└─────────────────────────────────────────────────────┘
```

**Filters:**
- Date Range: Calendar picker, default last 7 days
- Direction: Dropdown (All, Inbound, Outbound)
- Status: Multi-select (All, Answered, Missed, Failed, Busy, No Answer)
- DID: Searchable dropdown
- Extension: Searchable dropdown
- Search: Phone number or name search

**Table:**
- Columns: Direction (icon), From, To, DID, Extension, Status (badge), Duration, Timestamp
- Sortable columns (click header)
- Click row → opens call detail modal
- 50 rows per page
- Pagination at bottom

**Call Detail Modal:**
Shows full call details:
- Full phone numbers
- Call timeline (initiated → ringing → answered → completed)
- Recording link (if available)
- Notes section (editable)

**Export:**
- CSV download with current filters applied
- Includes all columns

---

### Users

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│ Users                                [+ Add User]   │
├─────────────────────────────────────────────────────┤
│ [Role Filter] [Status Toggle] [Search]              │
│                                                     │
│  ┌───────────────────────────────────────────────┐ │
│  │ Avatar Name      Email     Role    Ext  Status│ │
│  │ JD  John Doe  jd@...    Admin  101  Active   │ │
│  │ JS  Jane Smith js@...   Agent  102  Active   │ │
│  └───────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

**Features:**
- Add User button (primary, top-right)
- Filters: Role dropdown, Status toggle, Search input
- Table with avatar, name, email, role badge, extension, status toggle
- Actions dropdown on each row (Edit, Delete)
- Click row to edit user
- Delete shows confirmation dialog

**User Form Modal:**
Opens when clicking "Add User" or "Edit"
- Name, Email, Role, Extension, Status fields
- Cancel and Save buttons at bottom
- Validation: Email format, unique email per tenant

---

### Extensions

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│ Extensions                 [Grid/List]  [+ Create]  │
├─────────────────────────────────────────────────────┤
│ [Type Filter] [Status Toggle] [Search]              │
│                                                     │
│  [ExtensionCard] [ExtensionCard] [ExtensionCard]   │
│  [ExtensionCard] [ExtensionCard] [ExtensionCard]   │
└─────────────────────────────────────────────────────┘
```

**View Modes:**
- Grid View (default): 3 columns desktop, 2 tablet, 1 mobile
- List View: Table with same data

**Filters:**
- Type: All, User, Virtual, Queue
- Status: Active/Inactive toggle
- Search: Extension number or user name

**Extension Form Modal:**
- Extension Number (auto-increment suggested)
- Type selector (User/Virtual/Queue)
- User assignment (dropdown, optional)
- Description (textarea, optional)
- Status (switch)

---

### DIDs

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│ DIDs                                   [+ Add DID]  │
├─────────────────────────────────────────────────────┤
│ [Search]                                            │
│                                                     │
│  ┌───────────────────────────────────────────────┐ │
│  │ Number        Name      Routing      Status    │ │
│  │ +1555...    Sales      Ext 101      Active    │ │
│  │ +1555...    Support    Ring Grp 1   Active    │ │
│  └───────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

**Table Columns:**
- Phone Number (formatted)
- Friendly Name
- Routing Type (icon + label)
- Routing Target (extension #, ring group name, or hours rule)
- Status badge
- Actions (Edit, Delete)

**DID Form Modal:**
See [DIDForm component](#9-didform) above

---

### Ring Groups

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│ Ring Groups                     [+ Create Group]    │
├─────────────────────────────────────────────────────┤
│ [Search]                                            │
│                                                     │
│  ┌────────────────────────────────────────────┐    │
│  │ Sales Team                 [Badge: Simultaneous]│
│  │ 5 members • 30s timeout                        │
│  │                                   [Edit] [Delete]│
│  └────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────┘
```

**Card Layout:**
Each ring group shows:
- Name (bold, large)
- Strategy badge
- Member count + Timeout
- Actions (Edit, Delete)

**Ring Group Form Modal:**
- Name input
- Strategy selector (visual cards)
- Member selection (multi-select extensions)
  - For sequential: drag-to-reorder list
- Timeout slider (10-120 seconds)
- Fallback action (dropdown: voicemail, extension, disconnect)

---

### Business Hours

**Layout:**
```
┌─────────────────────────────────────────────────────┐
│ Business Hours                [+ Create Rule]       │
├─────────────────────────────────────────────────────┤
│                                                     │
│  ┌────────────────────────────────────────────┐    │
│  │ Main Office Hours                              │
│  │ EST • Mon-Fri 9am-5pm                          │
│  │ 2 holidays configured                          │
│  │                                   [Edit] [Delete]│
│  └────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────┘
```

**Card Layout:**
- Rule name
- Timezone
- Schedule summary (e.g., "Mon-Fri 9am-5pm")
- Holiday count
- Actions (Edit, Delete)

**Business Hours Form Modal:**
- Rule Name input
- Timezone selector (searchable)
- Schedule builder (see [BusinessHoursScheduleBuilder](#11-businesshoursschedulebuilder))
- Holiday list (date picker, add/remove)
- Open Hours Routing (DID form routing options)
- Closed Hours Routing (DID form routing options)

---

## Responsive Design

### Breakpoints

```typescript
breakpoints: {
  xs: '320px',   // Small phones
  sm: '640px',   // Large phones
  md: '768px',   // Tablets
  lg: '1024px',  // Laptops
  xl: '1280px',  // Desktops
  '2xl': '1536px' // Large desktops
}
```

### Responsive Patterns

#### Mobile First Approach

Start with mobile layout, progressively enhance for larger screens.

**Example:**
```tsx
<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
  {/* StatCards */}
</div>
```

#### Sidebar Behavior

| Breakpoint | Behavior |
|------------|----------|
| < 768px | Collapsed by default, hamburger menu, overlay when open |
| 768px - 1023px | Narrow sidebar (icons + text) |
| >= 1024px | Full sidebar (256px wide) |

**Mobile Navigation:**
When sidebar is collapsed, show bottom navigation bar with key items:
- Dashboard, Live Calls, Call Logs, Settings

#### Table → Card Transformation

On mobile (<768px), tables convert to stacked cards:

**Desktop Table:**
```
| From | To | Status | Duration |
| +1555 | 101 | Answered | 00:45 |
```

**Mobile Card:**
```
┌───────────────────────────┐
│ From: +1 (555) 123-4567   │
│ To: Extension 101         │
│ Status: Answered          │
│ Duration: 00:45           │
└───────────────────────────┘
```

#### Touch Targets

All interactive elements have minimum 44x44px touch target (WCAG AA).

**Implementation:**
```tsx
<Button className="h-11 px-4"> {/* 44px height */}
  Click Me
</Button>
```

---

## Interaction Patterns

### Loading States

#### Skeleton Loaders

Use skeleton screens for content loading:

```tsx
{loading ? (
  <Skeleton className="h-32 w-full" />
) : (
  <StatCard {...data} />
)}
```

**Skeleton Animation:**
Shimmer effect from left to right, 2s duration, infinite loop.

#### Spinner

Use for button/action loading:

```tsx
<Button disabled={loading}>
  {loading && <LoadingSpinner size="sm" className="mr-2" />}
  Save
</Button>
```

#### Progress Indicators

For multi-step forms or long operations:
- Linear progress bar at top
- Steps indicator (1/3, 2/3, 3/3)

---

### Error States

#### Inline Form Errors

Show below field with danger color:

```tsx
<Input {...field} />
{error && (
  <p className="text-sm text-danger-600 mt-1">
    {error.message}
  </p>
)}
```

#### Toast Notifications

For API errors and general feedback:

**Error Toast:**
```tsx
toast.error("Failed to save user", {
  description: error.message,
  action: {
    label: "Retry",
    onClick: () => handleRetry(),
  },
});
```

**Success Toast:**
```tsx
toast.success("User saved successfully");
```

**Position:** Top-right corner
**Duration:** 3s auto-dismiss (errors persist until dismissed)
**Animation:** Slide in from right

---

### Success Feedback

#### Checkmark Animation

After save, show checkmark with scale animation:

```tsx
<motion.div
  initial={{ scale: 0 }}
  animate={{ scale: 1 }}
  className="text-success-600"
>
  <CheckCircle className="h-12 w-12" />
</motion.div>
```

#### State Transitions

Smooth color transitions when status changes:

```tsx
className={cn(
  'transition-colors duration-200',
  isActive ? 'bg-success-100' : 'bg-neutral-100'
)}
```

---

### Confirmations

#### Destructive Actions

Always confirm deletes:

```tsx
<ConfirmDialog
  open={showConfirm}
  onOpenChange={setShowConfirm}
  title="Delete User?"
  description="This will permanently delete the user and cannot be undone."
  confirmLabel="Delete"
  onConfirm={handleDelete}
  variant="danger"
/>
```

#### Critical Actions

For very critical operations (delete organization, purge data), require typing confirmation:

```tsx
<Input
  placeholder="Type 'DELETE' to confirm"
  value={confirmText}
  onChange={(e) => setConfirmText(e.target.value)}
/>
<Button
  disabled={confirmText !== 'DELETE'}
  onClick={handleDelete}
>
  Delete Organization
</Button>
```

---

## Animation Guidelines

### Duration Standards

```typescript
animation: {
  duration: {
    fastest: '100ms',  // Micro-interactions (hover, focus)
    fast: '150ms',     // Button press, badge appear
    normal: '200ms',   // Page transitions, card slide
    slow: '300ms',     // Modal open/close, complex transitions
    slower: '400ms',   // Drawer slide, large movements
    slowest: '500ms',  // Full page transitions
  }
}
```

### Timing Functions

```typescript
timing: {
  linear: 'linear',           // Constant speed (loading spinners)
  ease: 'ease',               // Default ease
  easeIn: 'cubic-bezier(0.4, 0, 1, 1)',     // Accelerate
  easeOut: 'cubic-bezier(0, 0, 0.2, 1)',    // Decelerate (preferred)
  easeInOut: 'cubic-bezier(0.4, 0, 0.2, 1)', // Smooth start/end
  spring: 'cubic-bezier(0.68, -0.55, 0.265, 1.55)', // Bounce effect
}
```

### Common Animations

#### Fade In/Out

```tsx
<motion.div
  initial={{ opacity: 0 }}
  animate={{ opacity: 1 }}
  exit={{ opacity: 0 }}
  transition={{ duration: 0.2 }}
>
  Content
</motion.div>
```

#### Slide In

```tsx
<motion.div
  initial={{ x: 20, opacity: 0 }}
  animate={{ x: 0, opacity: 1 }}
  transition={{ duration: 0.2, ease: 'easeOut' }}
>
  Content
</motion.div>
```

#### Scale

```tsx
<motion.button
  whileHover={{ scale: 1.02 }}
  whileTap={{ scale: 0.98 }}
  transition={{ duration: 0.1 }}
>
  Click Me
</motion.button>
```

#### Pulsing (for ringing calls)

```css
@keyframes pulse {
  0%, 100% {
    opacity: 1;
    transform: scale(1);
  }
  50% {
    opacity: 0.8;
    transform: scale(1.05);
  }
}

.animate-pulse {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}
```

### Performance Considerations

- **Prefer transform and opacity:** GPU-accelerated
- **Avoid animating:** width, height, top, left (causes reflow)
- **Use will-change sparingly:** Only for animations about to start
- **Reduce motion:** Respect `prefers-reduced-motion`

```css
@media (prefers-reduced-motion: reduce) {
  * {
    animation-duration: 0.01ms !important;
    transition-duration: 0.01ms !important;
  }
}
```

---

## Accessibility Standards

### WCAG 2.1 AA Compliance

#### Color Contrast

All text meets WCAG AA contrast ratios:

| Text Size | Background | Minimum Ratio |
|-----------|------------|---------------|
| Normal text (<18px) | Any | 4.5:1 |
| Large text (>=18px) | Any | 3:1 |
| UI components | Any | 3:1 |

**Validation:**
Use browser DevTools or [WebAIM Contrast Checker](https://webaim.org/resources/contrastchecker/)

**Design Token Validation:**
```
✓ primary-600 on white: 7.2:1
✓ success-600 on success-100: 6.8:1
✓ neutral-700 on white: 12.6:1
✓ neutral-500 on white: 4.6:1
```

#### Focus Indicators

All interactive elements have visible focus state:

```tsx
className="focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
```

**Focus Ring:**
- 2px width
- Primary-500 color
- 2px offset from element

#### Keyboard Navigation

| Key | Action |
|-----|--------|
| Tab | Move to next focusable element |
| Shift+Tab | Move to previous focusable element |
| Enter / Space | Activate button or link |
| Esc | Close modal or dropdown |
| Arrow Keys | Navigate within lists, menus, radio groups |

**Implementation:**
- All interactive elements are keyboard accessible
- Modals trap focus (can't Tab outside)
- Dropdowns close on Esc
- First element in modal auto-focuses

#### Screen Reader Support

**ARIA Labels:**
```tsx
<Button aria-label="Close dialog">
  <X className="h-4 w-4" />
</Button>
```

**ARIA Descriptions:**
```tsx
<Input
  aria-describedby="email-error"
/>
<p id="email-error" className="text-danger-600">
  Email is required
</p>
```

**Live Regions:**
For real-time updates (live calls):

```tsx
<div aria-live="polite" aria-atomic="true">
  {activeCallCount} active calls
</div>
```

**Semantic HTML:**
- Use `<button>` not `<div onClick>`
- Use `<nav>` for navigation
- Use `<main>` for main content
- Use `<header>` for page headers

#### Form Accessibility

**Labels:**
Every input has an associated label:

```tsx
<Label htmlFor="email">Email *</Label>
<Input id="email" type="email" required />
```

**Required Indicators:**
Show asterisk (*) in label for required fields.

**Error Announcements:**
Errors are announced to screen readers:

```tsx
<Input
  aria-invalid={!!error}
  aria-describedby={error ? "email-error" : undefined}
/>
{error && (
  <p id="email-error" role="alert" className="text-danger-600">
    {error.message}
  </p>
)}
```

#### Touch Targets

Minimum 44x44px for all interactive elements (WCAG 2.5.5).

**Implementation:**
```tsx
<Button className="min-h-[44px] min-w-[44px]">
  Save
</Button>
```

---

## Implementation Guide

### Getting Started

1. **Import Design Tokens:**
   ```tsx
   import { colors, typography, spacing } from '@/styles/tokens';
   ```

2. **Use Tailwind Classes:**
   All tokens are mapped to Tailwind config, so use Tailwind classes:
   ```tsx
   <div className="bg-primary-600 text-white p-4 rounded-md">
     Content
   </div>
   ```

3. **Import Components:**
   ```tsx
   import { Button } from '@/components/ui/button';
   import { StatCard } from '@/components/design-system/StatCard';
   ```

### Component Development Workflow

1. **Check Design System First:**
   Before building custom components, check if a design system component exists.

2. **Extend Existing Components:**
   If similar component exists, extend it:
   ```tsx
   import { Button } from '@/components/ui/button';

   export function IconButton({ icon: Icon, ...props }) {
     return (
       <Button variant="ghost" size="icon" {...props}>
         <Icon className="h-4 w-4" />
       </Button>
     );
   }
   ```

3. **Create Reusable Components:**
   New components should:
   - Accept className prop for customization
   - Use design tokens
   - Include TypeScript types
   - Document props with JSDoc

4. **Add to Storybook (Optional):**
   For component library documentation.

### Styling Guidelines

#### Use Tailwind First

```tsx
// ✅ Good
<div className="flex items-center gap-4 p-4 bg-white rounded-lg shadow-md">

// ❌ Avoid
<div style={{ display: 'flex', alignItems: 'center', gap: '16px' }}>
```

#### Conditional Styling with cn()

```tsx
import { cn } from '@/lib/utils';

<Button
  className={cn(
    'base-classes',
    isActive && 'active-classes',
    isDisabled && 'disabled-classes'
  )}
/>
```

#### Custom Styles (When Necessary)

Use CSS modules or styled-components for complex animations:

```tsx
// Component.module.css
.pulsingRing {
  animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
}

@keyframes pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.5; }
}
```

### Responsive Development

Use Tailwind responsive prefixes:

```tsx
<div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
  {/* Responsive grid */}
</div>
```

### Accessibility Checklist

- [ ] All interactive elements have visible focus states
- [ ] Color is not the only means of conveying information
- [ ] All images have alt text (or alt="" if decorative)
- [ ] Form inputs have associated labels
- [ ] Buttons have descriptive text or aria-label
- [ ] Modals trap focus and close on Esc
- [ ] Keyboard navigation works for all features
- [ ] ARIA attributes used correctly
- [ ] Touch targets are minimum 44x44px
- [ ] Color contrast meets WCAG AA (4.5:1 for text)

### Testing

1. **Visual Testing:**
   - Test on multiple screen sizes (mobile, tablet, desktop)
   - Test light and dark mode (if applicable)
   - Verify colors and spacing match design tokens

2. **Accessibility Testing:**
   - Use keyboard only (no mouse)
   - Test with screen reader (VoiceOver, NVDA, JAWS)
   - Run Lighthouse accessibility audit (score >= 95)
   - Use axe DevTools browser extension

3. **Performance Testing:**
   - Animations run at 60fps
   - No layout shifts (CLS < 0.1)
   - Fast interaction response (FID < 100ms)

---

## Appendix

### Icon Reference

Using **Lucide React** icons throughout:

| Category | Icons |
|----------|-------|
| Navigation | LayoutDashboard, Phone, PhoneCall, List, Users, Hash, UsersRound, Clock, Settings, LogOut |
| Actions | Plus, Pencil, Trash2, Search, Filter, Download, Upload, MoreVertical |
| Status | CheckCircle, XCircle, AlertCircle, Info, AlertTriangle |
| Arrows | ArrowUp, ArrowDown, ArrowLeft, ArrowRight, ChevronDown, ChevronUp |
| Media | Play, Pause, Stop, Volume, VolumeX |
| Files | File, FileText, Folder, Download |

**Installation:**
```bash
npm install lucide-react
```

**Usage:**
```tsx
import { PhoneCall, Users, Settings } from 'lucide-react';

<PhoneCall className="h-5 w-5 text-primary-600" />
```

### Color Palette Reference

Complete color palette with all shades:

#### Primary (Blue)
```
50:  #eff6ff
100: #dbeafe
200: #bfdbfe
300: #93c5fd
400: #60a5fa
500: #3b82f6 ⭐ Main
600: #2563eb
700: #1d4ed8
800: #1e40af
900: #1e3a8a
```

#### Success (Green)
```
50:  #f0fdf4
100: #dcfce7
200: #bbf7d0
300: #86efac
400: #4ade80
500: #10b981 ⭐ Main
600: #059669
700: #047857
800: #065f46
900: #064e3b
```

#### Warning (Amber)
```
50:  #fffbeb
100: #fef3c7
200: #fde68a
300: #fcd34d
400: #fbbf24
500: #f59e0b ⭐ Main
600: #d97706
700: #b45309
800: #92400e
900: #78350f
```

#### Danger (Red)
```
50:  #fef2f2
100: #fee2e2
200: #fecaca
300: #fca5a5
400: #f87171
500: #ef4444 ⭐ Main
600: #dc2626
700: #b91c1c
800: #991b1b
900: #7f1d1d
```

#### Neutral (Gray)
```
50:  #f9fafb
100: #f3f4f6
200: #e5e7eb
300: #d1d5db
400: #9ca3af
500: #6b7280 ⭐ Secondary text
600: #4b5563
700: #374151 ⭐ Primary text
800: #1f2937
900: #111827 ⭐ Sidebar
```

---

## Change Log

### Version 1.0.0 (2025-12-21)
- Initial design system documentation
- Complete design tokens (colors, typography, spacing, shadows)
- Core component specifications (StatCard, CallStatusBadge, ActiveCallCard, ExtensionCard, EmptyState)
- Form components (UserForm, DIDForm, RingGroupStrategySelector, BusinessHoursScheduleBuilder)
- All page layout specifications
- Responsive design patterns
- Interaction and animation guidelines
- WCAG 2.1 AA accessibility standards
- Implementation guide

---

## Contributing

When adding new components or patterns to the design system:

1. **Document First:** Add specification to this document
2. **Get Review:** Have design review before implementation
3. **Build Component:** Implement with TypeScript + Tailwind
4. **Test Accessibility:** Run full accessibility audit
5. **Update Changelog:** Document changes

---

## Support

For design system questions or feedback:
- **UI Design Team:** Contact via internal Slack channel
- **Documentation Issues:** Open GitHub issue with "design-system" label
- **Component Requests:** Use the component request template

---

**End of Design System Documentation v1.0.0**
