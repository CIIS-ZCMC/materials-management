import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useState } from 'react';
import type { ItemSheet } from '@/components/deliveries/delivery-types';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

// Step 6.1: the PO Reviewer's delivery update. Tick the PO lines that arrived, type the quantity delivered today and its
// date for each, and save them all at once (one save, all or nothing). The numbers on the right of each line are worked out
// in your browser while you type, so you can see the running total before saving; the server works them out again on save.
//
// React state (useState) in this page:
//   entries  what is typed for every line: ticked or not, quantity, date, over-delivery tick and its reason
//   picked   the ids of the lines sent in the last save (the server reports errors by position, "lines.0.quantity")
//   errors   the server's messages, busy: a request is running
type Props = {
    delivery: { id: number; iar_number: string; date_received: string; purchase_order: { id: number; po_number: string; supplier: string | null } };
    sheet: ItemSheet;
};

type Entry = { selected: boolean; quantity: string; date: string; over: boolean; reason: string };

const INPUT = 'w-full rounded-md border bg-background px-3 py-2 text-sm tabular-nums';

// Quantities are compared as whole thousandths, like the server does (so 0.1 + 0.2 is exactly 0.3).
function toThousandths(text: string): number | null {
    const value = text.trim();

    if (!/^\d{1,9}(\.\d{1,3})?$/.test(value)) {
        return null;
    }

    const thousandths = Math.round(parseFloat(value) * 1000);

    return thousandths >= 1 ? thousandths : null;
}

function fromServer(text: string): number {
    return Math.round(parseFloat(text) * 1000);
}

function show(thousandths: number): string {
    return (thousandths / 1000).toFixed(3).replace(/\.?0+$/, '');
}

