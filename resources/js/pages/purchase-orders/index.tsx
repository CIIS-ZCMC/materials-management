import { Head, Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import Heading from '@/components/heading';
import LiveSearchInput from '@/components/live-search-input';
import PaginationBar from '@/components/pagination-bar';
import type { PaginationMeta } from '@/components/pagination-bar';
import OverdueBadgeSlot from '@/components/purchase-orders/overdue-badge-slot';
import PoStatusBadge from '@/components/purchase-orders/po-status-badge';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Button } from '@/components/ui/button';
import { useLiveSearch } from '@/hooks/use-live-search';
import { useAccess } from '@/hooks/use-access';

type PoRow = {
    id: number;
    po_number: string;
    supplier: string;
    status: { value: string; label: string };
    due_date: string | null;
    po_date: string | null;
    items_count: number;
};

type Paginated = PaginationMeta & { data: PoRow[] };

type Filters = {
    search: string;
    status: string;
    supplier: string;
    from: string;
    to: string;
    view: 'active' | 'closed';
    sort: string;
    direction: 'asc' | 'desc';
};

type Props = {
    purchaseOrders: Paginated;
    filters: Filters;
    statusOptions: { value: string; label: string }[];
    supplierOptions: { id: number; name: string; is_active: boolean }[];
};

const DEFAULT_SORT = 'due_date';
const DEFAULT_DIRECTION = 'asc';

// Same keys as the columns below; used by the "Sort by" box on phones (no table header there).
const SORT_CHOICES = [
    { key: 'po_number', label: 'PO number' },
    { key: 'supplier', label: 'Supplier' },
    { key: 'status', label: 'Status' },
    { key: 'due_date', label: 'Due date' },
    { key: 'po_date', label: 'PO date' },
    { key: 'items', label: 'Items' },
];

const control =
    'h-9 w-full rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring';

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <label className="grid gap-1 text-sm">
            <span className="text-muted-foreground">{label}</span>
            {children}
        </label>
    );
}

