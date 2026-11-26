import { Link, router } from '@inertiajs/react';
import { Plus, Truck } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import DeliveryLineHistory from '@/components/deliveries/delivery-line-history';
import { FULLY_DELIVERED_NOTE } from '@/components/deliveries/delivery-types';
import type { DeliveryItemEntry, DeliveryItemsPanelData, DeliveryLine } from '@/components/deliveries/delivery-types';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

// Step 6.1: the delivered items of ONE delivery. The table shows every PO line with its running totals (all
// deliveries together); the history under it shows the rows of THIS delivery. The server decides can_update and
// can_void and checks every action again (DeliveryPolicy + DeliveryItemRule); this panel only draws what it is told.
// React state: "voiding" is the row whose void form is open, "reason" what is typed in it, "busy" blocks the buttons
// while a request runs and "actionError" is the server's message.
// Step 6.1b: one IAR = one delivery. The heading carries the IAR number; more items arrive with a NEW delivery (a new IAR), recorded from
// the PO page by the Encoder. The link shows only when the server says so (delivery.encode and the PO still accepts a delivery).
// Step 6.1c (front-end only): "Record another delivery" is a real button next to "Update delivered items". When EVERY PO line already has a
// quantity on this IAR there is nothing left to save here (the server would refuse it), so the Update button gives way to a note that says
// what to do instead. A delivery with some lines still open keeps the button: "Partially delivered" is about the whole PO, not this IAR.
// Step 6.1e: when the PO is fully delivered "Record another delivery" is an OUTLINE button with a note (a soft warning; nothing is blocked
// unless the server switch allow_after_full is off, and then can_record_another is false and the button is not drawn).
type Props = { deliveryId: number; poId: number; iarNumber: string; items: DeliveryItemsPanelData };

const MIN_REASON = 5;

export const lineColumns: ListColumn<DeliveryLine>[] = [
    { key: 'description', header: 'PO line', card: 'title', cell: (l) => <span className="break-words">{l.description}</span> },
    { key: 'unit', header: 'Unit', cell: (l) => l.unit ?? '—' },
    { key: 'ordered', header: 'Ordered', card: 'summary', className: 'tabular-nums', cell: (l) => l.ordered },
    { key: 'delivered', header: 'Total delivered', card: 'summary', className: 'tabular-nums', cell: (l) => l.delivered },
    {
        key: 'undelivered',
        header: 'Undelivered',
        card: 'badge',
        className: 'tabular-nums',
        cell: (l) =>
            l.over_by ? (
                <Badge variant="outline" className="border-amber-500/60 text-amber-700 dark:text-amber-400">
                    Over by {l.over_by}
                </Badge>
            ) : (
                l.undelivered
            ),
    },
];

