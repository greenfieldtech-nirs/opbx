# Frontend Remediation Plan

## Overview

This document outlines a comprehensive implementation plan for addressing the frontend (UI/UX) code review findings. The plan is organized into three phases based on priority:

- **Phase 1: Critical Fixes** - Replace alert() with toast(), add loading states, fix login form
- **Phase 2: Modularization** - Create reusable hooks, split components, standardize patterns
- **Phase 3: UI/UX Polish** - Empty state standardization, design system, loading skeletons

**Target Environment:** OpenPBX running on Podman (use `docker compose` instead of `docker-compose`)

---

## Phase 1: Critical Fixes

### 1.1 Goal

Fix critical issues that impact functionality and user experience:
- Replace all `alert()` calls with `toast()` notifications
- Add loading states to all action buttons
- Make LoginPage fully functional

### 1.2 Pre-requisites

- Understanding of existing `useToast` hook implementation
- Understanding of React Query mutation patterns
- Access to all React component files

### 1.3 Tasks

#### Step 1.1: Replace alert() with toast() in BusinessHoursPage

**Sub-agents required:**
- `frontend-developer` - To implement the changes

**Actions:**
1. Import `toast` from `@/hooks/use-toast`
2. Replace `alert()` calls with `toast()` calls
3. Remove unused `alert` function calls

**Code Changes:**

**Before:**
```typescript
// Line 29
alert('Business hours schedule deleted successfully.');

// Line 34
alert(error.response?.data?.message || 'Failed to delete business hours schedule.');

// Line 44
alert('Business hours schedule duplicated successfully.');

// Line 47
alert(error.response?.data?.message || 'Failed to duplicate business hours schedule.');
```

**After:**
```typescript
import { toast } from '@/hooks/use-toast';

// Line 29
toast({
  title: 'Success',
  description: 'Business hours schedule deleted successfully.',
});

// Line 34
toast({
  title: 'Error',
  description: error.response?.data?.message || 'Failed to delete business hours schedule.',
  variant: 'destructive',
});

// Line 44
toast({
  title: 'Success',
  description: 'Business hours schedule duplicated successfully.',
});

// Line 47
toast({
  title: 'Error',
  description: error.response?.data?.message || 'Failed to duplicate business hours schedule.',
  variant: 'destructive',
});
```

**Files affected:**
- `resources/js/pages/BusinessHours/BusinessHoursPage.tsx`

**Testing:**
- Verify toast notifications appear on success
- Verify toast notifications appear on error
- Verify toast variants (success/destructive) work correctly

**Docker Commands:**
```bash
docker compose exec app npm run build 2>&1 | head -50
docker compose exec frontend npm run build 2>&1 | head -50
```

#### Step 1.2: Replace alert() with toast() in BusinessHoursForm

**Sub-agents required:**
- `frontend-developer` - To implement the changes

**Actions:**
1. Import `toast` from `@/hooks/use-toast`
2. Replace `alert()` calls with `toast()` calls

**Code Changes:**

**Before (lines 141, 146, 156, 161):**
```typescript
alert('Business hours schedule created successfully!');
alert(error.response?.data?.message || 'Failed to create business hours schedule.');
alert('Business hours schedule updated successfully!');
alert(error.response?.data?.message || 'Failed to update business hours schedule.');
```

**After:**
```typescript
toast({
  title: 'Success',
  description: 'Business hours schedule created successfully!',
});

toast({
  title: 'Error',
  description: error.response?.data?.message || 'Failed to create business hours schedule.',
  variant: 'destructive',
});

toast({
  title: 'Success',
  description: 'Business hours schedule updated successfully!',
});

toast({
  title: 'Error',
  description: error.response?.data?.message || 'Failed to update business hours schedule.',
  variant: 'destructive',
});
```

**Files affected:**
- `resources/js/pages/BusinessHours/BusinessHoursCreatePage.tsx`
- `resources/js/pages/BusinessHours/BusinessHoursEditPage.tsx`

**Testing:**
- Verify toast notifications appear on form submission success
- Verify toast notifications appear on form submission error

**Docker Commands:**
```bash
docker compose exec frontend npm run lint 2>&1 | grep -E "(error|warning)" | head -20
```

#### Step 1.3: Add Loading States to Action Buttons

**Sub-agents required:**
- `frontend-developer` - To implement the changes
- `ui-designer` - To review loading state visual design

