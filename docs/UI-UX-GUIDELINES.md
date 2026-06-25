# OPBX UI/UX Guidelines

This document defines the design language used across the OPBX React admin application. All feature pages should follow these patterns to maintain a consistent user experience.

## Page Layout

- **Outer wrapper**: `<div className="space-y-6">` (no `p-6` on the outer container; layout padding is handled by the shell).
- **Page header block**:
  ```tsx
  <div className="flex justify-between items-start">
    <div>
      <div className="flex items-center gap-3">
        <h1 className="text-3xl font-bold flex items-center gap-2">
          <FeatureIcon className="h-8 w-8" />
          Page Title
        </h1>
        {isReadOnly && (
          <Badge variant="outline" className="bg-gray-50 text-gray-700 border-gray-200">
            Read-Only
          </Badge>
        )}
      </div>
      <p className="text-muted-foreground mt-1">Short page description.</p>
      <div className="flex items-center gap-2 mt-2 text-sm text-muted-foreground">
        <span>Dashboard</span>
        <span>/</span>
        <span className="text-foreground">Page Title</span>
      </div>
    </div>
    {canManage && (
      <Button onClick={openCreateDialog}>
        <Plus className="h-4 w-4 mr-2" />
        Create Item
      </Button>
    )}
  </div>
  ```
- For dashboard/analytics pages without a primary create action, keep the same header structure but omit the button.

## Filters

- Wrap filter controls in a `Card` with `CardContent className="p-4"`.
- Use a flex row with `gap-3` and `flex-wrap`.
- Search input must have a magnifying glass icon and `pl-9` / `pl-10` padding.
- Include a refresh button (circular outline icon) when refetching is supported.
- Use shadcn `Select` components for dropdown filters with a `Filter` icon in the trigger.
- Show a "Clear Filters" button (`variant="ghost"`) when filters are active.

## Data Tables

- Use `StandardDataTable` from `@/components/design-system` for all list views.
- Provide:
  - `identityIcon`, `identityIconBg`, `identityIconColor`
  - `getIdentityPrimary`, `getIdentitySecondary`
  - `onRowClick`, `onIdentityClick`
  - `sortField`, `sortDirection`, `onSort`
  - `onDelete`, `canDelete` (disable edit/view if not needed with `canView={false} canEdit={false}`)
  - `emptyState` using the `EmptyState` design-system component
- Wrap the table in a `Card` with `CardContent className="pt-6"`.
- Status badges should be clickable to toggle status for users with permission, with loading spinner during mutation.

## Empty State

- Use the `EmptyState` component from `@/components/design-system`.
- Required props:
  - `icon`: relevant Lucide icon
  - `title`: `"No [items] found"`
  - `description`: `"Try adjusting your filters"` when filters active, else `"Get started by creating your first [item]"`
  - `action`: create button only when no filters active and user has create permission

## Pagination

- Show pagination controls only when `totalPages > 1`.
- Use the pattern:
  ```tsx
  <div className="flex items-center justify-between mt-4 pt-4 border-t">
    <div className="text-sm text-muted-foreground">
      Showing {(currentPage - 1) * perPage + 1} to {Math.min(currentPage * perPage, totalItems)} of {totalItems} items
    </div>
    <div className="flex items-center gap-2">
      <Button variant="outline" size="sm" onClick={...} disabled={currentPage === 1}>Previous</Button>
      <div className="text-sm">Page {currentPage} of {totalPages}</div>
      <Button variant="outline" size="sm" onClick={...} disabled={currentPage === totalPages}>Next</Button>
    </div>
  </div>
  ```

## KPI / Stat Cards

Stat cards must follow the main Dashboard pattern:

```tsx
<Card className="hover:shadow-md transition-shadow">
  <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
    <CardTitle className="text-sm font-medium">{title}</CardTitle>
    <div className={cn('p-2 rounded-lg', bgColor)}>
      <Icon className={cn('h-5 w-5', color)} />
    </div>
  </CardHeader>
  <CardContent>
    <div className="text-2xl font-bold">{value}</div>
  </CardContent>
</Card>
```

- Title on the left, icon on the right inside a colored rounded square (`p-2 rounded-lg`).
- Use semantic Tailwind color pairs such as `bg-blue-100` + `text-blue-600`.
- Value below the header using `text-2xl font-bold`.
- Wrap in `grid gap-4 md:grid-cols-2 lg:grid-cols-4`.
- Add `cursor-pointer hover:shadow-md transition-shadow` when the card is clickable.

## Status Badges

- Active status: `bg-green-100 text-green-800 hover:bg-green-200`
- Inactive/disabled status: `bg-gray-100 text-gray-800 hover:bg-gray-200`
- Use `cursor-pointer transition-all hover:scale-105` when clickable.
- Show spinner and keep current label text while the toggle mutation is pending.

## Forms

- Use `react-hook-form` + Zod for validation.
- Group related fields into `Card` sections with `CardHeader` and `CardTitle`.
- Form actions at the bottom: primary submit button first, then `Cancel` outline button.
- Page header for forms should use the same `text-3xl font-bold flex items-center gap-2` pattern with a relevant icon.

## Colors & Badges

- Prefer semantic Tailwind color classes over arbitrary values:
  - Blue: `bg-blue-100 text-blue-600` for primary identity icons
  - Green: `bg-green-100 text-green-800` for active status
  - Gray: `bg-gray-100 text-gray-800` for inactive status
  - Purple: `bg-purple-100 text-purple-600` for conference/media
  - Cyan: `bg-cyan-100 text-cyan-800` for AI features
- Use `Badge variant="outline"` for tags/chips and `Badge variant="secondary"` for neutral states.

## Icons

- Use Lucide icons only.
- Feature page headings use `h-8 w-8`.
- Table identity icons use `h-5 w-5` inside a `h-10 w-10 rounded-full` container.
- Inline badges use `h-3.5 w-3.5` or `h-4 w-4`.

## Loading & Error States

- For full-page loading, show a `Card` with `LoadingSpinner` or skeletons.
- For error states, show a centered message with a retry button inside a `Card`.
- Inline table loading is handled by `StandardDataTable` `isLoading` prop.

## Responsive Behavior

- Use `flex flex-col sm:flex-row` for header actions and filter rows.
- Tables scroll horizontally on small screens by default.
- Avoid `max-w-*` wrappers on standard CRUD pages unless the content is inherently narrow (e.g., a simple configuration form).

## Components to Use

| Purpose | Component | Location |
|---------|-----------|----------|
| Data table | `StandardDataTable` | `@/components/design-system` |
| Empty state | `EmptyState` | `@/components/design-system` |
| Loading spinner | `LoadingSpinner` | `@/components/design-system` |
| Confirmation dialog | `ConfirmDialog` | `@/components/design-system` |
| KPI/stat cards | custom `Card` with `CardHeader`/`CardContent` | `@/components/ui/card` |
| Buttons | `Button` | `@/components/ui/button` |
| Form inputs | `Input`, `Select`, `Switch`, `Textarea`, `Label` | `@/components/ui/*` |

## Anti-Patterns

- Do not use `p-6` on the outer page wrapper.
- Do not use `text-2xl` for page titles.
- Do not build custom tables when `StandardDataTable` can be used.
- Do not inline empty states; always use `EmptyState`.
- Do not omit the refresh button from filter bars when data can be refetched.
- Do not use raw HTML `<input>` elements; use shadcn `Input`.
