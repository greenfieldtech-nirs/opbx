# Implementation Plan: Generic Destination Selector Components

**Date:** 2026-02-11  
**Author:** Claude Code  
**Status:** Ready for Implementation

---

## Executive Summary

Create reusable React components for destination type and destination selection that can be used consistently across all pages in the OpBX application (IVR Menus, Ring Groups, DID Numbers, Business Hours, etc.).

---

## Background & Problem Statement

### Current State
- Code duplication across IVRMenus.tsx (~250 lines), RingGroupForm.tsx (~180 lines), DIDNumbers.tsx (~150 lines)
- Each page implements its own data fetching for destinations
- Inconsistent filtering (e.g., extensions show 'user' & 'forward' vs 'all')
- Manual mapping of `destination_type` to available resources
- Repetitive JSX for Select components with 7+ options

### Target State
- Single source of truth for destination selection UI
- Consistent behavior across all pages
- Reduced code duplication by ~75%
- Type-safe components with proper TypeScript interfaces

---

## Component Architecture

### 1. Type Definitions (`types/destination.types.ts`)

```typescript
export type DestinationType = 
  | 'extension' 
  | 'ring_group' 
  | 'conference_room' 
  | 'ivr_menu' 
  | 'business_hours' 
  | 'ai_assistant' 
  | 'ai_load_balancer' 
  | 'hangup';

export interface DestinationOption {
  id: string;
  type: DestinationType;
  label: string;
  subLabel?: string;
  icon?: string;
  badge?: {
    color: string;
    text: string;
  };
  metadata?: Record<string, any>;
}

export interface TypeMetadata {
  value: DestinationType;
  label: string;
  description: string;
  icon: string;
  category: 'pbx' | 'ai' | 'routing' | 'termination';
}
```

### 2. Data Hook (`hooks/useDestinations.ts`)

**Purpose:** Centralized data fetching for all destination types using React Query.

**Features:**
- Parallel fetching of all destination resources
- Intelligent caching with React Query
- Loading and error state management
- Organization-scoped queries

**API:**
```typescript
function useDestinations(organizationId?: string): {
  data: {
    extensions: Extension[];
    ringGroups: RingGroup[];
    conferenceRooms: ConferenceRoom[];
    ivrMenus: IvrMenu[];
    businessHours: BusinessHoursSchedule[];
    aiAssistants: Extension[];
    aiLoadBalancers: AiLoadBalancer[];
  };
  isLoading: boolean;
  isError: boolean;
  refetch: () => void;
}
```

### 3. DestinationTypeSelector Component

**Purpose:** Dropdown for selecting destination category (Extension, Ring Group, etc.)

**Props:**
```typescript
interface DestinationTypeSelectorProps {
  value: DestinationType | null;
  onChange: (value: DestinationType, metadata: TypeMetadata) => void;
  label?: string;
  placeholder?: string;
  disabled?: boolean;
  allowedTypes?: DestinationType[];
  includeHangup?: boolean;
  showDescriptions?: boolean;
  size?: 'sm' | 'default' | 'lg';
}
```

**UI Features:**
- Shadcn/ui Select component
- Icons for each type (Phone, Users, Menu, Bot, Scale, etc.)
- Optional descriptions under each option
- Consistent styling with design system

### 4. DestinationSelector Component

**Purpose:** Dropdown for selecting actual destination based on type.

**Props:**
```typescript
interface DestinationSelectorProps {
  type: DestinationType | null;
  value: string;
  onChange: (value: string, option: DestinationOption) => void;
  label?: string;
  placeholder?: string;
  disabled?: boolean;
  organizationId?: string;
  extensionTypes?: ('user' | 'forward' | 'ai_assistant')[];
  showBadges?: boolean;
  emptyMessage?: string;
  loadingMessage?: string;
}
```

**UI Features:**
- Dynamic content based on selected type
- Type badges (User, Forward, AI Assistant, etc.)
- Empty state messages
- Loading skeletons
- Grouping by type for extensions

### 5. DestinationTypeAndSelector Component (Combined)

**Purpose:** Convenience component combining both selectors.

**Props:**
```typescript
interface DestinationTypeAndSelectorProps {
  typeValue: DestinationType | null;
  destinationValue: string;
  onChange: (type: DestinationType, destinationId: string) => void;
  typeLabel?: string;
  destinationLabel?: string;
  disabled?: boolean;
  allowedTypes?: DestinationType[];
  includeHangup?: boolean;
  layout?: 'horizontal' | 'vertical' | 'grid';
  gridColumns?: {
    type: number;
    destination: number;
  };
  extensionTypes?: ('user' | 'forward' | 'ai_assistant')[];
}
```

**Layout Options:**
- `horizontal`: Side-by-side (Type | Destination)
- `vertical`: Stacked (Type above Destination)
- `grid`: Custom grid columns

### 6. DestinationBadge Component

**Purpose:** Display-only component for showing destination info.

**Props:**
```typescript
interface DestinationBadgeProps {
  type: DestinationType;
  label: string;
  subType?: 'user' | 'forward' | 'ai_assistant';
  size?: 'sm' | 'md' | 'lg';
  showIcon?: boolean;
}
```

---

## File Structure

```
frontend/src/components/destinations/
├── index.ts                           # Public exports
├── DestinationTypeSelector.tsx        # Type dropdown
├── DestinationSelector.tsx            # Destination dropdown
├── DestinationTypeAndSelector.tsx     # Combined component
├── DestinationBadge.tsx               # Display badge
├── types/
│   └── destination.types.ts           # TypeScript definitions
├── hooks/
│   ├── useDestinations.ts             # Data fetching hook
│   └── useDestinationOptions.ts       # Option transformation hook
└── utils/
    ├── destination-config.ts          # Type metadata config
    └── destination-helpers.ts         # Helper functions
```