**Actions:**
1. Add `isLoading` state to delete button in `BusinessHoursPage`
2. Add `isLoading` state to duplicate button in `BusinessHoursPage`
3. Add `isLoading` state to submit button in `BusinessHoursForm`

**Code Changes:**

**BusinessHoursPage.tsx - Delete Button:**

```typescript
// Before (line 184-189)
<button
  onClick={() => handleDelete(schedule)}
  className="text-red-600 hover:underline text-sm"
>
  Delete
</button>

// After
<button
  onClick={() => handleDelete(schedule)}
  disabled={deleteMutation.isPending}
  className="text-red-600 hover:underline text-sm disabled:opacity-50"
>
  {deleteMutation.isPending ? 'Deleting...' : 'Delete'}
</button>
```

**BusinessHoursPage.tsx - Duplicate Button:**

```typescript
// Before (line 178-182)
<button
  onClick={() => handleDuplicate(schedule)}
  className="text-blue-600 hover:underline text-sm"
>
  Duplicate
</button>

// After
<button
  onClick={() => handleDuplicate(schedule)}
  disabled={duplicateMutation.isPending}
  className="text-blue-600 hover:underline text-sm disabled:opacity-50"
>
  {duplicateMutation.isPending ? 'Duplicating...' : 'Duplicate'}
</button>
```

**BusinessHoursForm.tsx - Submit Button:**

The form already has `isSubmitting` state, verify it's used correctly:

```typescript
// Line 293
{isSubmitting ? 'Saving...' : (isEditing ? 'Update Schedule' : 'Create Schedule')}
```

**Files affected:**
- `resources/js/pages/BusinessHours/BusinessHoursPage.tsx`
- `resources/js/pages/BusinessHours/BusinessHoursForm.tsx`

**Testing:**
- Verify buttons show loading state during API calls
- Verify buttons are disabled during loading
- Verify loading text is displayed

**Docker Commands:**
```bash
docker compose exec frontend npm run type-check 2>&1 | head -30
```

#### Step 1.4: Make LoginPage Fully Functional

**Sub-agents required:**
- `frontend-developer` - To implement the changes
- `api-designer` - To verify API contract

**Actions:**
1. Add form state using React
2. Add form validation
3. Add API integration
4. Add error handling with toast
5. Add navigation after successful login

**Code Changes:**

```typescript
import React, { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useToast } from '@/hooks/use-toast';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AuroraBackgroundProvider } from '@nauverse/react-aurora-background';

export const LoginPage: React.FC = () => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [isLoading, setIsLoading] = useState(false);
  const { toast } = useToast();
  const navigate = useNavigate();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!email || !password) {
      toast({
        title: 'Error',
        description: 'Please enter both email and password.',
        variant: 'destructive',
      });
      return;
    }

    setIsLoading(true);

    try {
      const response = await fetch('/api/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
        body: JSON.stringify({ email, password }),
      });

      if (response.ok) {
        toast({
          title: 'Success',
          description: 'Login successful. Redirecting...',
        });
        navigate('/dashboard');
      } else {
        const data = await response.json();
        toast({
          title: 'Error',
          description: data.message || 'Invalid credentials.',
          variant: 'destructive',
        });
      }
    } catch (error) {
      toast({
        title: 'Error',
        description: 'An unexpected error occurred. Please try again.',
        variant: 'destructive',
      });
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <div className="min-h-screen flex">
      {/* Left side - Aurora Background */}
      <div className="flex-1 relative overflow-hidden flex items-center justify-center">
        <AuroraBackgroundProvider
          colors={['#3A29FF', '#FF94B4', '#FF3232']}
          numBubbles={4}
          animDuration={5}
          blurAmount="10vw"
          bgColor="#3f5efb"
          useRandomness={false}
          className="w-full h-full flex items-center justify-center"
        >
          <div className="text-white text-center">
            <h1 className="text-6xl font-bold mb-4">OpBX</h1>
            <p className="text-xl opacity-90">Cloud PBX Administration</p>
          </div>
        </AuroraBackgroundProvider>
      </div>

      {/* Right side - Login Form */}
      <div className="flex-1 flex items-center justify-center p-8 bg-gray-50">
        <div className="w-full max-w-md bg-white rounded-lg shadow-lg p-8">
          <div className="space-y-1 mb-6">
            <h2 className="text-2xl font-bold text-center">Sign In</h2>
            <p className="text-gray-600 text-center">
              Enter your credentials to access the PBX administration
            </p>
          </div>
          <form onSubmit={handleSubmit} className="space-y-4">
            <div className="space-y-2">
              <label htmlFor="email" className="text-sm font-medium">
                Email
              </label>
              <Input
                id="email"
                type="email"
                placeholder="admin@example.com"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                disabled={isLoading}
                className="w-full"
              />
            </div>
            <div className="space-y-2">
              <label htmlFor="password" className="text-sm font-medium">
                Password
              </label>
              <Input
                id="password"
                type="password"
                placeholder="Enter your password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                disabled={isLoading}
                className="w-full"
              />
            </div>
            <Button 
              type="submit" 
              className="w-full" 
              size="lg"
              disabled={isLoading}
            >
              {isLoading ? 'Signing in...' : 'Sign In'}
            </Button>
            <div className="text-center text-sm text-gray-600">
              <a href="#" className="hover:underline">
                Forgot your password?
              </a>
            </div>
          </form>
        </div>
      </div>
    </div>
  );
};
```

