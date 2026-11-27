import { Head, Link, router } from '@inertiajs/react';
import { X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import DeliveryStatusBadge from '@/components/deliveries/delivery-status-badge';
import type { DeliveryRow } from '@/components/deliveries/delivery-types';
import Heading from '@/components/heading';
import { formatPhp } from '@/components/purchase-orders/po-format';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { index } from '@/routes/deliveries';

// The list sends what each delivery carried (active rows only) as delivered_items.
type ListRow = DeliveryRow & { delivered_items?: { id: number; quantity: string; unit: string | null; description: string | null }[] };

const MAX_SHOWN = 3;

function DeliveredCell({ delivery }: { delivery: ListRow }) {
    const rows = delivery.delivered_items ?? [];

    if (delivery.status.value === 'voided') {
        return <span className="text-muted-foreground">Voided</span>;
    }

    if (rows.length === 0) {
        return <span className="text-muted-foreground">Not entered yet</span>;
    }

    return (
        <ul className="space-y-0.5">
            {rows.slice(0, MAX_SHOWN).map((row) => (
                <li key={row.id} className="break-words">
                    <span className="font-medium tabular-nums">
                        {row.quantity}
                        {row.unit ? ` ${row.unit}` : ''}
                    </span>
                    <span className="text-muted-foreground"> · {row.description ?? '—'}</span>
                </li>
            ))}
            {rows.length > MAX_SHOWN && <li className="text-xs text-muted-foreground">+{rows.length - MAX_SHOWN} more, open the delivery</li>}
        </ul>
    );
}

type Filters = { search: string; status: string; sort: string; direction: string };

type Props = {
    deliveries: {
        data: ListRow[];
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        prev_url: string | null;
        next_url: string | null;
    };
    filters: Filters;
};

const STATUSES = [
    { value: '', label: 'All statuses' },
    { value: 'for_uploading', label: 'For uploading' },
    { value: 'complete', label: 'Documents complete' },
    { value: 'voided', label: 'Voided' },
];

const columns: ListColumn<ListRow>[] = [
    {
        key: 'iar_number',
        header: 'IAR no.',
        card: 'title',
        className: 'tabular-nums',
        cell: (d) => (
            <Link href={`/deliveries/${d.id}`} className="font-medium underline-offset-4 hover:underline">
                {d.iar_number}
            </Link>
        ),
    },
    { key: 'status', header: 'Status', card: 'badge', cell: (d) => <DeliveryStatusBadge status={d.status} /> },
    {
        key: 'po',
        header: 'PO',
        card: 'summary',
        cell: (d) => (
            <Link href={`/purchase-orders/${d.purchase_order.id}`} className="underline-offset-4 hover:underline">
                {d.purchase_order.po_number}
            </Link>
        ),
    },
    { key: 'delivered', header: 'Quantity delivered', card: 'summary', cell: (d) => <DeliveredCell delivery={d} /> },
    { key: 'supplier', header: 'Supplier', cell: (d) => d.purchase_order.supplier ?? '—' },
    { key: 'invoice_no', header: 'Invoice no.', cell: (d) => d.invoice_no },
    { key: 'date_received', header: 'Date received', className: 'tabular-nums', cell: (d) => d.date_received },
    { key: 'amount', header: 'Amount', className: 'tabular-nums', cell: (d) => formatPhp(d.amount) },
    {
        key: 'actions',
        header: 'Actions',
        cell: (d) => (
            <div className="flex flex-wrap gap-2">
                <Button asChild variant="outline" size="sm">
                    <Link href={`/deliveries/${d.id}`}>View</Link>
                </Button>
                {d.can_edit && (
                    <Button asChild variant="outline" size="sm">
                        <Link href={`/deliveries/${d.id}/edit`}>Edit</Link>
                    </Button>
                )}
            </div>
        ),
    },
];

const selectClass =
    'border-input h-9 rounded-md border bg-transparent px-3 py-1 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50';

export default function DeliveriesIndex({ deliveries, filters }: Props) {
    const [search, setSearch] = useState(filters.search);
    const [loading, setLoading] = useState(false);
    const firstRun = useRef(true);

    // Live search (about 300 ms, server-side, no Enter key). A new search goes back to page 1.
    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, ...next };
        const params: Record<string, string> = {};

        (['search', 'status', 'sort', 'direction'] as const).forEach((key) => {
            if (merged[key]) {
                params[key] = merged[key];
            }
        });

        router.get('/deliveries', params, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => setLoading(true),
            onFinish: () => setLoading(false),
        });
    };

    useEffect(() => {
        if (firstRun.current) {
            firstRun.current = false;

            return;
        }

        const timer = setTimeout(() => apply({ search: search.trim() }), 300);

        return () => clearTimeout(timer);
        // apply reads the latest filters; only a change of the typed text should start a search.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const go = (url: string | null) => {
        if (url) {
            router.get(url, {}, { preserveState: true, preserveScroll: true, onStart: () => setLoading(true), onFinish: () => setLoading(false) });
        }
    };

    return (
        <>
            <Head title="Deliveries" />

            <div className="space-y-4 p-4 md:space-y-6">
                <Heading title="Deliveries" description="One record per delivery batch (one IAR). Record a delivery from the Deliveries tab of a verified PO." />

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative w-full max-w-sm">
                        <Input
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                            placeholder="Search IAR no., invoice no., PO no. or supplier"
                            aria-label="Search deliveries"
                            className="pr-9"
                        />
                        {search !== '' && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="absolute top-0 right-0 size-9"
                                aria-label="Clear search"
                                onClick={() => {
                                    setSearch('');
                                    apply({ search: '' });
                                }}
                            >
                                <X className="size-4" />
                            </Button>
                        )}
                    </div>

                    <select className={selectClass} value={filters.status} aria-label="Filter by status" onChange={(e) => apply({ status: e.target.value })}>
                        {STATUSES.map((s) => (
                            <option key={s.value} value={s.value}>
                                {s.label}
                            </option>
                        ))}
                    </select>

                    {loading && <span className="text-sm text-muted-foreground">Loading…</span>}
                </div>

                <ResponsiveList
                    columns={columns}
                    rows={deliveries.data}
                    rowKey={(d) => d.id}
                    emptyMessage={filters.search || filters.status ? 'No deliveries match your search.' : 'No deliveries recorded yet.'}
                />

                {deliveries.total > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
                        <span>
                            {deliveries.from}–{deliveries.to} of {deliveries.total}
                        </span>
                        <div className="flex gap-2">
                            <Button variant="outline" size="sm" disabled={!deliveries.prev_url} onClick={() => go(deliveries.prev_url)}>
                                Previous
                            </Button>
                            <Button variant="outline" size="sm" disabled={!deliveries.next_url} onClick={() => go(deliveries.next_url)}>
                                Next
                            </Button>
                        </div>
                    </div>
                )}
            </div>
        </>
    );
}

DeliveriesIndex.layout = {
    breadcrumbs: [{ title: 'Deliveries', href: index() }],
};