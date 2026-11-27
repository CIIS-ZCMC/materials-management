import { Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { lineColumns } from '@/components/deliveries/delivery-items-panel';
import DeliveryStatusBadge from '@/components/deliveries/delivery-status-badge';
import { FULLY_DELIVERED_NOTE } from '@/components/deliveries/delivery-types';
import type { DeliveryGroup, DeliveryGroupRow, DeliveryRow, PoDeliveriesData } from '@/components/deliveries/delivery-types';
import { formatPhp, orDash } from '@/components/purchase-orders/po-format';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Notice, SectionCard, Stat } from '@/components/section-card';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

const MAX_SHOWN = 3;

// Step 6.1c: what each delivery (IAR) carried. Only ACTIVE rows count and a voided delivery carries nothing, the same as the totals.
// Taken from the history groups the server already sends, so nothing is added up in the browser.
function carriedByDelivery(groups: DeliveryGroup[]): Map<number, DeliveryGroupRow[]> {
    const carried = new Map<number, DeliveryGroupRow[]>();

    groups.forEach((group) => {
        carried.set(group.delivery_id, group.status.value === 'voided' ? [] : group.rows.filter((row) => row.status.value === 'active'));
    });

    return carried;
}

function DeliveredCell({ delivery, rows, withLine }: { delivery: DeliveryRow; rows: DeliveryGroupRow[]; withLine: boolean }) {
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
                    {withLine && <span className="text-muted-foreground"> · {row.description ?? '—'}</span>}
                </li>
            ))}
            {rows.length > MAX_SHOWN && <li className="text-xs text-muted-foreground">+{rows.length - MAX_SHOWN} more, see the history below</li>}
        </ul>
    );
}

function buildColumns(carried: Map<number, DeliveryGroupRow[]>, withLine: boolean): ListColumn<DeliveryRow>[] {
    return [
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
            key: 'delivered',
            header: 'Quantity delivered',
            card: 'summary',
            cell: (d) => <DeliveredCell delivery={d} rows={carried.get(d.id) ?? []} withLine={withLine} />,
        },
        { key: 'invoice_no', header: 'Invoice no.', cell: (d) => d.invoice_no },
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
}

// The Deliveries tab of the PO page (step 5.2). The server decides whether "Record delivery" is drawn; hiding the button is for convenience only.
// Step 6.1 / 6.1b / 6.1c / 6.1d / 6.1e: see the notes in the earlier version; the data and rules are unchanged.
// Layout: three separate cards, each with its own header: 1) the deliveries (with the amount check), 2) the delivered items per PO line,
// 3) the history, one block per delivery (one IAR each).
export default function PoDeliveriesPanel({ poId, deliveries }: { poId: number; deliveries: PoDeliveriesData }) {
    const columns = buildColumns(carriedByDelivery(deliveries.groups), deliveries.lines.length > 1);
    const amounts = deliveries.amounts;

    return (
        <div className="space-y-4 md:space-y-6">
            {/* 1. The deliveries */}
            <SectionCard
                title="Deliveries"
                description="One record per delivery batch (one IAR)."
                actions={
                    deliveries.can_record && (
                        <Button asChild size="sm" variant={deliveries.fully_delivered ? 'outline' : 'default'}>
                            <Link href={`/deliveries/create?purchase_order=${poId}`}>
                                <Plus className="size-4" /> Record delivery
                            </Link>
                        </Button>
                    )
                }
            >
                {deliveries.blocked_reason && <p className="text-sm text-muted-foreground">{deliveries.blocked_reason}</p>}

                {deliveries.can_record && deliveries.fully_delivered && <Notice tone="warning">{FULLY_DELIVERED_NOTE}</Notice>}

                {amounts && amounts.counted > 0 && (
                    <div className="space-y-2">
                        <div className="grid gap-3 sm:grid-cols-3">
                            <Stat label="Total of all deliveries">{formatPhp(amounts.deliveries_total)}</Stat>
                            <Stat label="PO total">{formatPhp(amounts.po_total)}</Stat>
                            <Stat label="Deliveries counted">
                                {amounts.counted} <span className="text-xs font-normal text-muted-foreground">(voided ones not counted)</span>
                            </Stat>
                        </div>
                        {amounts.over_by && (
                            <Notice tone="warning">
                                The deliveries add up to <span className="font-medium tabular-nums">{formatPhp(amounts.over_by)}</span> more than the PO total. Check the delivery amounts.
                                This is only a check; nothing is blocked.
                            </Notice>
                        )}
                    </div>
                )}

                <ResponsiveList columns={columns} rows={deliveries.rows} rowKey={(d) => d.id} emptyMessage="No deliveries recorded for this PO yet." />
            </SectionCard>

            {deliveries.progress && (
                <>
                    {/* 2. Delivered items per PO line */}
                    <SectionCard
                        title="Delivered items"
                        description="Totals count every delivery of this PO. To update them, open a delivery."
                        actions={<Badge variant="secondary">{deliveries.progress.label}</Badge>}
                    >
                        <ResponsiveList columns={lineColumns} rows={deliveries.lines} rowKey={(l) => l.po_item_id} emptyMessage="This PO has no lines." />
                    </SectionCard>

                    {/* 3. History by delivery */}
                    <SectionCard title="History by delivery" description="One IAR each, with the line's running total after every row.">
                        {deliveries.groups.length === 0 ? (
                            <p className="rounded-lg border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">No deliveries recorded for this PO yet.</p>
                        ) : (
                            <div className="space-y-3">
                                {deliveries.groups.map((group) => (
                                    <div key={group.delivery_id} className="overflow-hidden rounded-lg border bg-background">
                                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b bg-muted/30 px-3 py-2 text-sm">
                                            <Link href={`/deliveries/${group.delivery_id}`} className="font-semibold underline-offset-4 hover:underline">
                                                IAR {group.iar_number}
                                            </Link>
                                            <DeliveryStatusBadge status={group.status} />
                                            <span className="text-muted-foreground tabular-nums">Received {group.date_received}</span>
                                        </div>

                                        {group.rows.length === 0 ? (
                                            <p className="px-3 py-3 text-sm text-muted-foreground">No quantities entered for this IAR yet.</p>
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
                                ))}
                            </div>
                        )}
                    </SectionCard>
                </>
            )}
        </div>
    );
}