**Files affected:**
- `resources/js/pages/LoginPage.tsx`

**Testing:**
- Verify form submits successfully
- Verify error handling works
- Verify loading state during API call
- Verify navigation after successful login

**Docker Commands:**
```bash
docker compose exec frontend npm run lint 2>&1
docker compose exec frontend npm run type-check 2>&1
```

---

## Phase 2: Code Modularization

### 2.1 Goal

Improve code organization and reusability:
- Create reusable hooks for API operations
- Split large components into smaller pieces
- Standardize API patterns across pages

### 2.2 Pre-requisites

- Complete Phase 1 (all steps)
- Understanding of existing React Query patterns

### 2.3 Tasks

#### Step 2.1: Create useBusinessHours Hook

**Sub-agents required:**
- `frontend-developer` - To create the hook

**Actions:**
1. Create `hooks/useBusinessHours.ts`
2. Move data fetching logic from `BusinessHoursPage.tsx`
3. Move mutation logic from `BusinessHoursPage.tsx` and `BusinessHoursForm.tsx`
4. Update components to use the new hook

**New File: `resources/js/hooks/useBusinessHours.ts`**

```typescript
import { useQuery, useMutation, useQueryClient } from 'react-query';
import { businessHoursApi } from '@/lib/api';
import { BusinessHoursSchedule, BusinessHoursFormData } from '@/types/business-hours';
import { toast } from '@/hooks/use-toast';

const BUSINESS_HOURS_QUERY_KEY = 'business-hours';

export function useBusinessHours(search: string = '') {
  return useQuery({
    queryKey: [BUSINESS_HOURS_QUERY_KEY, search],
    queryFn: () => businessHoursApi.getAll({
      search: search || undefined,
      per_page: 50,
    }),
    keepPreviousData: true,
  });
}

export function useCreateBusinessHours() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (data: BusinessHoursFormData) => businessHoursApi.create(data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [BUSINESS_HOURS_QUERY_KEY] });
      toast({
        title: 'Success',
        description: 'Business hours schedule created successfully!',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to create business hours schedule.',
        variant: 'destructive',
      });
    },
  });
}

export function useUpdateBusinessHours() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: ({ id, data }: { id: string; data: BusinessHoursFormData }) => 
      businessHoursApi.update(id, data),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [BUSINESS_HOURS_QUERY_KEY] });
      toast({
        title: 'Success',
        description: 'Business hours schedule updated successfully!',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to update business hours schedule.',
        variant: 'destructive',
      });
    },
  });
}

export function useDeleteBusinessHours() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id: string) => businessHoursApi.delete(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [BUSINESS_HOURS_QUERY_KEY] });
      toast({
        title: 'Success',
        description: 'Business hours schedule deleted successfully.',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to delete business hours schedule.',
        variant: 'destructive',
      });
    },
  });
}

export function useDuplicateBusinessHours() {
  const queryClient = useQueryClient();
  
  return useMutation({
    mutationFn: (id: string) => businessHoursApi.duplicate(id),
    onSuccess: () => {
      queryClient.invalidateQueries({ queryKey: [BUSINESS_HOURS_QUERY_KEY] });
      toast({
        title: 'Success',
        description: 'Business hours schedule duplicated successfully.',
      });
    },
    onError: (error: any) => {
      toast({
        title: 'Error',
        description: error.response?.data?.message || 'Failed to duplicate business hours schedule.',
        variant: 'destructive',
      });
    },
  });
}
```

