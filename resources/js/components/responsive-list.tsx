import { ChevronDown } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { cn } from '@/lib/utils';

/**
 * Where a column shows up in the phone (card) layout:
 * - 'title'   the main identifier (one column; the first column if none is marked)
 * - 'badge'   small status badge on the right of the collapsed card
 * - 'summary' extra line(s) under the title in the collapsed card
 * - 'detail'  only shown after the card is expanded (default)
 * From the `md` breakpoint up every column is a normal table column.
 */
export type CardRole = 'title' | 'badge' | 'summary' | 'detail';

export type ListColumn<T> = {
    key: string;
    header: string;
    cell: (row: T) => ReactNode;
    /** Optional different content for the card. Return null to hide it in the card (e.g. empty value). */
    cardCell?: (row: T) => ReactNode;
    card?: CardRole;
    /** Extra classes for the table cell (e.g. 'font-mono'). */
    className?: string;
};

type Props<T> = {
    columns: ListColumn<T>[];
    rows: T[];
    rowKey: (row: T) => string | number;
    /** Buttons/links for a row. Shown in the last table cell and at the bottom of the expanded card. */
    renderActions?: (row: T) => ReactNode;
    /** Dims the list while a search/page request is running. */
    loading?: boolean;
    emptyMessage?: string;
};

export default function ResponsiveList<T>({ columns, rows, rowKey, renderActions, loading = false, emptyMessage = 'Nothing found.' }: Props<T>) {
    const [open, setOpen] = useState<Set<string | number>>(new Set());

    const toggle = (key: string | number, next: boolean) =>
        setOpen((prev) => {
            const copy = new Set(prev);

            if (next) {
                copy.add(key);
            } else {
                copy.delete(key);
            }

            return copy;
        });

    const role = (c: ListColumn<T>): CardRole => c.card ?? 'detail';
    const titleCol = columns.find((c) => role(c) === 'title') ?? columns[0];
    const badgeCols = columns.filter((c) => role(c) === 'badge');
    const summaryCols = columns.filter((c) => role(c) === 'summary');
    const detailCols = columns.filter((c) => role(c) === 'detail' && c !== titleCol);
    const cardValue = (c: ListColumn<T>, row: T) => (c.cardCell ? c.cardCell(row) : c.cell(row));
    const hasValue = (v: ReactNode) => v !== null && v !== undefined && v !== false && v !== '';

    return (
        <div aria-busy={loading} className={cn('transition-opacity', loading && 'opacity-60')}>
            {/* md and up: table */}
            <div className="hidden overflow-x-auto rounded-md border md:block">
                <table className="w-full text-sm">
                    <thead className="bg-muted/50 text-left">
                        <tr>
                            {columns.map((c) => (
                                <th key={c.key} className="px-3 py-2 font-medium">
                                    {c.header}
                                </th>
                            ))}
                            {renderActions && <th className="px-3 py-2" />}
                        </tr>
                    </thead>
                    <tbody>
                        {rows.length === 0 && (
                            <tr>
                                <td colSpan={columns.length + (renderActions ? 1 : 0)} className="px-3 py-8 text-center text-muted-foreground">
                                    {emptyMessage}
                                </td>
                            </tr>
                        )}
                        {rows.map((row) => (
                            <tr key={rowKey(row)} className="border-t">
                                {columns.map((c) => (
                                    <td key={c.key} className={cn('px-3 py-2', c.className)}>
                                        {c.cell(row)}
                                    </td>
                                ))}
                                {renderActions && (
                                    <td className="px-3 py-2">
                                        <div className="flex justify-end gap-2">{renderActions(row)}</div>
                                    </td>
                                )}
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* below md: expandable cards, no horizontal scrolling */}
            <div className="md:hidden">
                {rows.length === 0 ? (
                    <div className="rounded-md border px-3 py-8 text-center text-sm text-muted-foreground">{emptyMessage}</div>
                ) : (
                    <ul className="divide-y overflow-hidden rounded-md border">
                        {rows.map((row) => {
                            const key = rowKey(row);
                            const isOpen = open.has(key);
                            const summary = summaryCols.map((c) => ({ c, v: cardValue(c, row) })).filter((x) => hasValue(x.v));

                            return (
                                <li key={key}>
                                    <Collapsible open={isOpen} onOpenChange={(next) => toggle(key, next)}>
                                        <CollapsibleTrigger asChild>
                                            <button type="button" className="flex min-h-14 w-full items-start gap-3 px-3 py-3 text-left hover:bg-muted/40">
                                                <span className="min-w-0 flex-1">
                                                    <span className="flex items-center justify-between gap-2">
                                                        <span className="min-w-0 truncate font-medium">{cardValue(titleCol, row)}</span>
                                                        <span className="flex shrink-0 items-center gap-1">
                                                            {badgeCols.map((c) => (
                                                                <span key={c.key}>{cardValue(c, row)}</span>
                                                            ))}
                                                        </span>
                                                    </span>
                                                    {summary.length > 0 && (
                                                        <span className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-sm text-muted-foreground">
                                                            {summary.map(({ c, v }) => (
                                                                <span key={c.key}>{v}</span>
                                                            ))}
                                                        </span>
                                                    )}
                                                </span>
                                                <ChevronDown className={cn('mt-0.5 size-4 shrink-0 text-muted-foreground transition-transform', isOpen && 'rotate-180')} />
                                            </button>
                                        </CollapsibleTrigger>
                                        <CollapsibleContent>
                                            <div className="space-y-3 border-t bg-muted/20 px-3 py-3 text-sm">
                                                {detailCols.length > 0 && (
                                                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2">
                                                        {detailCols.map((c) => (
                                                            <div key={c.key} className="contents">
                                                                <dt className="text-muted-foreground">{c.header}</dt>
                                                                <dd className={cn('min-w-0 break-words', c.className)}>{cardValue(c, row)}</dd>
                                                            </div>
                                                        ))}
                                                    </dl>
                                                )}
                                                {renderActions && <div className="flex flex-wrap gap-2 [&>*]:min-h-10 [&>*]:flex-1">{renderActions(row)}</div>}
                                            </div>
                                        </CollapsibleContent>
                                    </Collapsible>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </div>
        </div>
    );
}