---

## Implementation Phases

### Phase 1: Foundation (Commits 1-3)
**Goal:** Create type definitions, utilities, and data hook.

**Commit 1: Type Definitions**
- Create `types/destination.types.ts`
- Define all interfaces and types
- Export from index.ts

**Commit 2: Configuration & Helpers**
- Create `utils/destination-config.ts` with type metadata
- Create `utils/destination-helpers.ts` with formatting functions
- Add unit tests for helpers

**Commit 3: Data Hook**
- Create `hooks/useDestinations.ts`
- Implement parallel data fetching
- Add proper error handling and caching
- Test hook with mock data

### Phase 2: Core Components (Commits 4-6)
**Goal:** Build individual selector components.

**Commit 4: DestinationTypeSelector**
- Create component with full prop support
- Implement icon mapping
- Add storybook/demo page
- Test all type options

**Commit 5: DestinationSelector**
- Create component with dynamic content
- Implement option transformation
- Add badge rendering
- Test with all destination types

**Commit 6: DestinationBadge**
- Create badge component
- Implement color scheme
- Add size variants

### Phase 3: Combined Component (Commit 7)
**Goal:** Create convenience combined component.

**Commit 7: DestinationTypeAndSelector**
- Create combined component
- Implement layout options
- Handle state synchronization
- Add comprehensive tests

### Phase 4: Integration (Commits 8-10)
**Goal:** Replace existing code in IVRMenus.tsx.

**Commit 8: Refactor IVR Menu Options**
- Replace manual selects with new components
- Update form handling
- Test creation flow
- Verify validation

**Commit 9: Refactor IVR Failover Section**
- Replace failover selects with new components
- Handle 'hangup' special case
- Test failover functionality

**Commit 10: Cleanup & Testing**
- Remove old code
- Add error boundaries
- Run full test suite
- Verify no regressions

---

## Migration Strategy

### Page-by-Page Rollout

1. **IVRMenus.tsx** (Primary target) - Full implementation
2. **RingGroupForm.tsx** - Replace fallback section
3. **DIDNumbers.tsx** - Replace routing section
4. **BusinessHours.tsx** - Replace routing section
5. **Extensions.tsx** - Replace forwarding section

### Backward Compatibility

- Keep existing types in `api.types.ts` (IvrDestinationType, etc.)
- Map new types to existing API types
- No backend changes required

---

## Testing Plan

### Unit Tests
- Type definition validation
- Helper function logic
- Hook data transformation
- Component rendering

### Integration Tests
- Hook with React Query
- Component interactions
- Form integration
- Error handling

### E2E Tests
- IVR Menu creation flow
- Ring Group fallback flow
- DID Number routing flow

### Visual Regression
- Screenshots of all states
- Mobile responsiveness
- Dark mode support

---

## Expected Benefits

### Code Reduction
| Page | Before | After | Savings |
|------|--------|-------|---------|
| IVRMenus.tsx | ~250 lines | ~60 lines | 76% |
| RingGroupForm.tsx | ~180 lines | ~45 lines | 75% |
| DIDNumbers.tsx | ~150 lines | ~40 lines | 73% |
| **Total** | **~580 lines** | **~145 lines** | **75%** |

### Consistency
- Same UI patterns across all pages
- Consistent labeling and icons
- Unified error handling
- Standardized loading states

### Maintainability
- Single source of truth for destination logic
- Easy to add new destination types
- Centralized caching strategy
- Type-safe throughout

---

## Constraints & Considerations

### Technical Constraints
- Use existing React Query setup
- Compatible with Shadcn/ui Select
- Support organization scoping
- Handle all existing destination types

### User Experience
- No visual changes to existing UI (pixel-perfect replacement)
- Maintain all current functionality
- Preserve form validation behavior
- Support keyboard navigation

### Performance
- Leverage React Query caching
- Lazy load destination data
- Minimize re-renders
- Optimize bundle size

---

## Risk Mitigation

| Risk | Mitigation |
|------|------------|
| Breaking changes | Incremental rollout, feature flags |
| Data inconsistencies | Comprehensive testing, type safety |
| Performance regression | React Query caching, lazy loading |
| UI regressions | Visual regression testing |

---

## Success Criteria

- [ ] All components created and tested
- [ ] IVRMenus.tsx refactored with 75%+ code reduction
- [ ] No visual regressions
- [ ] All existing functionality preserved
- [ ] TypeScript compilation passes
- [ ] Unit tests pass (>80% coverage)
- [ ] E2E tests pass
- [ ] Documentation complete

---

## Implementation Commands

```bash
# Create directory structure
mkdir -p frontend/src/components/destinations/{types,hooks,utils}

# Run tests
npm run test -- --watch

# Type check
npx tsc --noEmit

# Build
npm run build

# Docker commands (use 'docker compose', NOT 'docker-compose')
docker compose up -d
docker compose logs -f frontend
```

---

## Review Checklist

Before each commit:
- [ ] Code follows project conventions
- [ ] TypeScript strict mode passes
- [ ] No console.log statements
- [ ] Proper error handling
- [ ] Accessible (ARIA labels, keyboard nav)
- [ ] Responsive design
- [ ] Incremental git commit with concise message

---

**Next Step:** Proceed with Phase 1 - Foundation (Type Definitions)