**Files affected:**
- `resources/js/hooks/useBusinessHours.ts` (new)
- `resources/js/pages/BusinessHours/BusinessHoursPage.tsx`
- `resources/js/pages/BusinessHours/BusinessHoursForm.tsx`
- `resources/js/pages/BusinessHours/BusinessHoursCreatePage.tsx`
- `resources/js/pages/BusinessHours/BusinessHoursEditPage.tsx`

**Testing:**
- Verify all queries work correctly
- Verify all mutations work correctly
- Verify toast notifications work
- Verify query invalidation works

**Docker Commands:**
```bash
docker compose exec frontend npm run type-check 2>&1
docker compose exec frontend npm run lint 2>&1
```

#### Step 2.2: Create EmptyState Component

**Sub-agents required:**
- `ui-designer` - To design the component
- `frontend-developer` - To implement the component

**Actions:**
1. Create `components/ui/EmptyState.tsx`
2. Implement the pattern specified in CLAUDE.md (ConferenceRooms.tsx reference)
3. Update `BusinessHoursPage.tsx` to use the new component
4. Create reusable icon mapping

**New File: `resources/js/components/ui/EmptyState.tsx`**

```typescript
import React from 'react';
import { Plus } from 'lucide-react';
import { Button } from './button';

interface EmptyStateProps {
  icon: React.ReactNode;
  title: string;
  description: string;
  hasFilters: boolean;
  canCreate: boolean;
  onCreate?: () => void;
  createLabel?: string;
  className?: string;
}

export function EmptyState({
  icon,
  title,
  description,
  hasFilters,
  canCreate,
  onCreate,
  createLabel = 'Create',
  className = '',
}: EmptyStateProps) {
  return (
    <div className={`text-center py-12 ${className}`}>
      <div className="mx-auto h-12 w-12 text-muted-foreground mb-4">
        {icon}
      </div>
      <h3 className="text-lg font-semibold mb-2">{title}</h3>
      <p className="text-muted-foreground mb-4">
        {hasFilters ? 'Try adjusting your filters' : description}
      </p>
      {canCreate && !hasFilters && onCreate && (
        <Button onClick={onCreate}>
          <Plus className="h-4 w-4 mr-2" />
          {createLabel}
        </Button>
      )}
    </div>
  );
}
```

**Icon Mapping Utility: `resources/js/components/ui/Icons.tsx`**

```typescript
import React from 'react';
import { Clock, Users, Phone, Settings, Folder, FileText } from 'lucide-react';

export const iconMap: Record<string, React.ReactNode> = {
  business_hours: <Clock className="h-12 w-12" />,
  users: <Users className="h-12 w-12" />,
  extensions: <Phone className="h-12 w-12" />,
  settings: <Settings className="h-12 w-12" />,
  ring_groups: <Folder className="h-12 w-12" />,
  call_logs: <FileText className="h-12 w-12" />,
  default: <Folder className="h-12 w-12" />,
};

export function getIcon(key: string) {
  return iconMap[key] || iconMap.default;
}
```

**Updated BusinessHoursPage.tsx:**

```typescript
import { EmptyState, getIcon } from '@/components/ui/EmptyState';

// Replace the empty state div (lines 106-124) with:
<EmptyState
  icon={getIcon('business_hours')}
  title="No business hours schedules found"
  description="Get started by creating your first business hours schedule"
  hasFilters={!!search}
  canCreate={true}
  onCreate={() => navigate('/business-hours/create')}
  createLabel="Create Schedule"
/>
```

**Files affected:**
- `resources/js/components/ui/EmptyState.tsx` (new)
- `resources/js/components/ui/Icons.tsx` (new)
- `resources/js/pages/BusinessHours/BusinessHoursPage.tsx`

**Testing:**
- Verify empty state displays correctly
- Verify filters message shows when filters active
- Verify create button shows when no filters
- Verify icons render correctly

**Docker Commands:**
```bash
docker compose exec frontend npm run build 2>&1 | grep -E "(error|Error)" | head -10
```

