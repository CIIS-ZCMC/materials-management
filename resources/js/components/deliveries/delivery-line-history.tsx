import { Link } from '@inertiajs/react';
import { Ban } from 'lucide-react';
import type { DeliveryItemEntry } from '@/components/deliveries/delivery-types';
import { orDash } from '@/components/purchase-orders/po-format';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

// Step 6.1: the history of ONE PO line: every delivered quantity with its date, delivery (IAR), who entered it,
// the over-delivery badge and, for a voided row, its reason. Voided rows stay in the list but are struck through.
// "onVoid" is given only where the person may void (the delivery page); the server checks again on save.
type Props = {
    rows: DeliveryItemEntry[];
    onVoid?: (row: DeliveryItemEntry) => void;
    busy?: boolean;
};

export default function DeliveryLineHistory({ rows, onVoid, busy = false }: Props) {
    if (rows.length === 0) {
        return <p className="text-sm text-muted-foreground">Nothing delivered yet.</p>;
    }

    return (
        <ul className="divide-y rounded-md border text-sm">
            {rows.map((row) => {
                const voided = row.status.value === 'void';

                return (
                    <li key={row.id} className="space-y-1 px-3 py-2">
                        <div className="flex flex-wrap items-center gap-x-4 gap-y-1">
                            <span className="tabular-nums">{row.delivered_on}</span>
                            <Link href={`/deliveries/${row.delivery_id}`} className="underline-offset-4 hover:underline">
                                IAR {row.iar_number}
                            </Link>
                            <span className={`font-medium tabular-nums ${voided ? 'text-muted-foreground line-through' : ''}`}>{row.quantity}</span>
                            {voided && <Badge variant="destructive">Voided</Badge>}
                            {row.over_delivery && (
                                <Badge variant="outline" className="border-amber-500/60 text-amber-700 dark:text-amber-400">
                                    Over-delivery
                                </Badge>
                            )}
                            {onVoid && row.can_void && (
                                <Button type="button" size="sm" variant="outline" disabled={busy} onClick={() => onVoid(row)}>
                                    <Ban className="size-4" /> Void
                                </Button>
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
    );
}