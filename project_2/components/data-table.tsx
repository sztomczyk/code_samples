import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    ColumnDef,
    ColumnFiltersState,
    ExpandedState,
    flexRender,
    getCoreRowModel,
    getExpandedRowModel,
    getFilteredRowModel,
    getGroupedRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    GroupingState,
    SortingState,
    useReactTable,
    VisibilityState,
} from '@tanstack/react-table';
import { ChevronDown, ChevronRight } from 'lucide-react';
import { useState } from 'react';

/**
 * Data Table Component
 */

interface DataTableProps<TData, TValue> {
    columns: ColumnDef<TData, TValue>[];
    data: TData[];
    searchPlaceholder?: string;
    groupBy?: string;
    fixedLayout?: boolean;
    onRowClick?: (row: TData) => void;
}

export function DataTable<TData, TValue>({
    columns,
    data,
    searchPlaceholder = 'Szukaj...',
    groupBy,
    fixedLayout = false,
    onRowClick,
}: DataTableProps<TData, TValue>) {
    // Table state
    const [sorting, setSorting] = useState<SortingState>([]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>({});
    const [globalFilter, setGlobalFilter] = useState('');
    const [grouping, setGrouping] = useState<GroupingState>(
        groupBy ? [groupBy] : [],
    );
    const [expanded, setExpanded] = useState<ExpandedState>({});

    // Initialize table with all features
    const table = useReactTable({
        data,
        columns,
        onSortingChange: setSorting,
        onColumnFiltersChange: setColumnFilters,
        onGroupingChange: setGrouping,
        onExpandedChange: setExpanded,
        getCoreRowModel: getCoreRowModel(),
        getPaginationRowModel: getPaginationRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        getGroupedRowModel: getGroupedRowModel(),
        getExpandedRowModel: getExpandedRowModel(),
        onColumnVisibilityChange: setColumnVisibility,
        onGlobalFilterChange: setGlobalFilter,
        globalFilterFn: 'includesString',
        state: {
            sorting,
            columnFilters,
            columnVisibility,
            globalFilter,
            grouping,
            expanded,
        },
    });

    return (
        <div className="w-full space-y-4">
            {/* Global Search */}
            <div className="flex items-center">
                <Input
                    placeholder={searchPlaceholder}
                    value={globalFilter ?? ''}
                    onChange={(event) => setGlobalFilter(event.target.value)}
                    className="max-w-sm"
                />
            </div>

            {/* Table */}
            <div className="overflow-hidden rounded-lg border border-sidebar-border/70 dark:border-sidebar-border">
                <Table style={fixedLayout ? { tableLayout: 'fixed' } : undefined}>
                    <TableHeader>
                        {table.getHeaderGroups().map((headerGroup) => (
                            <TableRow key={headerGroup.id}>
                                {headerGroup.headers.map((header) => (
                                    <TableHead
                                        key={header.id}
                                        className={
                                            fixedLayout && header.column.columnDef.size
                                                ? 'whitespace-normal'
                                                : undefined
                                        }
                                        style={
                                            fixedLayout
                                                ? {
                                                      width: header.column.columnDef.size
                                                          ? `${header.column.columnDef.size}px`
                                                          : undefined,
                                                      minWidth: header.column.columnDef.minSize
                                                          ? `${header.column.columnDef.minSize}px`
                                                          : undefined,
                                                      maxWidth: header.column.columnDef.maxSize
                                                          ? `${header.column.columnDef.maxSize}px`
                                                          : undefined,
                                                  }
                                                : undefined
                                        }
                                    >
                                        {header.isPlaceholder
                                            ? null
                                            : flexRender(
                                                  header.column.columnDef.header,
                                                  header.getContext(),
                                              )}
                                    </TableHead>
                                ))}
                            </TableRow>
                        ))}
                    </TableHeader>
                    <TableBody>
                        {table.getRowModel().rows?.length ? (
                            table.getRowModel().rows.map((row) => (
                                <TableRow
                                    key={row.id}
                                    onClick={(e) => {
                                        // Prevent navigation when clicking buttons/links
                                        const target = e.target as HTMLElement;
                                        if (
                                            target.closest('button') ||
                                            target.closest('[role="menuitem"]') ||
                                            target.closest('[role="button"]') ||
                                            target.closest('a')
                                        ) {
                                            return;
                                        }
                                        onRowClick?.(row.original);
                                    }}
                                    className={onRowClick ? 'cursor-pointer' : undefined}
                                >
                                    {row.getVisibleCells().map((cell) => (
                                        <TableCell
                                            key={cell.id}
                                            style={
                                                fixedLayout
                                                    ? {
                                                          width: cell.column.columnDef.size
                                                              ? `${cell.column.columnDef.size}px`
                                                              : undefined,
                                                      }
                                                    : undefined
                                            }
                                        >
                                            {/* Handle grouped/aggregated cells */}
                                            {cell.getIsGrouped() ? (
                                                <button
                                                    onClick={row.getToggleExpandedHandler()}
                                                    className="flex items-center gap-2 font-medium"
                                                >
                                                    {row.getIsExpanded() ? (
                                                        <ChevronDown className="h-4 w-4" />
                                                    ) : (
                                                        <ChevronRight className="h-4 w-4" />
                                                    )}
                                                    {flexRender(
                                                        cell.column.columnDef.cell,
                                                        cell.getContext(),
                                                    )}{' '}
                                                    ({row.subRows.length})
                                                </button>
                                            ) : cell.getIsAggregated() ? (
                                                flexRender(
                                                    cell.column.columnDef.aggregatedCell ??
                                                        cell.column.columnDef.cell,
                                                    cell.getContext(),
                                                )
                                            ) : cell.getIsPlaceholder() ? null : (
                                                flexRender(
                                                    cell.column.columnDef.cell,
                                                    cell.getContext(),
                                                )
                                            )}
                                        </TableCell>
                                    ))}
                                </TableRow>
                            ))
                        ) : (
                            <TableRow>
                                <TableCell
                                    colSpan={columns.length}
                                    className="h-24 text-center"
                                >
                                    No data to display
                                </TableCell>
                            </TableRow>
                        )}
                    </TableBody>
                </Table>
            </div>

            {/* Pagination */}
            <div className="flex items-center justify-end space-x-2">
                <div className="flex-1 text-sm text-muted-foreground">
                    Strona {table.getState().pagination.pageIndex + 1} z{' '}
                    {table.getPageCount()}
                </div>
                <div className="space-x-2">
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => table.previousPage()}
                        disabled={!table.getCanPreviousPage()}
                    >
                        Poprzednia
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => table.nextPage()}
                        disabled={!table.getCanNextPage()}
                    >
                        Następna
                    </Button>
                </div>
            </div>
        </div>
    );
}