#### Step 2.3: Split BusinessHoursForm into Smaller Components

**Sub-agents required:**
- `frontend-developer` - To implement the changes

**Actions:**
1. Create `components/BusinessHours/WeeklySchedule.tsx`
2. Create `components/BusinessHours/Exceptions.tsx`
3. Update `BusinessHoursForm.tsx` to use new components

**New File: `resources/js/components/BusinessHours/WeeklySchedule.tsx`**

```typescript
import React from 'react';
import { useFieldArray, UseFormReturn } from 'react-hook-form';
import { BusinessHoursFormData } from '@/types/business-hours';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

interface WeeklyScheduleProps {
  control: UseFormReturn<BusinessHoursFormData>['control'];
  register: UseFormReturn<BusinessHoursFormData>['register'];
  errors: UseFormReturn<BusinessHoursFormData>['formState']['errors'];
}

const DAYS = [
  'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'
] as const;

const DAY_LABELS: Record<string, string> = {
  monday: 'Monday',
  tuesday: 'Tuesday',
  wednesday: 'Wednesday',
  thursday: 'Thursday',
  friday: 'Friday',
  saturday: 'Saturday',
  sunday: 'Sunday',
};

export function WeeklySchedule({ control, register, errors }: WeeklyScheduleProps) {
  return (
    <div className="bg-white shadow rounded-lg p-6">
      <h3 className="text-lg font-medium text-gray-900 mb-4">Weekly Schedule</h3>
      <p className="text-gray-600 mb-6">Configure your business hours for each day of the week.</p>
      
      <div className="space-y-4">
        {DAYS.map((day) => (
          <div key={day} className="flex items-center space-x-4">
            <div className="w-32">
              <label className="flex items-center space-x-2">
                <input
                  type="checkbox"
                  {...register(`schedule.${day}.enabled`)}
                  className="rounded border-gray-300 text-blue-600 focus:ring-blue-500"
                />
                <span className="text-sm font-medium text-gray-700">
                  {DAY_LABELS[day]}
                </span>
              </label>
            </div>
            
            <DayTimeRanges
              day={day}
              control={control}
              register={register}
              errors={errors}
            />
          </div>
        ))}
      </div>
    </div>
  );
}

interface DayTimeRangesProps {
  day: string;
  control: any;
  register: any;
  errors: any;
}

function DayTimeRanges({ day, control, register, errors }: DayTimeRangesProps) {
  const { fields, append, remove } = useFieldArray({
    control,
    name: `schedule.${day}.time_ranges`,
  });

  return (
    <div className="flex-1 space-y-2">
      {fields.map((field, index) => (
        <div key={field.id} className="flex items-center space-x-2">
          <Input
            type="time"
            {...register(`schedule.${day}.time_ranges.${index}.start_time`)}
            className="w-32"
          />
          <span className="text-gray-500">to</span>
          <Input
            type="time"
            {...register(`schedule.${day}.time_ranges.${index}.end_time`)}
            className="w-32"
          />
          <Button
            type="button"
            variant="outline"
            size="sm"
            onClick={() => remove(index)}
            className="text-red-600"
          >
            Remove
          </Button>
        </div>
      ))}
      
      <Button
        type="button"
        variant="outline"
        size="sm"
        onClick={() => append({ start_time: '09:00', end_time: '17:00' })}
      >
        Add Time Range
      </Button>
      
      {errors.schedule?.[day]?.time_ranges && (
        <p className="text-red-600 text-sm">
          {errors.schedule[day].time_ranges.message}
        </p>
      )}
    </div>
  );
}
```

**Files affected:**
- `resources/js/components/BusinessHours/WeeklySchedule.tsx` (new)
- `resources/js/components/BusinessHours/Exceptions.tsx` (new - similar pattern)
- `resources/js/pages/BusinessHours/BusinessHoursForm.tsx` (simplified)

**Testing:**
- Verify weekly schedule renders correctly
- Verify time ranges can be added/removed
- Verify validation works
- Verify form submits correctly

**Docker Commands:**
```bash
docker compose exec frontend npm run type-check 2>&1
docker compose exec frontend npm run lint 2>&1
```

---

## Phase 3: UI/UX Polish

### 3.1 Goal

Improve visual consistency and user experience:
- Standardize design tokens
- Add loading skeletons
- Create design system documentation

