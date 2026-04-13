# Caller ID Pooling - Frontend Specification

## Overview
Frontend changes for the Caller ID Pooling feature in the Auto Dialer Campaigns UI.

---

## 1. Component Changes

### 1.1 Campaign Form - Caller ID Section

**Location:** `frontend/src/pages/AutoDialerCampaignForm.tsx`

**Current State:** Single dropdown for Caller ID selection

**New Design:** Multi-select with strategy picker

```tsx
// New form section layout
<Card>
  <CardHeader>
    <CardTitle>Caller ID Configuration</CardTitle>
    <CardDescription>
      Select one or more phone numbers for outbound calls
    </CardDescription>
  </CardHeader>
  <CardContent className="space-y-6">
    {/* Strategy Selector */}
    <StrategySelector 
      value={form.watch('caller_id_strategy')}
      onChange={(value) => form.setValue('caller_id_strategy', value)}
    />
    
    {/* Multi-select DID picker */}
    <CallerIdPoolSelector
      selected={form.watch('caller_id_pool')}
      onChange={(pool) => form.setValue('caller_id_pool', pool)}
      maxSelection={100}
    />
    
    {/* Pool preview/summary */}
    <CallerIdPoolSummary pool={form.watch('caller_id_pool')} />
  </CardContent>
</Card>
```

---

### 1.2 Strategy Selector Component

**New File:** `frontend/src/components/AutoDialer/StrategySelector.tsx`

```tsx
interface StrategySelectorProps {
  value: CallerIdStrategy;
  onChange: (strategy: CallerIdStrategy) => void;
  disabled?: boolean;
}

type CallerIdStrategy = 'round_robin' | 'random' | 'least_recently_used';

const strategies: { value: CallerIdStrategy; label: string; description: string; icon: LucideIcon }[] = [
  {
    value: 'round_robin',
    label: 'Round Robin',
    description: 'Cycle through Caller IDs sequentially (1, 2, 3, 1, 2, 3...)',
    icon: ListOrdered
  },
  {
    value: 'random',
    label: 'Random',
    description: 'Select Caller IDs randomly for each call',
    icon: Shuffle
  },
  {
    value: 'least_recently_used',
    label: 'Least Recently Used',
    description: 'Select the Caller ID used longest ago',
    icon: Clock
  }
];

export function StrategySelector({ value, onChange, disabled }: StrategySelectorProps) {
  return (
    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
      {strategies.map((strategy) => (
        <StrategyCard
          key={strategy.value}
          strategy={strategy}
          isSelected={value === strategy.value}
          onClick={() => onChange(strategy.value)}
          disabled={disabled}
        />
      ))}
    </div>
  );
}
```

**Visual Design:**
- Card-based selection (like strategy cards elsewhere in the app)
- Selected state: highlighted border, checkmark icon
- Disabled state: reduced opacity, no pointer events

---

### 1.3 Caller ID Pool Selector Component

**New File:** `frontend/src/components/AutoDialer/CallerIdPoolSelector.tsx`

```tsx
interface CallerIdPoolSelectorProps {
  selected: CallerIdPoolItem[];
  onChange: (pool: CallerIdPoolItem[]) => void;
  maxSelection?: number;
  disabled?: boolean;
}

interface CallerIdPoolItem {
  did_id: number;
  phone_number: string;
  friendly_name?: string;
  weight: number;
}
```

**Features:**
1. **Multi-select Dropdown:** Searchable, checkbox-based selection
2. **Selected Items List:** Drag-and-drop reorderable (for Round Robin priority)
3. **Weight Input:** Numeric input for each selected item (1-100)
4. **Validation:** Shows error if >100 items selected
5. **Loading State:** Shows skeleton while fetching available DIDs

**Empty State:**
```tsx
{selected.length === 0 && (
  <div className="text-center py-8 border-2 border-dashed rounded-lg">
    <Phone className="h-12 w-12 mx-auto text-muted-foreground mb-4" />
    <h3 className="text-lg font-semibold mb-2">No Caller IDs Selected</h3>
    <p className="text-muted-foreground mb-4">
      Select phone numbers from your organization's DIDs
    </p>
    <Button onClick={openSelector}>
      <Plus className="h-4 w-4 mr-2" />
      Add Caller IDs
    </Button>
  </div>
)}
```

