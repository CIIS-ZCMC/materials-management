import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, FileText, History, Truck } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { ComingSoon } from '@/components/coming-soon';
import Heading from '@/components/heading';
import OverdueBadgeSlot from '@/components/purchase-orders/overdue-badge-slot';
import { formatPhp, orDash } from '@/components/purchase-orders/po-format';
import PoStatusBadge from '@/components/purchase-orders/po-status-badge';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type ItemRow = {
    id: number;
    description: string;
    category: string;
    unit: string;
    unit_price: string;
    quantity_ordered: number;
    line_total: string;
    in_catalog: boolean;
};

type ExtensionRow = {
    id: number;
    extra_days: number;
    reason: string;
    old_due_date: string;
    new_due_date: string;
    approved_by: string | null;
    approved_at: string;
};

type PurchaseOrder = {
    id: number;
    po_number: string;
    supplier: string;
    source: string;
    external_id: string | null;
    po_date: string | null;
    emailed_date: string | null;
    delivery_term_days: number | null;
    due_date: string | null;
    status: { value: string; label: string };
    closed_at: string | null;
    items: ItemRow[];
    total: string;
    schedules: string[];
    extensions: ExtensionRow[];
};

type Props = { purchaseOrder: PurchaseOrder };

const TABS = [
    { key: 'details', label: 'Details' },
    { key: 'items', label: 'Items' },
    { key: 'schedule', label: 'Schedule' },
    { key: 'deliveries', label: 'Deliveries' },
    { key: 'documents', label: 'Documents' },
    { key: 'history', label: 'History' },
] as const;

type TabKey = (typeof TABS)[number]['key'];

function Field({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div>
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 min-w-0 break-words font-medium">{children}</dd>
        </div>
    );
}

const itemColumns: ListColumn<ItemRow>[] = [
    { key: 'description', header: 'Description', card: 'title', cell: (i) => i.description },
    {
        key: 'catalog',
        header: 'Catalog',
        card: 'summary',
        cell: (i) => (i.in_catalog ? 'In catalog' : <Badge variant="outline">Not in catalog</Badge>),
        cardCell: (i) => (i.in_catalog ? null : <Badge variant="outline">Not in catalog</Badge>),
    },
    { key: 'category', header: 'Category', cell: (i) => i.category },
    { key: 'unit', header: 'Unit', cell: (i) => i.unit },
    { key: 'quantity_ordered', header: 'Qty ordered', className: 'tabular-nums', cell: (i) => i.quantity_ordered },
    { key: 'unit_price', header: 'Unit price', className: 'tabular-nums', cell: (i) => formatPhp(i.unit_price) },
    { key: 'line_total', header: 'Line total', card: 'badge', className: 'font-medium tabular-nums', cell: (i) => formatPhp(i.line_total) },
];

const extensionColumns: ListColumn<ExtensionRow>[] = [
    { key: 'extra_days', header: 'Days added', card: 'title', cell: (e) => `+${e.extra_days} ${e.extra_days === 1 ? 'day' : 'days'}` },
    { key: 'reason', header: 'Reason', card: 'summary', cell: (e) => e.reason },
    { key: 'old_due_date', header: 'Old due date', className: 'tabular-nums', cell: (e) => e.old_due_date },
    { key: 'new_due_date', header: 'New due date', className: 'tabular-nums', cell: (e) => e.new_due_date },
    { key: 'approved_by', header: 'Approved by', cell: (e) => orDash(e.approved_by) },
    { key: 'approved_at', header: 'Approved on', cell: (e) => e.approved_at },
];

