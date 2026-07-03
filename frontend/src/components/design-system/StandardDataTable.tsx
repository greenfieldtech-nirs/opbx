import React, { ReactNode } from 'react';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow
} from '@/components/ui/table';
import { Button } from '@/components/ui/button';
import {
    Eye,
    Edit2,
    Trash2,
    ChevronDown,
    ChevronUp,
    LucideIcon
} from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { LoadingSpinner } from './LoadingSpinner';

export interface Column<T> {
    header: string;
    accessorKey?: keyof T;
    sortKey?: string;
    className?: string;
    cell?: (item: T) => ReactNode;
}

export interface StandardDataTableProps<T> {
    data: T[];
    columns: Column<T>[];
    isLoading?: boolean;
    onRowClick?: (item: T) => void;
    // Identity column props
    identityIcon: LucideIcon;
    identityIconColor?: string;
    identityIconBg?: string;
    getIdentityPrimary: (item: T) => string;
    getIdentitySecondary: (item: T) => string;
    onIdentityClick?: (item: T, e: React.MouseEvent) => void;
    showIdentityColumn?: boolean;
    // Sorting props
    sortField?: string;
    sortDirection?: 'asc' | 'desc';
    onSort?: (field: string) => void;
    // Actions props
    onView?: (item: T, e: React.MouseEvent) => void;
    onEdit?: (item: T, e: React.MouseEvent) => void;
    onDelete?: (item: T, e: React.MouseEvent) => void;
    canView?: boolean;
    canEdit?: boolean;
    canDelete?: boolean;
    // Empty state
    emptyState?: ReactNode;
}

export function StandardDataTable<T extends { id: string | number }>({
    data,
    columns,
    isLoading,
    onRowClick,
    identityIcon: IdentityIcon,
    identityIconColor = "text-blue-600",
    identityIconBg = "bg-blue-100",
    getIdentityPrimary,
    getIdentitySecondary,
    onIdentityClick,
    showIdentityColumn = true,
    sortField,
    sortDirection,
    onSort,
    onView,
    onEdit,
    onDelete,
    canView = true,
    canEdit = true,
    canDelete = true,
    emptyState
}: StandardDataTableProps<T>) {

    const renderSortIcon = (key: string) => {
        if (sortField !== key) return null;
        return sortDirection === 'asc' ? (
            <ChevronUp className="h-4 w-4 ml-1" />
        ) : (
            <ChevronDown className="h-4 w-4 ml-1" />
        );
    };

    const handleAction = (callback: ((item: T, e: React.MouseEvent) => void) | undefined, item: T, e: React.MouseEvent) => {
        e.stopPropagation();
        callback?.(item, e);
    };

    if (isLoading) {
        return (
            <div className="flex justify-center py-20">
                <LoadingSpinner size="lg" />
            </div>
        );
    }

    if (data.length === 0 && emptyState) {
        return <>{emptyState}</>;
    }

    return (
        <TooltipProvider>
            <Table>
                <TableHeader>
                    <TableRow>
                        {showIdentityColumn && (
                            <TableHead
                                className={cn("w-[250px]", onSort && "cursor-pointer")}
                                onClick={() => onSort?.('identity')}
                            >
                                <div className="flex items-center">
                                    Name/Identity
                                    {onSort && renderSortIcon('identity')}
                                </div>
                            </TableHead>
                        )}
                        {columns.map((column, idx) => (
                            <TableHead key={idx} className={column.className}>
                                <button
                                    onClick={() => column.sortKey && onSort?.(column.sortKey)}
                                    className={cn(
                                        "flex items-center gap-1",
                                        column.sortKey && onSort && "hover:text-foreground cursor-pointer"
                                    )}
                                >
                                    {column.header}
                                    {column.sortKey && renderSortIcon(column.sortKey)}
                                </button>
                            </TableHead>
                        ))}
                        {(canView || canEdit || canDelete) && (
                            <TableHead className="text-right">Actions</TableHead>
                        )}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {data.map((item) => (
                        <TableRow
                            key={item.id}
                            className={cn(onRowClick && "cursor-pointer hover:bg-muted/50 transition-colors")}
                            onClick={() => onRowClick?.(item)}
                        >
                            {/* Identity Cell */}
                            {showIdentityColumn && (
                                <TableCell>
                                    <button
                                        onClick={(e) => {
                                            if (onIdentityClick) {
                                                e.stopPropagation();
                                                onIdentityClick(item, e);
                                            } else if (onRowClick) {
                                                // Fallback to row click if identity specifically doesn't have its own
                                            }
                                        }}
                                        className={cn(
                                            "flex items-center gap-3 text-left translate-y-[-2px]",
                                            onIdentityClick && "hover:underline"
                                        )}
                                    >
                                        <div className={cn("h-10 w-10 rounded-full flex items-center justify-center flex-shrink-0", identityIconBg)}>
                                            <IdentityIcon className={cn("h-5 w-5", identityIconColor)} />
                                        </div>
                                        <div>
                                            <div className="font-medium text-primary">{getIdentityPrimary(item)}</div>
                                            <div className="text-xs text-muted-foreground capitalize">
                                                {getIdentitySecondary(item)}
                                            </div>
                                        </div>
                                    </button>
                                </TableCell>
                            )}

                            {/* Dynamic Columns */}
                            {columns.map((column, idx) => (
                                <TableCell key={idx} className={column.className}>
                                    {column.cell
                                        ? column.cell(item)
                                        : column.accessorKey
                                            ? String(item[column.accessorKey] ?? '-')
                                            : '-'}
                                </TableCell>
                            ))}

                            {/* Actions Cell - only render if at least one action is enabled */}
                            {(canView || canEdit || canDelete) && (
                                <TableCell className="text-right">
                                    <div className="flex items-center justify-end gap-1">
                                        {canView && (
                                            <Tooltip>
                                                <TooltipTrigger asChild>
                                                    <Button
                                                        variant="ghost"
                                                        size="sm"
                                                        className="h-8 w-8 p-0"
                                                        onClick={(e) => handleAction(onView, item, e)}
                                                >
                                                    <Eye className="h-4 w-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>View Details</TooltipContent>
                                        </Tooltip>
                                    )}
                                    {canEdit && (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-8 w-8 p-0"
                                                    onClick={(e) => handleAction(onEdit, item, e)}
                                                >
                                                    <Edit2 className="h-4 w-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>Edit</TooltipContent>
                                        </Tooltip>
                                    )}
                                    {canDelete && (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    className="h-8 w-8 p-0 text-destructive hover:text-destructive hover:bg-destructive/10"
                                                    onClick={(e) => handleAction(onDelete, item, e)}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </TooltipTrigger>
                                            <TooltipContent>Delete</TooltipContent>
                                        </Tooltip>
                                    )}
                                </div>
                            </TableCell>
                            )}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </TooltipProvider>
    );
}