### 3.2 Pre-requisites

- Complete Phase 1 and Phase 2
- Understanding of design requirements

### 3.3 Tasks

#### Step 3.1: Create Design System Constants

**Sub-agents required:**
- `ui-designer` - To define design tokens
- `frontend-developer` - To implement constants

**Actions:**
1. Create `styles/tokens.css` or `lib/design-tokens.ts`
2. Define color, spacing, typography constants
3. Update components to use constants

**New File: `resources/js/lib/design-tokens.ts`**

```typescript
// Colors
export const colors = {
  primary: {
    50: '#eff6ff',
    100: '#dbeafe',
    500: '#3b82f6',
    600: '#2563eb',
    700: '#1d4ed8',
  },
  success: {
    100: '#dcfce7',
    800: '#166534',
  },
  danger: {
    100: '#fee2e2',
    600: '#dc2626',
    700: '#b91c1c',
  },
  gray: {
    50: '#f9fafb',
    100: '#f3f4f6',
    300: '#d1d5db',
    600: '#4b5563',
    800: '#1f2937',
  },
} as const;

// Spacing
export const spacing = {
  xs: '0.25rem',
  sm: '0.5rem',
  md: '1rem',
  lg: '1.5rem',
  xl: '2rem',
  '2xl': '3rem',
} as const;

// Border Radius
export const borderRadius = {
  sm: '0.25rem',
  md: '0.375rem',
  lg: '0.5rem',
  full: '9999px',
} as const;

// Typography
export const typography = {
  sizes: {
    sm: '0.875rem',
    base: '1rem',
    lg: '1.125rem',
    xl: '1.25rem',
    '2xl': '1.5rem',
    '3xl': '1.875rem',
  },
  weights: {
    normal: '400',
    medium: '500',
    semibold: '600',
    bold: '700',
  },
} as const;

// Button Styles
export const buttonStyles = {
  primary: 'bg-blue-600 text-white hover:bg-blue-700',
  secondary: 'bg-gray-100 text-gray-700 hover:bg-gray-200',
  danger: 'bg-red-600 text-white hover:bg-red-700',
  outline: 'border border-gray-300 text-gray-700 hover:bg-gray-50',
} as const;
```

**Files affected:**
- `resources/js/lib/design-tokens.ts` (new)
- All component files (update to use constants)

**Testing:**
- Verify all components render correctly with constants
- Verify design consistency

**Docker Commands:**
```bash
docker compose exec frontend npm run build 2>&1 | grep -E "(error|Error)" | head -10
```

#### Step 3.2: Add Loading Skeleton Components

**Sub-agents required:**
- `frontend-developer` - To implement components
- `ui-designer` - To review visual design

**Actions:**
1. Create `components/ui/Skeleton.tsx`
2. Create `components/ui/TableSkeleton.tsx`
3. Update `BusinessHoursPage.tsx` to use skeleton during loading

**New File: `resources/js/components/ui/Skeleton.tsx`**

```typescript
import React from 'react';

interface SkeletonProps {
  className?: string;
  variant?: 'text' | 'circular' | 'rectangular';
  width?: string | number;
  height?: string | number;
}

export function Skeleton({ 
  className = '', 
  variant = 'text',
  width,
  height,
}: SkeletonProps) {
  const baseClass = 'animate-pulse bg-gray-200';
  
  const variantClasses = {
    text: 'rounded',
    circular: 'rounded-full',
    rectangular: 'rounded-md',
  };

  return (
    <div
      className={`${baseClass} ${variantClasses[variant]} ${className}`}
      style={{
        width: width ? (typeof width === 'number' ? `${width}px` : width) : undefined,
        height: height ? (typeof height === 'number' ? `${height}px` : height) : undefined,
      }}
    />
  );
}

export function TableSkeleton({ rows = 5, cols = 5 }: { rows?: number; cols?: number }) {
  return (
    <div className="space-y-3">
      {/* Header */}
      <div className="flex space-x-4 pb-2 border-b">
        {Array.from({ length: cols }).map((_, i) => (
          <Skeleton key={`header-${i}`} width="100px" height="20px" />
        ))}
      </div>
      
      {/* Rows */}
      {Array.from({ length: rows }).map((_, rowIndex) => (
        <div key={`row-${rowIndex}`} className="flex space-x-4 py-2 border-b">
          {Array.from({ length: cols }).map((_, colIndex) => (
            <Skeleton 
              key={`cell-${rowIndex}-${colIndex}`} 
              width="100px" 
              height="24px" 
            />
          ))}
        </div>
      ))}
    </div>
  );
}

export function CardSkeleton({ hasHeader = true, hasFooter = true }: { hasHeader?: boolean; hasFooter?: boolean }) {
  return (
    <div className="bg-white shadow rounded-lg p-6 space-y-4">
      {hasHeader && (
        <div className="space-y-2">
          <Skeleton width="40%" height="24px" />
          <Skeleton width="60%" height="16px" />
        </div>
      )}
      
      <div className="space-y-3">
        <Skeleton width="100%" height="40px" />
        <Skeleton width="100%" height="40px" />
        <Skeleton width="100%" height="40px" />
      </div>
      
      {hasFooter && (
        <div className="flex justify-end space-x-2 pt-2">
          <Skeleton width="80px" height="36px" />
          <Skeleton width="80px" height="36px" />
        </div>
      )}
    </div>
  );
}
```

