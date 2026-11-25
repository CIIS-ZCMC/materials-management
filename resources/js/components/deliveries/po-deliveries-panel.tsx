import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { lineColumns } from '@/components/deliveries/delivery-items-panel';
import DeliveryStatusBadge from '@/components/deliveries/delivery-status-badge';
import type { DeliveryRow, PoDeliveriesData } from '@/components/deliveries/delivery-types';
import { formatPhp, orDash } from '@/components/purchase-orders/po-format';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
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
// Step 6.1 adds the delivered items: the PO's progress and, per PO line, the totals. Step 6.1b: the history is grouped by delivery
// (one IAR each, with its status), so 500 / 300 / 200 read as three IARs, each row with the line's running total after it.
// Voided rows keep their reason. Read-only here; the update is made on the delivery page.
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

                    <div className="space-y-2">
                        <h4 className="text-sm font-medium">History by delivery (one IAR each)</h4>
                        {deliveries.groups.length === 0 ? (
                            <p className="text-sm text-muted-foreground">No deliveries recorded for this PO yet.</p>
                        ) : (
                            deliveries.groups.map((group) => (
                                <div key={group.delivery_id} className="rounded-md border">
                                    <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b px-3 py-2 text-sm">
                                        <Link href={`/deliveries/${group.delivery_id}`} className="font-medium underline-offset-4 hover:underline">
                                            IAR {group.iar_number}
                                        </Link>
                                        <DeliveryStatusBadge status={group.status} />
                                        <span className="text-muted-foreground tabular-nums">Received {group.date_received}</span>
                                    </div>

                                    {group.rows.length === 0 ? (
                                        <p className="px-3 py-2 text-sm text-muted-foreground">No quantities entered for this IAR yet.</p>
                                    ) : (
                                        <ul className="divide-y text-sm">
                                            {group.rows.map((row) => {
                                                const voided = row.status.value === 'void';

                                                return (
                                                    <li key={row.id} className="space-y-1 px-3 py-2">
                                                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                                                            <span className="font-medium break-words">{row.description ?? '—'}</span>
                                                            <span className={`tabular-nums ${voided ? 'text-muted-foreground line-through' : 'font-medium'}`}>
                                                                {row.quantity}
                                                                {row.unit ? ` ${row.unit}` : ''}
                                                            </span>
                                                            {voided && <Badge variant="destructive">Voided</Badge>}
                                                            {row.over_delivery && (
                                                                <Badge variant="outline" className="border-amber-500/60 text-amber-700 dark:text-amber-400">
                                                                    Over-delivery
                                                                </Badge>
                                                            )}
                                                            {row.running_total !== null && (
                                                                <span className="text-muted-foreground">
                                                                    Total after this IAR: <span className="font-medium text-foreground tabular-nums">{row.running_total}</span>
                                                                </span>
                                                            )}
                                                        </div>
                                                        <p className="text-xs text-muted-foreground">
                                                            Entered by {orDash(row.recorded_by)} · {orDash(row.recorded_at)}
                                                        </p>
                                                        {row.over_delivery && row.over_delivery_reason && <p className="text-xs break-words">Over-delivery reason: {row.over_delivery_reason}</p>}
                                                        {voided && (
                                                            <p className="text-xs break-words">
                                                                Voided: {row.void_reason ?? 'No reason was recorded.'} · {orDash(row.voided_by)} · {orDash(row.voided_at)}
                                                            </p>
                                                        )}
                                                    </li>
                                                );
                                            })}
                                        </ul>
                                    )}
                                </div>
                            ))
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}