export default function DeliveryItemsPanel({ deliveryId, poId, iarNumber, items }: Props) {
    const [voiding, setVoiding] = useState<DeliveryItemEntry | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const withRows = items.lines.filter((line) => line.rows.length > 0);

    // Lines that already carry a quantity on THIS IAR (entered_here is null when none). Worked out from what the server sent.
    const totalLines = items.lines.length;
    const enteredLines = items.lines.filter((line) => line.entered_here !== null).length;
    const allEntered = totalLines > 0 && enteredLines === totalLines;
    const showUpdate = items.can_update && !allEntered;
    const showAllEnteredNote = items.can_update && allEntered;

    function close() {
        setVoiding(null);
        setReason('');
    }

    function submitVoid(event: FormEvent) {
        event.preventDefault();

        if (!voiding || reason.trim().length < MIN_REASON) {
            return;
        }

        router.post(
            `/deliveries/${deliveryId}/items/${voiding.id}/void`,
            { reason: reason.trim() },
            {
                preserveScroll: true,
                onStart: () => {
                    setBusy(true);
                    setActionError(null);
                },
                onSuccess: () => close(),
                onError: (errors: Record<string, string>) => setActionError(errors.form ?? errors.reason ?? 'The action could not be completed.'),
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <section className="space-y-4 rounded-md border p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <h3 className="text-base font-medium">Delivered items · IAR {iarNumber}</h3>
                    <p className="text-sm text-muted-foreground">
                        PO progress: <span className="font-medium text-foreground">{items.progress.label}</span>. Totals count every delivery of the PO and are worked out each time, never saved.
                    </p>
                    {totalLines > 0 && (
                        <p className="text-sm text-muted-foreground">
                            PO lines entered on this IAR: <span className="font-medium text-foreground tabular-nums">{enteredLines} of {totalLines}</span>
                        </p>
                    )}
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    {showUpdate && (
                        <Button asChild size="sm">
                            <Link href={`/deliveries/${deliveryId}/items/create`}>
                                <Plus className="size-4" /> Update delivered items
                            </Link>
                        </Button>
                    )}
                    {items.can_record_another && (
                        <Button asChild size="sm" variant={items.fully_delivered ? 'outline' : 'default'}>
                            <Link href={`/deliveries/create?purchase_order=${poId}`}>
                                <Truck className="size-4" /> Record another delivery
                            </Link>
                        </Button>
                    )}
                </div>
            </div>

            <div className="rounded-md border bg-muted/40 px-3 py-2 text-sm">
                {showAllEnteredNote ? (
                    <>
                        <p className="font-medium">Every PO line already has a quantity on this IAR, so there is nothing more to add here.</p>
                        <p className="mt-1 text-muted-foreground">
                            To correct a quantity, void it in the history below and enter it again. More items arrive with a new IAR: record a new delivery from the PO page.
                        </p>
                    </>
                ) : (
                    <p>One IAR = one delivery. If more items arrive later with a new IAR, record a new delivery from the PO page.</p>
                )}
                {items.can_record_another && items.fully_delivered && <p className="mt-1 font-medium text-amber-700 dark:text-amber-400">{FULLY_DELIVERED_NOTE}</p>}
                {!items.can_record_another && (
                    <p className="mt-1 text-muted-foreground">{items.record_blocked_reason ?? 'Ask the Encoder to record it.'}</p>
                )}
            </div>

            {items.locked_reason && <p className="text-sm text-muted-foreground">{items.locked_reason}</p>}
            {actionError && <p className="text-sm text-destructive">{actionError}</p>}

            <ResponsiveList columns={lineColumns} rows={items.lines} rowKey={(l) => l.po_item_id} emptyMessage="This PO has no lines." />

            {voiding && (
                <form onSubmit={submitVoid} className="space-y-3 rounded-md border border-amber-500/40 bg-amber-500/10 p-4">
                    <p className="text-sm font-medium break-words">
                        Void {voiding.quantity} delivered on {voiding.delivered_on} (IAR {voiding.iar_number})?
                    </p>
                    <p className="text-sm text-muted-foreground">The line stays on record and stops counting. To correct a quantity, void it and enter the line again.</p>
                    <div className="space-y-1.5">
                        <Label htmlFor="delivery-item-void-reason">Reason (required)</Label>
                        <textarea
                            id="delivery-item-void-reason"
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                            maxLength={500}
                            rows={3}
                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                            placeholder="Say briefly what is wrong."
                        />
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="submit" size="sm" variant="destructive" disabled={busy || reason.trim().length < MIN_REASON}>
                            {busy ? 'Working...' : 'Void line'}
                        </Button>
                        <Button type="button" size="sm" variant="ghost" disabled={busy} onClick={close}>
                            Cancel
                        </Button>
                    </div>
                </form>
            )}

            <div className="space-y-2">
                <h4 className="text-sm font-medium">History of this delivery</h4>
                {withRows.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No delivered items were entered for this delivery yet.</p>
                ) : (
                    withRows.map((line) => (
                        <details key={line.po_item_id} className="rounded-md border px-3 py-2" open>
                            <summary className="cursor-pointer text-sm font-medium break-words">
                                {line.description} ({line.rows.length})
                            </summary>
                            {line.active_here > 1 && (
                                <p className="mt-2 rounded-md border border-amber-500/40 bg-amber-500/10 px-3 py-2 text-sm">
                                    This line has {line.active_here} quantities on this IAR (entered before the one-quantity rule). Both still count in the totals.
                                </p>
                            )}
                            <div className="mt-2">
                                <DeliveryLineHistory
                                    rows={line.rows}
                                    busy={busy}
                                    onVoid={(row) => {
                                        setVoiding(row);
                                        setReason('');
                                        setActionError(null);
                                    }}
                                />
                            </div>
                        </details>
                    ))
                )}
            </div>
        </section>
    );
}