**Updated BusinessHoursPage.tsx:**

```typescript
import { TableSkeleton } from '@/components/ui/Skeleton';

// Replace loading spinner (lines 102-105) with:
{isLoading ? (
  <TableSkeleton rows={10} cols={5} />
) : schedules.length === 0 ? (
  // Empty state
) : (
  // Table
)}
```

**Files affected:**
- `resources/js/components/ui/Skeleton.tsx` (new)
- `resources/js/pages/BusinessHours/BusinessHoursPage.tsx`

**Testing:**
- Verify skeleton displays during loading
- Verify skeleton matches table structure
- Verify card skeleton for forms

**Docker Commands:**
```bash
docker compose exec frontend npm run type-check 2>&1
```

#### Step 3.3: Standardize Button Styles

**Sub-agents required:**
- `ui-designer` - To define standard styles
- `frontend-developer` - To update components

**Actions:**
1. Audit all button usages in components
2. Standardize on consistent styles
3. Update `button.tsx` to enforce consistency

**Standard Button Styles:**

| Type | Class | Usage |
|------|-------|-------|
| Primary | `bg-blue-600 text-white hover:bg-blue-700 rounded-md` | Main actions (Create, Save, Submit) |
| Secondary | `bg-gray-100 text-gray-700 hover:bg-gray-200 rounded-md` | Cancel, Back |
| Danger | `bg-red-600 text-white hover:bg-red-700 rounded-md` | Delete, Remove |
| Outline | `border border-gray-300 text-gray-700 hover:bg-gray-50 rounded-md` | Alternative actions |

**Files affected:**
- `resources/js/components/ui/button.tsx`
- All component files using buttons

**Testing:**
- Verify all buttons use consistent styles
- Verify hover states work
- Verify disabled states work

**Docker Commands:**
```bash
docker compose exec frontend npm run lint 2>&1 | grep -E "className" | head -20
```

---

## Implementation Order

### Phase Order
1. **Phase 1** - Critical Fixes (foundational for other phases)
2. **Phase 2** - Code Modularization (builds on Phase 1)
3. **Phase 3** - UI/UX Polish (final polish)

### Within Each Phase
1. Sub-agent delegation
2. Implementation
3. Testing
4. Code review
5. Documentation update

---

## Sub-agent Usage Summary

| Phase | Step | Sub-agents Required | Timing |
|-------|------|---------------------|--------|
| 1 | 1.1 | `frontend-developer` | Day 1 |
| 1 | 1.2 | `frontend-developer` | Day 1 (after 1.1) |
| 1 | 1.3 | `frontend-developer`, `ui-designer` | Day 2 |
| 1 | 1.4 | `frontend-developer`, `api-designer` | Day 3 |
| 2 | 2.1 | `frontend-developer` | Day 4-5 (after Phase 1) |
| 2 | 2.2 | `ui-designer`, `frontend-developer` | Day 5-6 (after 2.1) |
| 2 | 2.3 | `frontend-developer` | Day 7 (after 2.2) |
| 3 | 3.1 | `ui-designer`, `frontend-developer` | Day 8 (after Phase 2) |
| 3 | 3.2 | `frontend-developer`, `ui-designer` | Day 9 (after 3.1) |
| 3 | 3.3 | `ui-designer`, `frontend-developer` | Day 10 (after 3.2) |