export default function PurchaseOrdersIndex({ purchaseOrders, filters, statusOptions, supplierOptions }: Props) {
    const { can } = useAccess();
    const isDefaultSort = filters.sort === DEFAULT_SORT && filters.direction === DEFAULT_DIRECTION;

    // The search box plus every other filter. `params` are the values the server applied;
    // the hook sends them along with each search so typing never loses a filter.
    const search = useLiveSearch({
        url: '/purchase-orders',
        initial: filters.search ?? '',
        only: ['purchaseOrders', 'filters'],
        params: {
            status: filters.status,
            supplier: filters.supplier,
            from: filters.from,
            to: filters.to,
            view: filters.view === 'closed' ? 'closed' : '',
            sort: isDefaultSort ? '' : filters.sort,
            direction: isDefaultSort ? '' : filters.direction,
        },
    });

    const hasFilters = filters.status !== '' || filters.supplier !== '' || filters.from !== '' || filters.to !== '';

    // Back to the default sort leaves the URL clean (no sort parameters).
    const setSort = (key: string, direction: 'asc' | 'desc') => {
        if (key === DEFAULT_SORT && direction === DEFAULT_DIRECTION) {
            search.apply({ sort: '', direction: '' });
        } else {
            search.apply({ sort: key, direction });
        }
    };

    // Click on a header: same column flips the direction, another column starts ascending.
    const clickSort = (key: string) => setSort(key, filters.sort === key && filters.direction === 'asc' ? 'desc' : 'asc');

    const columns: ListColumn<PoRow>[] = [
        {
            key: 'po_number',
            header: 'PO number',
            sortable: true,
            card: 'title',
            className: 'font-mono',
            cell: (p) => (
                <Link href={`/purchase-orders/${p.id}`} className="font-medium text-primary hover:underline">
                    {p.po_number}
                </Link>
            ),
            cardCell: (p) => p.po_number,
        },
        { key: 'supplier', header: 'Supplier', sortable: true, card: 'summary', cell: (p) => p.supplier },
        { key: 'status', header: 'Status', sortable: true, card: 'badge', cell: (p) => <PoStatusBadge status={p.status} /> },
        {
            key: 'due_date',
            header: 'Due date',
            sortable: true,
            card: 'summary',
            className: 'tabular-nums',
            cell: (p) => (
                <>
                    {p.due_date ?? '—'}
                    {/* The overdue badge goes here in step 8.3 */}
                    <OverdueBadgeSlot />
                </>
            ),
            cardCell: (p) => (
                <span>
                    {p.due_date ? `Due ${p.due_date}` : 'No due date'}
                    <OverdueBadgeSlot />
                </span>
            ),
        },
        { key: 'po_date', header: 'PO date', sortable: true, className: 'tabular-nums', cell: (p) => p.po_date ?? '—' },
        { key: 'items', header: 'Items', sortable: true, className: 'tabular-nums', cell: (p) => p.items_count },
    ];

    const renderActions = (po: PoRow) => (
        <Button asChild size="sm" variant="outline">
            <Link href={`/purchase-orders/${po.id}`}>View</Link>
        </Button>
    );

    return (
        <>
            <Head title="Purchase Orders" />

            <div className="space-y-4 p-4 md:space-y-6">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading title="Purchase Orders" description="All purchase orders and where they stand" />

                    <div className="flex flex-wrap items-center gap-2">
                        {can('po.create') && (
                            <Button asChild size="sm">
                                <Link href="/purchase-orders/create">
                                    <Plus className="size-4" /> New PO
                                </Link>
                            </Button>
                        )}

                        {/* Active / Closed toggle */}
                        <div className="inline-flex rounded-md border p-0.5" role="group" aria-label="Show">
                            {(['active', 'closed'] as const).map((v) => (
                                <Button
                                    key={v}
                                    size="sm"
                                    variant={filters.view === v ? 'default' : 'ghost'}
                                    aria-pressed={filters.view === v}
                                    onClick={() => search.apply({ view: v === 'closed' ? 'closed' : '' })}
                                >
                                    {v === 'active' ? 'Active' : 'Closed'}
                                </Button>
                            ))}
                        </div>
                    </div>
                </div>

                <LiveSearchInput
                    value={search.value}
                    onChange={search.setValue}
                    onClear={search.clear}
                    onSubmit={search.flush}
                    loading={search.loading}
                    placeholder="Search PO number or supplier"
                />

                {/* Filters */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <Field label="Status">
                        <select className={control} value={filters.status} onChange={(e) => search.apply({ status: e.target.value })}>
                            <option value="">All statuses</option>
                            {statusOptions.map((s) => (
                                <option key={s.value} value={s.value}>
                                    {s.label}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Supplier">
                        <select className={control} value={filters.supplier} onChange={(e) => search.apply({ supplier: e.target.value })}>
                            <option value="">All suppliers</option>
                            {supplierOptions.map((s) => (
                                <option key={s.id} value={String(s.id)}>
                                    {s.name}
                                    {s.is_active ? '' : ' (inactive)'}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="PO date from">
                        <input type="date" className={control} value={filters.from} onChange={(e) => search.apply({ from: e.target.value })} />
                    </Field>
                    <Field label="PO date to">
                        <input type="date" className={control} value={filters.to} onChange={(e) => search.apply({ to: e.target.value })} />
                    </Field>
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    {/* Phones have no table header, so sorting is a box + a direction button */}
                    <div className="flex items-end gap-2 md:hidden">
                        <Field label="Sort by">
                            <select className={control} value={filters.sort} onChange={(e) => setSort(e.target.value, filters.direction)}>
                                {SORT_CHOICES.map((c) => (
                                    <option key={c.key} value={c.key}>
                                        {c.label}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Button
                            type="button"
                            variant="outline"
                            size="icon"
                            aria-label={filters.direction === 'asc' ? 'Ascending, tap for descending' : 'Descending, tap for ascending'}
                            onClick={() => setSort(filters.sort, filters.direction === 'asc' ? 'desc' : 'asc')}
                        >
                            {filters.direction === 'asc' ? <ArrowUp className="size-4" /> : <ArrowDown className="size-4" />}
                        </Button>
                    </div>

                    {hasFilters && (
                        <Button type="button" variant="ghost" size="sm" onClick={() => search.apply({ status: '', supplier: '', from: '', to: '' })}>
                            Clear filters
                        </Button>
                    )}
                </div>

                <ResponsiveList
                    columns={columns}
                    rows={purchaseOrders.data}
                    rowKey={(p) => p.id}
                    renderActions={renderActions}
                    loading={search.loading}
                    emptyMessage={filters.view === 'closed' ? 'No closed purchase orders found.' : 'No purchase orders found.'}
                    sort={{ key: filters.sort, direction: filters.direction }}
                    onSort={clickSort}
                />

                <PaginationBar meta={purchaseOrders} onNavigate={search.goTo} noun="purchase orders" />
            </div>
        </>
    );
}

PurchaseOrdersIndex.layout = {
    breadcrumbs: [{ title: 'Purchase Orders', href: '/purchase-orders' }],
};