---

### 1.4 Pool Summary Component

**New File:** `frontend/src/components/AutoDialer/CallerIdPoolSummary.tsx`

Displays a summary of the selected pool:

```tsx
interface PoolSummaryProps {
  pool: CallerIdPoolItem[];
}

export function CallerIdPoolSummary({ pool }: PoolSummaryProps) {
  const totalWeight = pool.reduce((sum, item) => sum + item.weight, 0);
  
  return (
    <div className="bg-muted p-4 rounded-lg">
      <div className="flex items-center justify-between mb-2">
        <span className="font-medium">Pool Summary</span>
        <Badge variant="secondary">{pool.length} numbers</Badge>
      </div>
      <div className="space-y-2">
        {pool.map((item) => (
          <div key={item.did_id} className="flex items-center justify-between text-sm">
            <div className="flex items-center gap-2">
              <Phone className="h-4 w-4 text-muted-foreground" />
              <span>{item.friendly_name || item.phone_number}</span>
            </div>
            <div className="flex items-center gap-4">
              <span className="text-muted-foreground">
                {Math.round((item.weight / totalWeight) * 100)}% distribution
              </span>
              <Badge variant="outline">weight: {item.weight}</Badge>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
```

---

## 2. Campaign Detail Page Changes

### 2.1 Caller ID Stats Section

**Location:** `frontend/src/pages/AutoDialerCampaignDetail.tsx`

Add new section showing Caller ID usage statistics:

```tsx
<Card>
  <CardHeader>
    <CardTitle>Caller ID Statistics</CardTitle>
    <CardDescription>
      Performance breakdown by phone number
    </CardDescription>
  </CardHeader>
  <CardContent>
    <Table>
      <TableHeader>
        <TableRow>
          <TableHead>Phone Number</TableHead>
          <TableHead>Total Calls</TableHead>
          <TableHead>Completed</TableHead>
          <TableHead>Failed</TableHead>
          <TableHead>Success Rate</TableHead>
          <TableHead>Last Used</TableHead>
        </TableRow>
      </TableHeader>
      <TableBody>
        {callerIdStats?.map((stat) => (
          <TableRow key={stat.did_id}>
            <TableCell>
              <div className="flex items-center gap-2">
                <Phone className="h-4 w-4" />
                <div>
                  <div className="font-medium">{stat.phone_number}</div>
                  {stat.friendly_name && (
                    <div className="text-sm text-muted-foreground">
                      {stat.friendly_name}
                    </div>
                  )}
                </div>
              </div>
            </TableCell>
            <TableCell>{stat.total_calls}</TableCell>
            <TableCell className="text-green-600">{stat.completed_calls}</TableCell>
            <TableCell className="text-red-600">{stat.failed_calls}</TableCell>
            <TableCell>
              <Progress value={stat.success_rate} className="w-20" />
              <span className="text-sm text-muted-foreground ml-2">
                {stat.success_rate.toFixed(1)}%
              </span>
            </TableCell>
            <TableCell>
              {stat.last_used_at 
                ? formatDistanceToNow(new Date(stat.last_used_at), { addSuffix: true })
                : 'Never'}
            </TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  </CardContent>
</Card>
```

---

## 3. Campaign List Changes

### 3.1 Pool Indicator

**Location:** `frontend/src/pages/AutoDialerCampaigns.tsx`

Add indicator for campaigns using Caller ID pooling:

```tsx
// In campaign card/row
<div className="flex items-center gap-2">
  {campaign.caller_id_pool_enabled && (
    <Tooltip>
      <TooltipTrigger>
        <Badge variant="secondary" className="gap-1">
          <Users className="h-3 w-3" />
          {campaign.caller_id_pool.length} Caller IDs
        </Badge>
      </TooltipTrigger>
      <TooltipContent>
        <p>Using {campaign.caller_id_strategy} strategy</p>
      </TooltipContent>
    </Tooltip>
  )}
</div>
```

