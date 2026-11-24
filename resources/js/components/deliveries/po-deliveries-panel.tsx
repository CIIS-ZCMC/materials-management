import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
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
        </section>
    );
}