---

## Testing Strategy

### Unit Tests
```bash
# Frontend unit tests
docker compose exec frontend npm run test

# Type checking
docker compose exec frontend npm run type-check

# Linting
docker compose exec frontend npm run lint

# Build
docker compose exec frontend npm run build
```

### Integration Tests
```bash
# Test login form functionality
# Test business hours CRUD operations
# Test toast notifications
# Test loading states
```

---

## Docker Commands Reference

### Development
```bash
# Start containers
docker compose up -d

# View logs
docker compose logs -f frontend

# Run frontend tests
docker compose exec frontend npm run test

# Run type checking
docker compose exec frontend npm run type-check

# Run linting
docker compose exec frontend npm run lint

# Build frontend
docker compose exec frontend npm run build

# View build errors
docker compose exec frontend npm run build 2>&1 | grep -E "(error|Error)" | head -20
```

### Frontend Development Server (Hot Reload)
```bash
# Run frontend dev server
docker compose exec frontend npm run dev

# Or if using Vite
docker compose exec frontend npm run dev -- --host 0.0.0.0
```

---

## Rollback Plan

### Git Branches
Each phase should be developed in a separate branch:
- `frontend/critical-fixes` - Phase 1
- `frontend/modularization` - Phase 2
- `frontend/ui-ux-polish` - Phase 3

### Rollback Commands
```bash
# Revert specific file
git checkout HEAD -- resources/js/pages/BusinessHours/BusinessHoursPage.tsx

# Revert entire phase
git checkout main -- .
git merge --strategy=ours frontend/critical-fixes 2>/dev/null || true
```

---

## Success Criteria

### Phase 1 Success
- [ ] No `alert()` calls in frontend code
- [ ] All action buttons have loading states
- [ ] LoginPage is fully functional
- [ ] Toast notifications work correctly

### Phase 2 Success
- [ ] `useBusinessHours` hook exists and is used
- [ ] `EmptyState` component exists and is used
- [ ] `BusinessHoursForm` is split into smaller components
- [ ] No code duplication in API operations

### Phase 3 Success
- [ ] Design tokens are defined and used
- [ ] Skeleton components exist and are used
- [ ] Button styles are consistent
- [ ] All components pass linting

### Overall Success
- [ ] All tests pass
- [ ] No regression in functionality
- [ ] Code coverage maintained or improved
- [ ] UI/UX is consistent across all pages

---

## Timeline Estimate

| Phase | Estimated Effort | Dependencies |
|-------|------------------|--------------|
| Phase 1 | 3 days | None |
| Phase 2 | 3-4 days | Phase 1 |
| Phase 3 | 2-3 days | Phase 2 |
| **Total** | **8-10 days** | - |

---

## Notes

1. **Backward Compatibility**: All changes must maintain backward compatibility with existing functionality
2. **No Database Changes**: This is purely frontend refactoring
3. **No Backend Changes**: All API contracts remain the same
4. **Design Consistency**: All changes should maintain visual consistency with existing design

---

## Files to Create/Modify Summary

### New Files
- `resources/js/hooks/useBusinessHours.ts`
- `resources/js/components/ui/EmptyState.tsx`
- `resources/js/components/ui/Icons.tsx`
- `resources/js/components/ui/Skeleton.tsx`
- `resources/js/lib/design-tokens.ts`
- `resources/js/components/BusinessHours/WeeklySchedule.tsx`
- `resources/js/components/BusinessHours/Exceptions.tsx`

### Modified Files
- `resources/js/pages/BusinessHours/BusinessHoursPage.tsx`
- `resources/js/pages/BusinessHours/BusinessHoursForm.tsx`
- `resources/js/pages/BusinessHours/BusinessHoursCreatePage.tsx`
- `resources/js/pages/BusinessHours/BusinessHoursEditPage.tsx`
- `resources/js/pages/LoginPage.tsx`
- `resources/js/components/ui/button.tsx`

---

## References

- [Frontend Code Review Report](link-to-report)
- [CLAUDE.md - Empty State Pattern](CLAUDE.md)
- [React Query Documentation](https://tanstack.com/query/latest)
- [Zod Documentation](https://zod.dev/)
- [shadcn/ui Components](https://ui.shadcn.com/)
- [Existing Workplans](docs/workplans/)