export default function DeliveryItemsCreate({ delivery, sheet }: Props) {
    const [entries, setEntries] = useState<Record<number, Entry>>(() => {
        const initial: Record<number, Entry> = {};

        sheet.lines.forEach((line) => {
            initial[line.po_item_id] = { selected: false, quantity: '', date: sheet.today, over: false, reason: '' };
        });

        return initial;
    });
    const [picked, setPicked] = useState<number[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);

    const chosen = sheet.lines.filter((line) => entries[line.po_item_id].selected);

    function change(id: number, patch: Partial<Entry>) {
        setEntries((current) => ({ ...current, [id]: { ...current[id], ...patch } }));
    }

    function lineError(id: number, field: string): string | undefined {
        const index = picked.indexOf(id);

        return index < 0 ? undefined : errors[`lines.${index}.${field}`];
    }

    // What one ticked line would be after saving.
    function figures(id: number, ordered: string, delivered: string) {
        const entry = entries[id];
        const now = toThousandths(entry.quantity);
        const before = fromServer(delivered);
        const orderedK = fromServer(ordered);
        const after = before + (now ?? 0);

        return { now, before, orderedK, after, over: now !== null && after > orderedK, undelivered: Math.max(0, orderedK - after) };
    }

    const canSave =
        !busy &&
        chosen.length > 0 &&
        chosen.every((line) => {
            const entry = entries[line.po_item_id];
            const f = figures(line.po_item_id, line.ordered, line.delivered);

            return f.now !== null && entry.date !== '' && (!f.over || (entry.over && entry.reason.trim().length >= 5));
        });

    function save() {
        const ids = chosen.map((line) => line.po_item_id);

        setPicked(ids);

        router.post(
            `/deliveries/${delivery.id}/items`,
            {
                lines: chosen.map((line) => {
                    const entry = entries[line.po_item_id];
                    const f = figures(line.po_item_id, line.ordered, line.delivered);

                    return {
                        po_item_id: line.po_item_id,
                        quantity: entry.quantity.trim(),
                        delivered_on: entry.date,
                        over_delivery: f.over && entry.over,
                        over_delivery_reason: f.over && entry.over ? entry.reason.trim() : '',
                    };
                }),
            },
            {
                preserveScroll: true,
                onStart: () => {
                    setBusy(true);
                    setErrors({});
                },
                onError: (serverErrors: Record<string, string>) => setErrors(serverErrors),
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <>
            <Head title={`Delivered items, IAR ${delivery.iar_number}`} />

            <div className="space-y-4 p-4 md:space-y-6">
                <Button asChild variant="outline" size="sm">
                    <Link href={`/deliveries/${delivery.id}`}>
                        <ArrowLeft className="size-4" /> Back to delivery {delivery.iar_number}
                    </Link>
                </Button>

                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        title="Update delivered items"
                        description={`IAR ${delivery.iar_number} · PO ${delivery.purchase_order.po_number}${delivery.purchase_order.supplier ? ` · ${delivery.purchase_order.supplier}` : ''}`}
                    />
                    <Badge variant="secondary">PO progress: {sheet.progress.label}</Badge>
                </div>

                <p className="text-sm text-muted-foreground">
                    Tick each PO line that arrived, then type the quantity delivered today and its date. Quantities may have up to 3 decimals (for example 2.5). A quantity above what was ordered
                    needs the over-delivery tick and a reason. If one line cannot be saved, none of them is.
                </p>

                {(errors.form || errors.lines) && <p className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm">{errors.form ?? errors.lines}</p>}

                {sheet.lines.length === 0 && <p className="text-sm text-muted-foreground">This PO has no lines.</p>}

                <div className="space-y-3">
                    {sheet.lines.map((line) => {
                        const entry = entries[line.po_item_id];
                        const f = figures(line.po_item_id, line.ordered, line.delivered);
                        const checkboxId = `item-line-${line.po_item_id}`;

                        return (
                            <div key={line.po_item_id} className={`space-y-3 rounded-md border p-3 ${entry.selected ? 'border-primary/50' : ''}`}>
                                <div className="flex items-start gap-3">
                                    <input
                                        id={checkboxId}
                                        type="checkbox"
                                        className="mt-1 size-4"
                                        checked={entry.selected}
                                        onChange={(e) => change(line.po_item_id, { selected: e.target.checked })}
                                    />
                                    <label htmlFor={checkboxId} className="min-w-0 cursor-pointer">
                                        <span className="block font-medium break-words">{line.description}</span>
                                        {line.unit && <span className="block text-xs text-muted-foreground">Unit: {line.unit}</span>}
                                    </label>
                                </div>

                                <dl className="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-5">
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Ordered</dt>
                                        <dd className="font-medium tabular-nums">{line.ordered}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Delivered before</dt>
                                        <dd className="font-medium tabular-nums">{line.delivered}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Delivered now</dt>
                                        <dd className="font-medium tabular-nums">{entry.selected && f.now !== null ? show(f.now) : '—'}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Running total delivered</dt>
                                        <dd className="font-medium tabular-nums">{entry.selected && f.now !== null ? show(f.after) : line.delivered}</dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">Total undelivered</dt>
                                        <dd className="font-medium tabular-nums">{entry.selected && f.now !== null ? show(f.undelivered) : line.undelivered}</dd>
                                    </div>
                                </dl>

                                {entry.selected && (
                                    <div className="space-y-3">
                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="space-y-1.5">
                                                <Label htmlFor={`${checkboxId}-quantity`}>Quantity delivered today</Label>
                                                <input
                                                    id={`${checkboxId}-quantity`}
                                                    type="text"
                                                    inputMode="decimal"
                                                    className={INPUT}
                                                    value={entry.quantity}
                                                    onChange={(e) => change(line.po_item_id, { quantity: e.target.value })}
                                                    placeholder="for example 500 or 2.5"
                                                />
                                                {lineError(line.po_item_id, 'quantity') && <p className="text-sm text-destructive">{lineError(line.po_item_id, 'quantity')}</p>}
                                                {!lineError(line.po_item_id, 'quantity') && entry.quantity.trim() !== '' && f.now === null && (
                                                    <p className="text-sm text-destructive">Use digits with up to 3 decimals, more than zero.</p>
                                                )}
                                            </div>
                                            <div className="space-y-1.5">
                                                <Label htmlFor={`${checkboxId}-date`}>Delivery date</Label>
                                                <input
                                                    id={`${checkboxId}-date`}
                                                    type="date"
                                                    className={INPUT}
                                                    value={entry.date}
                                                    min={sheet.min_date ?? undefined}
                                                    max={sheet.today}
                                                    onChange={(e) => change(line.po_item_id, { date: e.target.value })}
                                                />
                                                {lineError(line.po_item_id, 'delivered_on') && <p className="text-sm text-destructive">{lineError(line.po_item_id, 'delivered_on')}</p>}
                                            </div>
                                        </div>

                                        {lineError(line.po_item_id, 'po_item_id') && <p className="text-sm text-destructive">{lineError(line.po_item_id, 'po_item_id')}</p>}

                                        {f.over && (
                                            <div className="space-y-2 rounded-md border border-amber-500/40 bg-amber-500/10 p-3">
                                                <p className="text-sm font-medium">This is {show(f.after - f.orderedK)} more than ordered.</p>
                                                <label className="flex items-center gap-2 text-sm">
                                                    <input
                                                        type="checkbox"
                                                        className="size-4"
                                                        checked={entry.over}
                                                        onChange={(e) => change(line.po_item_id, { over: e.target.checked })}
                                                    />
                                                    Over-delivery (record it anyway)
                                                </label>
                                                {entry.over && (
                                                    <div className="space-y-1.5">
                                                        <Label htmlFor={`${checkboxId}-reason`}>Reason for the over-delivery (5 to 500 characters)</Label>
                                                        <textarea
                                                            id={`${checkboxId}-reason`}
                                                            value={entry.reason}
                                                            onChange={(e) => change(line.po_item_id, { reason: e.target.value })}
                                                            maxLength={500}
                                                            rows={2}
                                                            className="w-full rounded-md border bg-background px-3 py-2 text-sm"
                                                        />
                                                        {lineError(line.po_item_id, 'over_delivery_reason') && (
                                                            <p className="text-sm text-destructive">{lineError(line.po_item_id, 'over_delivery_reason')}</p>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Button type="button" disabled={!canSave} onClick={save}>
                        {busy ? 'Saving...' : chosen.length > 1 ? `Save ${chosen.length} lines` : 'Save'}
                    </Button>
                    <Button asChild type="button" variant="ghost">
                        <Link href={`/deliveries/${delivery.id}`}>Cancel</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

DeliveryItemsCreate.layout = {
    breadcrumbs: [
        { title: 'Deliveries', href: '/deliveries' },
        { title: 'Delivered items', href: '#' },
    ],
};