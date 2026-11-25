import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { lineColumns } from '@/components/deliveries/delivery-items-panel';
import DeliveryLineHistory from '@/components/deliveries/delivery-line-history';
import DeliveryStatusBadge from '@/components/deliveries/delivery-status-badge';
import type { DeliveryRow, PoDeliveriesData } from '@/components/deliveries/delivery-types';
import { formatPhp } from '@/components/purchase-orders/po-format';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Button } from '@/components/ui/button';

const columns: ListColumn<DeliveryRow>[] = [
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
    { key: 'invoice_no', header: 'Invoice no.', card: 'summary', cell: (d) => d.invoice_no },
    { key: 'date_received', header: 'Date received', className: 'tabular-nums', cell: (d) => d.date_received },
    { key: 'amount', header: 'Amount', className: 'tabular-nums', cell: (d) => formatPhp(d.amount) },
    { key: 'fund_cluster', header: 'Fund cluster', cell: (d) => d.fund_cluster },
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

// The Deliveries tab of the PO page (step 5.2). The server decides whether "Record delivery" is drawn
// (delivery.encode, PO verified and not closed); hiding the button is for convenience only.
// Step 6.1 adds the delivered items: the PO's progress and, per PO line, the running totals and the full history
// (every delivery, voided rows with their reason). Read-only here; the update is made on the delivery page.
export default function PoDeliveriesPanel({ poId, deliveries }: { poId: number; deliveries: PoDeliveriesData }) {
    return (
        <section className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-base font-medium">Deliveries</h3>
                {deliveries.can_record && (
                    <Button asChild size="sm">
                        <Link href={`/deliveries/create?purchase_order=${poId}`}>
                            <Plus className="size-4" /> Record delivery
                        </Link>
                    </Button>
                )}
            </div>

            {deliveries.blocked_reason && <p className="text-sm text-muted-foreground">{deliveries.blocked_reason}</p>}

            <ResponsiveList columns={columns} rows={deliveries.rows} rowKey={(d) => d.id} emptyMessage="No deliveries recorded for this PO yet." />

            {deliveries.progress && (
                <div className="space-y-3 pt-2">
                    <div>
                        <h3 className="text-base font-medium">Delivered items</h3>
                        <p className="text-sm text-muted-foreground">
                            PO progress: <span className="font-medium text-foreground">{deliveries.progress.label}</span>. Totals count every delivery of this PO. To update them, open a delivery.
                        </p>
                    </div>

                    <ResponsiveList columns={lineColumns} rows={deliveries.lines} rowKey={(l) => l.po_item_id} emptyMessage="This PO has no lines." />

                    {deliveries.lines
                        .filter((line) => line.rows.length > 0)
                        .map((line) => (
                            <details key={line.po_item_id} className="rounded-md border px-3 py-2">
                                <summary className="cursor-pointer text-sm font-medium break-words">
                                    {line.description} ({line.rows.length})
                                </summary>
                                <div className="mt-2">
                                    <DeliveryLineHistory rows={line.rows} />
                                </div>
                            </details>
                        ))}
                </div>
            )}
        </section>
    );
}