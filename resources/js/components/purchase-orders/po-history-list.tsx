import { ArrowRight } from 'lucide-react';
import { Badge } from '@/components/ui/badge';

export type HistoryChange = { field: string; old: string | null; new: string | null };

export type HistoryEntry = {
    id: number;
    event: string;
    event_label: string;
    description: string;
    who: string | null;
    when: string;
    reason: string | null;
    changes: HistoryChange[];
};

/**
 * The History tab: what happened to this PO, newest first, read from the audit log.
 * A plain list (no table), so it reads the same on a phone and on a desktop.
 */
export default function PoHistoryList({ entries }: { entries: HistoryEntry[] }) {
    if (entries.length === 0) {
        return <p className="rounded-md border border-dashed px-3 py-6 text-center text-sm text-muted-foreground">No history recorded for this PO yet.</p>;
    }

    return (
        <ol className="space-y-3">
            {entries.map((entry) => (
                <li key={entry.id} className="space-y-2 rounded-md border px-3 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <Badge variant="outline">{entry.event_label}</Badge>
                        <span className="text-xs tabular-nums text-muted-foreground">
                            {entry.who ?? 'System'} · {entry.when}
                        </span>
                    </div>

                    <p className="text-sm font-medium">{entry.description}</p>

                    {entry.reason && (
                        <p className="rounded-md bg-muted/40 px-3 py-2 text-sm">
                            <span className="text-muted-foreground">Reason: </span>
                            {entry.reason}
                        </p>
                    )}

                    {entry.changes.length > 0 && (
                        <dl className="space-y-1 text-sm">
                            {entry.changes.map((change) => (
                                <div key={change.field} className="flex flex-wrap items-baseline gap-x-2">
                                    <dt className="text-muted-foreground">{change.field}:</dt>
                                    <dd className="min-w-0 break-words">
                                        {change.old !== null && (
                                            <>
                                                <span>{change.old}</span> <ArrowRight className="mx-1 inline size-3.5 text-muted-foreground" />
                                            </>
                                        )}
                                        <span className="font-medium">{change.new ?? '—'}</span>
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                </li>
            ))}
        </ol>
    );
}