---

## 4. Monitor Page Changes

### 4.1 Active Calls Table

**Location:** `frontend/src/pages/AutoDialerMonitor.tsx`

Add Caller ID column to active calls table:

```tsx
<TableRow>
  <TableHead>Phone Number</TableHead>
  <TableHead>Caller ID</TableHead>
  <TableHead>Status</TableHead>
  <TableHead>Duration</TableHead>
</TableRow>

// In data rows
<TableCell>{session.caller_id || '-'}</TableCell>
```

---

## 5. Form Validation

### 5.1 Zod Schema

**Update:** `frontend/src/schemas/campaignSchema.ts`

```typescript
export const campaignSchema = z.object({
  // ... existing fields
  
  caller_id_strategy: z.enum(['round_robin', 'random', 'least_recently_used']),
  
  caller_id_pool: z.array(
    z.object({
      did_id: z.number().int().positive(),
      weight: z.number().int().min(1).max(100).default(1)
    })
  )
  .min(1, 'At least one Caller ID is required')
  .max(100, 'Maximum 100 Caller IDs allowed')
});

export type CampaignFormData = z.infer<typeof campaignSchema>;
```

---

## 6. API Hooks

### 6.1 New Hooks

**File:** `frontend/src/hooks/useCallerIdPool.ts`

```typescript
export function useAvailableCallerIds(excludeCampaignId?: number) {
  return useQuery({
    queryKey: ['available-caller-ids', excludeCampaignId],
    queryFn: () => autoDialerCampaignsApi.getAvailableCallerIds(excludeCampaignId),
  });
}

export function useCallerIdStats(campaignId: number) {
  return useQuery({
    queryKey: ['caller-id-stats', campaignId],
    queryFn: () => autoDialerCampaignsApi.getCallerIdStats(campaignId),
    refetchInterval: 30000, // Refresh every 30s
  });
}

export function useResetCallerIdCycle() {
  return useMutation({
    mutationFn: (campaignId: number) => 
      autoDialerCampaignsApi.resetCallerIdCycle(campaignId),
  });
}
```

---

## 7. UI States

### 7.1 Loading State

```tsx
{isLoadingAvailableDids && (
  <div className="space-y-2">
    <Skeleton className="h-10 w-full" />
    <Skeleton className="h-10 w-full" />
    <Skeleton className="h-10 w-full" />
  </div>
)}
```

### 7.2 Error State

```tsx
{isError && (
  <Alert variant="destructive">
    <AlertCircle className="h-4 w-4" />
    <AlertTitle>Error loading available phone numbers</AlertTitle>
    <AlertDescription>
      Please try again or contact support if the problem persists.
    </AlertDescription>
  </Alert>
)}
```

---

## 8. Accessibility

### 8.1 ARIA Labels

```tsx
<button 
  aria-label={`Select ${strategy.label} strategy`}
  aria-pressed={isSelected}
>
  {/* content */}
</button>

<select 
  aria-label="Select Caller ID phone numbers"
  aria-describedby="caller-id-help"
>
  {/* options */}
</select>
```

### 8.2 Keyboard Navigation

- Tab through strategy cards
- Space/Enter to select
- Arrow keys for reordering pool items

---

## 9. Responsive Design

| Breakpoint | Layout |
|------------|--------|
| Desktop (≥1024px) | Strategy cards in 3-column grid |
| Tablet (768-1023px) | Strategy cards in 2-column grid |
| Mobile (<768px) | Strategy cards stacked vertically |

---

## 10. Dependencies

### 10.1 New Dependencies

```bash
npm install @dnd-kit/core @dnd-kit/sortable
# For drag-and-drop pool reordering
```

### 10.2 Existing Dependencies Used

- `react-hook-form` - Form management
- `@tanstack/react-query` - Data fetching
- `zod` - Validation
- `lucide-react` - Icons
- `date-fns` - Date formatting
