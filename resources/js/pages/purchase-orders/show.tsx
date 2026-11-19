import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Pencil, Truck } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { ComingSoon } from '@/components/coming-soon';
import Heading from '@/components/heading';
import OverdueBadgeSlot from '@/components/purchase-orders/overdue-badge-slot';
import PoDocumentsPanel from '@/components/purchase-orders/po-documents-panel';
import type { PoDocumentsData } from '@/components/purchase-orders/po-documents-panel';
import { formatPhp, orDash } from '@/components/purchase-orders/po-format';
import PoHistoryList from '@/components/purchase-orders/po-history-list';
import type { HistoryEntry } from '@/components/purchase-orders/po-history-list';
import PoReviewActions from '@/components/purchase-orders/po-review-actions';
import PoStatusBadge from '@/components/purchase-orders/po-status-badge';
import RecordExtensionDialog from '@/components/purchase-orders/record-extension-dialog';
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
    stock_no: string | null;
    brand: string | null;
    manufacturer: string | null;
    country_of_origin: string | null;
    specifications: string | null;
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
    supplier_details: {
        address: string | null;
        tin: string | null;
        contact_no: string | null;
        supply_chain_role: string | null;
    };
    source: string;
    external_id: string | null;
    po_date: string | null;
    emailed_date: string | null;
    delivery_term_days: number | null;
    due_date: string | null;
    pr_no: string | null;
    caf_no: string | null;
    mode_of_procurement: string | null;
    remarks: string | null;
    status: { value: string; label: string };
    closed_at: string | null;
    items: ItemRow[];
    total: string;
    schedules: string[];
    extensions: ExtensionRow[];
    edit: { allowed: boolean; reason: string | null };
    extension: { allowed: boolean; reason: string | null; max_days: number; counting: string };
    review: { can_verify: boolean; can_escalate: boolean; can_send_back: boolean; reason: string | null; send_back_reason: string | null };
    escalation: { reason: string | null; by: string | null; at: string } | null;
    history: HistoryEntry[];
    documents: PoDocumentsData;
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

// The printed details of a line (brand, manufacturer, country, specifications), as small grey lines
// under the description. Shows nothing when the line has none of them.
function LineDetails({ item }: { item: ItemRow }) {
    const parts = [
        item.brand ? `Brand: ${item.brand}` : null,
        item.manufacturer ? `Manufacturer: ${item.manufacturer}` : null,
        item.country_of_origin ? `Country of origin: ${item.country_of_origin}` : null,
        item.specifications ? `Specifications: ${item.specifications}` : null,
    ].filter((part): part is string => part !== null);

    return (
        <>
            {parts.map((part) => (
                <span key={part} className="block text-xs font-normal text-muted-foreground">
                    {part}
                </span>
            ))}
        </>
    );
}

const itemColumns: ListColumn<ItemRow>[] = [
    {
        key: 'description',
        header: 'Description',
        card: 'title',
        cell: (i) => (
            <>
                <span className="block">{i.description}</span>
                <LineDetails item={i} />
            </>
        ),
    },
    {
        key: 'catalog',
        header: 'Catalog',
        card: 'summary',
        cell: (i) => (i.in_catalog ? 'In catalog' : <Badge variant="outline">Not in catalog</Badge>),
        cardCell: (i) => (i.in_catalog ? null : <Badge variant="outline">Not in catalog</Badge>),
    },
    { key: 'stock_no', header: 'Stock no.', cell: (i) => orDash(i.stock_no) },
    { key: 'category', header: 'Category', cell: (i) => i.category },
    { key: 'unit', header: 'Unit', cell: (i) => i.unit },
    { key: 'quantity_ordered', header: 'Qty ordered', className: 'tabular-nums', cell: (i) => i.quantity_ordered },
    { key: 'unit_price', header: 'Unit price', className: 'tabular-nums', cell: (i) => formatPhp(i.unit_price) },
    { key: 'line_total', header: 'Line total', card: 'badge', className: 'font-medium tabular-nums', cell: (i) => formatPhp(i.line_total) },
];

