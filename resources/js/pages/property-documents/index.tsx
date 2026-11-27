import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import Heading from '@/components/heading';
import PaginationBar from '@/components/pagination-bar';
import type { PaginationMeta } from '@/components/pagination-bar';
import ProvisionalNotice from '@/components/property/provisional-notice';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Tab = 'pending' | 'done' | 'attention' | 'voided';

type ItemRow = {
    id: number;
    description: string;
    unit: string | null;
    quantity: string;
    unit_price: string;
    iar_number: string;
    po_number: string;
    delivered_on: string | null;
    state: string;
    state_label: string;
    band_label: string | null;
    documents: { type: string; number: string }[];
};

type VoidedRow = {
    id: number;
    number: string;
    type_label: string;
    description: string | null;
    delivery_item_id: number | null;
    void_reason: string | null;
    voided_by: string | null;
    voided_at: string | null;
};

type Props = {
    tab: Tab;
    rows: PaginationMeta & { data: (ItemRow | VoidedRow)[] };
    counts: Record<Tab, number>;
    filters: { search: string; tab: Tab };
    rule: { provisional: boolean; par_threshold: string; ics_min: string };
};

const TABS: { value: Tab; label: string }[] = [
    { value: 'pending', label: 'Pending' },
    { value: 'done', label: 'Done' },
    { value: 'attention', label: 'Needs attention' },
    { value: 'voided', label: 'Voided documents' },
];

const EMPTY: Record<Tab, string> = {
    pending: 'Nothing is waiting for an equipment decision or a property document.',
    done: 'No finished lines yet.',
    attention: 'No line needs attention.',
    voided: 'No voided documents.',
};

function stateVariant(state: string): 'default' | 'secondary' | 'outline' | 'destructive' {
    if (state === 'done') {
        return 'default';
    }

    if (state === 'attention') {
        return 'destructive';
    }

    return 'secondary';
}

export default function PropertyDocumentsIndex({ tab, rows, counts, filters, rule }: Props) {
    useFlashToast();

    const [search, setSearch] = useState(filters.search ?? '');

    const go = (nextTab: Tab, term: string) =>
        router.get('/property-documents', { tab: nextTab, search: term || undefined }, { preserveScroll: true, preserveState: true });

    const itemColumns: ListColumn<ItemRow>[] = [
        { key: 'item', header: 'Item', card: 'title', cell: (r) => r.description },
        {
            key: 'qty',
            header: 'Quantity',
            card: 'summary',
            cell: (r) => `${r.quantity}${r.unit ? ` ${r.unit}` : ''} at ${r.unit_price}`,
        },
        { key: 'iar', header: 'IAR', cell: (r) => <span className="font-mono">{r.iar_number}</span> },
        { key: 'po', header: 'PO', cell: (r) => <span className="font-mono">{r.po_number}</span> },
        { key: 'date', header: 'Delivered', cell: (r) => r.delivered_on ?? '—' },
        { key: 'band', header: 'Rule result', cell: (r) => r.band_label ?? '—' },
        {
            key: 'docs',
            header: 'Documents',
            cell: (r) =>
                r.documents.length === 0 ? (
                    '—'
                ) : (
                    <span className="font-mono">{r.documents.map((d) => d.number).join(', ')}</span>
                ),
        },
        {
            key: 'state',
            header: 'Status',
            card: 'badge',
            cell: (r) => <Badge variant={stateVariant(r.state)}>{r.state_label}</Badge>,
        },
    ];

    const voidedColumns: ListColumn<VoidedRow>[] = [
        { key: 'number', header: 'Number', card: 'title', cell: (r) => <span className="font-mono">{r.number}</span> },
        { key: 'type', header: 'Type', card: 'summary', cell: (r) => r.type_label },
        { key: 'item', header: 'Item', cell: (r) => r.description ?? '—' },
        { key: 'reason', header: 'Reason', cell: (r) => r.void_reason ?? '—' },
        { key: 'by', header: 'Voided by', cell: (r) => r.voided_by ?? '—' },
        { key: 'on', header: 'Voided on', cell: (r) => r.voided_at ?? '—' },
    ];

    return (
        <>
            <Head title="Property documents" />

            <div className="space-y-6 p-4">
                <Heading
                    title="Property documents"
                    description="Equipment decision, then PAR with Sub-PAR or ICS, for each delivered line"
                />

                {rule.provisional && <ProvisionalNotice />}

                <p className="text-sm text-muted-foreground">
                    Rule used now, per unit price: above {rule.par_threshold} is a PAR with a Sub-PAR; from {rule.ics_min} up to and including{' '}
                    {rule.par_threshold} is an ICS; below {rule.ics_min} neither (remarks are required); not equipment needs no document.
                </p>

                <div className="flex flex-wrap gap-2">
                    {TABS.map((t) => (
                        <Button
                            key={t.value}
                            type="button"
                            size="sm"
                            variant={t.value === tab ? 'default' : 'outline'}
                            onClick={() => go(t.value, search)}
                        >
                            {t.label} ({counts[t.value]})
                        </Button>
                    ))}
                </div>

                <form
                    className="flex max-w-xl gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        go(tab, search.trim());
                    }}
                >
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder={tab === 'voided' ? 'Search number or reason' : 'Search item, IAR, PO or document number'}
                    />
                    <Button type="submit" variant="outline">
                        Search
                    </Button>
                </form>

                {tab === 'voided' ? (
                    <ResponsiveList
                        columns={voidedColumns}
                        rows={rows.data as VoidedRow[]}
                        rowKey={(r) => r.id}
                        renderActions={(r) =>
                            r.delivery_item_id ? (
                                <Button asChild size="sm" variant="outline">
                                    <Link href={`/property-documents/items/${r.delivery_item_id}`}>Open line</Link>
                                </Button>
                            ) : null
                        }
                        emptyMessage={EMPTY[tab]}
                    />
                ) : (
                    <ResponsiveList
                        columns={itemColumns}
                        rows={rows.data as ItemRow[]}
                        rowKey={(r) => r.id}
                        renderActions={(r) => (
                            <Button asChild size="sm" variant="outline">
                                <Link href={`/property-documents/items/${r.id}`}>Open</Link>
                            </Button>
                        )}
                        emptyMessage={EMPTY[tab]}
                    />
                )}

                <PaginationBar meta={rows} onNavigate={(url: string | null) => {
                        if (url) {
                            router.get(url, {}, { preserveScroll: true });
                        }
                    }} noun="lines" />
            </div>
        </>
    );
}

PropertyDocumentsIndex.layout = {
    breadcrumbs: [{ title: 'Property documents', href: '/property-documents' }],
};