export default function PurchaseOrderShow({ purchaseOrder: po }: Props) {
    // React state: which tab is open. Changing it re-draws the page without reloading it.
    const [tab, setTab] = useState<TabKey>('details');

    return (
        <>
            <Head title={`PO ${po.po_number}`} />

            <div className="space-y-4 p-4 md:space-y-6">
                <Button asChild variant="outline" size="sm">
                    <Link href="/purchase-orders">
                        <ArrowLeft className="size-4" /> All purchase orders
                    </Link>
                </Button>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading title={po.po_number} description={po.supplier} />
                    <PoStatusBadge status={po.status} />
                </div>

                {/* Tabs: plain buttons, they wrap on a phone instead of scrolling sideways */}
                <div role="tablist" className="flex flex-wrap gap-1 border-b pb-2">
                    {TABS.map((t) => (
                        <Button
                            key={t.key}
                            role="tab"
                            aria-selected={tab === t.key}
                            size="sm"
                            variant={tab === t.key ? 'default' : 'ghost'}
                            onClick={() => setTab(t.key)}
                        >
                            {t.label}
                        </Button>
                    ))}
                </div>

                {tab === 'details' && (
                    <dl className="grid gap-x-8 gap-y-4 sm:grid-cols-2">
                        <Field label="PO number">{po.po_number}</Field>
                        <Field label="Supplier">{po.supplier}</Field>
                        <Field label="Source">{po.source}</Field>
                        <Field label="External ID">{orDash(po.external_id)}</Field>
                        <Field label="PO date">{orDash(po.po_date)}</Field>
                        <Field label="Emailed date">{orDash(po.emailed_date)}</Field>
                        <Field label="Delivery term">{po.delivery_term_days ? `${po.delivery_term_days} days` : '—'}</Field>
                        <Field label="Due date">
                            {orDash(po.due_date)}
                            {/* The overdue badge goes here in step 8.3 */}
                            <OverdueBadgeSlot />
                        </Field>
                        <Field label="Status">
                            <PoStatusBadge status={po.status} />
                        </Field>
                        {po.closed_at && <Field label="Closed on">{po.closed_at}</Field>}
                    </dl>
                )}

                {tab === 'items' && (
                    <div className="space-y-3">
                        <ResponsiveList columns={itemColumns} rows={po.items} rowKey={(i) => i.id} emptyMessage="This PO has no items." />
                        <div className="flex items-center justify-end gap-4 rounded-md border bg-muted/30 px-3 py-3">
                            <span className="text-sm text-muted-foreground">
                                PO total ({po.items.length} {po.items.length === 1 ? 'line' : 'lines'})
                            </span>
                            <span className="text-lg font-semibold tabular-nums">{formatPhp(po.total)}</span>
                        </div>
                    </div>
                )}

                {tab === 'schedule' && (
                    <div className="space-y-6">
                        <section className="space-y-2">
                            <h3 className="text-base font-medium">Scheduled delivery dates</h3>
                            {po.schedules.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No scheduled dates yet.</p>
                            ) : (
                                <ul className="flex flex-wrap gap-2">
                                    {po.schedules.map((date) => (
                                        <li key={date} className="rounded-md border px-3 py-1.5 text-sm tabular-nums">
                                            {date}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </section>

                        <section className="space-y-2">
                            <h3 className="text-base font-medium">Extension history</h3>
                            <ResponsiveList
                                columns={extensionColumns}
                                rows={po.extensions}
                                rowKey={(e) => e.id}
                                emptyMessage="No extensions approved for this PO."
                            />
                        </section>
                    </div>
                )}

                {tab === 'deliveries' && (
                    <ComingSoon title="Deliveries" description="Deliveries, IARs and delivered quantities for this PO will appear here." icon={Truck} step="5.2" />
                )}

                {tab === 'documents' && (
                    <ComingSoon title="Documents" description="The PO Bundle and the consolidated PDF will appear here." icon={FileText} step="4.2" />
                )}

                {tab === 'history' && (
                    <ComingSoon title="History" description="Status changes and audit entries for this PO will appear here." icon={History} step="3.6" />
                )}
            </div>
        </>
    );
}

PurchaseOrderShow.layout = {
    breadcrumbs: [
        { title: 'Purchase Orders', href: '/purchase-orders' },
        { title: 'Purchase order', href: '#' },
    ],
};