// An extension is recorded and approved by the same person in one step, so one column covers both.
const extensionColumns: ListColumn<ExtensionRow>[] = [
    { key: 'extra_days', header: 'Days added', card: 'title', cell: (e) => `+${e.extra_days} ${e.extra_days === 1 ? 'day' : 'days'}` },
    { key: 'reason', header: 'Reason', card: 'summary', cell: (e) => e.reason },
    { key: 'old_due_date', header: 'Old due date', className: 'tabular-nums', cell: (e) => e.old_due_date },
    { key: 'new_due_date', header: 'New due date', className: 'tabular-nums', cell: (e) => e.new_due_date },
    { key: 'approved_by', header: 'Recorded by', cell: (e) => orDash(e.approved_by) },
    { key: 'approved_at', header: 'Recorded on', cell: (e) => e.approved_at },
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
                    <div className="flex flex-wrap items-center gap-2">
                        {/* Verify / Escalate (PO Reviewer) and Send back (Admin). The server decides which are drawn. */}
                        <PoReviewActions poId={po.id} poNumber={po.po_number} dueDate={po.due_date} review={po.review} />
                        {po.edit.allowed && (
                            <Button asChild size="sm">
                                <Link href={`/purchase-orders/${po.id}/edit`}>
                                    <Pencil className="size-4" /> Edit
                                </Link>
                            </Button>
                        )}
                        <PoStatusBadge status={po.status} />
                    </div>
                </div>

                {/* The Admin sees why the PO was escalated, right where the fix starts. */}
                {po.escalation && (
                    <div className="rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm">
                        <p className="font-medium">Escalated to the Admin</p>
                        <p className="mt-0.5 break-words">{po.escalation.reason ?? 'No remarks were recorded.'}</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            {po.escalation.by ?? 'Unknown user'} · {po.escalation.at}
                        </p>
                    </div>
                )}

                {po.edit.reason && <p className="text-sm text-muted-foreground">{po.edit.reason}</p>}
                {po.review.reason && <p className="text-sm text-muted-foreground">{po.review.reason}</p>}
                {po.review.send_back_reason && <p className="text-sm text-muted-foreground">{po.review.send_back_reason}</p>}

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
                        <Field label="Supplier address">{orDash(po.supplier_details.address)}</Field>
                        <Field label="Supplier TIN">{orDash(po.supplier_details.tin)}</Field>
                        <Field label="Supplier contact no.">{orDash(po.supplier_details.contact_no)}</Field>
                        <Field label="Supply chain role">{orDash(po.supplier_details.supply_chain_role)}</Field>
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
                        <Field label="P.R. no.">{orDash(po.pr_no)}</Field>
                        <Field label="CAF no.">{orDash(po.caf_no)}</Field>
                        <Field label="Mode of procurement">{orDash(po.mode_of_procurement)}</Field>
                        <Field label="Free-text line">{orDash(po.remarks)}</Field>
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
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <h3 className="text-base font-medium">Extension history</h3>
                                {/* Only people with extension.manage get the button, and only while the PO may be extended. */}
                                {po.extension.allowed && (
                                    <RecordExtensionDialog
                                        poId={po.id}
                                        poNumber={po.po_number}
                                        dueDate={po.due_date}
                                        counting={po.extension.counting}
                                        maxDays={po.extension.max_days}
                                    />
                                )}
                            </div>
                            {po.extension.reason && <p className="text-sm text-muted-foreground">{po.extension.reason}</p>}
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

                {/* Step 4.2: the document list, and the PO Bundle upload for people with po.upload-files. */}
                {tab === 'documents' && <PoDocumentsPanel poId={po.id} isDraft={po.status.value === 'draft'} documents={po.documents} />}

                {tab === 'history' && <PoHistoryList entries={po.history} />}
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