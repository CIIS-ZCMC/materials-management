import { Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState, type FormEvent } from 'react';
import DeliveryLineHistory from '@/components/deliveries/delivery-line-history';
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
type Props = { deliveryId: number; items: DeliveryItemsPanelData };

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

export default function DeliveryItemsPanel({ deliveryId, items }: Props) {
    const [voiding, setVoiding] = useState<DeliveryItemEntry | null>(null);
    const [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false);
    const [actionError, setActionError] = useState<string | null>(null);

    const withRows = items.lines.filter((line) => line.rows.length > 0);

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
                    <h3 className="text-base font-medium">Delivered items</h3>
                    <p className="text-sm text-muted-foreground">
                        PO progress: <span className="font-medium text-foreground">{items.progress.label}</span>. Totals count every delivery of the PO and are worked out each time, never saved.
                    </p>
                </div>
                {items.can_update && (
                    <Button asChild size="sm">
                        <Link href={`/deliveries/${deliveryId}/items/create`}>
                            <Plus className="size-4" /> Update delivered items
                        </Link>
                    </Button>
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