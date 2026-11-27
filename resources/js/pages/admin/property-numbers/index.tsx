import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import Heading from '@/components/heading';
import LiveSearchInput from '@/components/live-search-input';
import PaginationBar from '@/components/pagination-bar';
import type { PaginationMeta } from '@/components/pagination-bar';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import ProvisionalNotice from '@/components/property/provisional-notice';
import { useLiveSearch } from '@/hooks/use-live-search';

type SeriesRow = {
    type: string;
    type_label: string;
    year: number;
    next_number: number;
    next_formatted: string;
    highest: number | null;
    used: number;
    void: number;
    exists: boolean;
};

type VoidedRow = {
    id: number;
    number: string;
    series_label: string | null;
    void_reason: string | null;
    voided_by: string | null;
    voided_at: string | null;
    delivery_item_id: number | null;
};

type Paginated = PaginationMeta & { data: VoidedRow[] };

type Props = {
    series: SeriesRow[];
    voided: Paginated;
    filters: { search: string };
    currentYear: number;
    provisional: boolean;
};

const TYPES = [
    { value: 'par', label: 'PAR (PAR-26-0001)' },
    { value: 'sub_par', label: 'Sub-PAR (SPAR-26-0001)' },
    { value: 'ics', label: 'ICS (ICS-26-0001)' },
];

export default function PropertyNumbersIndex({ series, voided, filters, currentYear, provisional }: Props) {
    useFlashToast();

    const search = useLiveSearch({
        url: '/admin/property-numbers',
        initial: filters.search ?? '',
        only: ['voided', 'filters'],
    });

    const [open, setOpen] = useState(false);
    const form = useForm({ type: 'par', year: String(currentYear), next_number: '' });

    const openFor = (row?: SeriesRow) => {
        form.clearErrors();
        form.setData({
            type: row?.type ?? 'par',
            year: String(row?.year ?? currentYear),
            next_number: row ? String(row.next_number) : '',
        });
        setOpen(true);
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put('/admin/property-numbers/counter', {
            preserveScroll: true,
            onSuccess: () => setOpen(false),
        });
    };

    const seriesColumns: ListColumn<SeriesRow>[] = [
        { key: 'series', header: 'Series', card: 'title', cell: (s) => `${s.type_label} ${s.year}` },
        {
            key: 'next',
            header: 'Next number',
            card: 'summary',
            cell: (s) => <span className="font-mono">{s.next_formatted}</span>,
        },
        {
            key: 'last',
            header: 'Highest recorded',
            cell: (s) => <span className="font-mono">{s.highest ?? '—'}</span>,
        },
        { key: 'used', header: 'Issued', cell: (s) => s.used },
        { key: 'void', header: 'Voided', cell: (s) => s.void },
        {
            key: 'status',
            header: 'Status',
            card: 'badge',
            cell: (s) => <Badge variant={s.exists ? 'default' : 'outline'}>{s.exists ? 'Active' : 'Not started'}</Badge>,
        },
    ];

    const voidedColumns: ListColumn<VoidedRow>[] = [
        { key: 'number', header: 'Number', card: 'title', cell: (n) => <span className="font-mono">{n.number}</span> },
        { key: 'series', header: 'Series', card: 'summary', cell: (n) => n.series_label ?? '—' },
        { key: 'reason', header: 'Reason', cell: (n) => n.void_reason ?? '—' },
        { key: 'by', header: 'Voided by', cell: (n) => n.voided_by ?? '—' },
        { key: 'on', header: 'Voided on', cell: (n) => n.voided_at ?? '—' },
        {
            key: 'line',
            header: 'Delivered line',
            cell: (n) =>
                n.delivery_item_id ? (
                    <Link href={`/property-documents/items/${n.delivery_item_id}`} className="underline">
                        #{n.delivery_item_id}
                    </Link>
                ) : (
                    '—'
                ),
        },
    ];

    return (
        <>
            <Head title="Property numbers" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        title="Property numbers"
                        description="PAR, Sub-PAR and ICS numbers: three series per year (for example PAR-26-0001)"
                    />
                    <Button onClick={() => openFor()}>Set counter</Button>
                </div>

                {provisional && <ProvisionalNotice what="The number format (prefixes and digits) is" />}

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Series</h2>
                    <ResponsiveList
                        columns={seriesColumns}
                        rows={series}
                        rowKey={(s) => `${s.type}-${s.year}`}
                        renderActions={(s) => (
                            <Button size="sm" variant="outline" onClick={() => openFor(s)}>
                                Set counter
                            </Button>
                        )}
                        emptyMessage="No series yet."
                    />
                    <p className="text-sm text-muted-foreground">
                        The next number is only a suggestion. A number is used up when a document is issued. A voided number is never
                        used again.
                    </p>
                </section>

                <section className="space-y-3">
                    <h2 className="text-base font-semibold">Voided numbers</h2>
                    <LiveSearchInput
                        value={search.value}
                        onChange={search.setValue}
                        onClear={search.clear}
                        onSubmit={search.flush}
                        loading={search.loading}
                        placeholder="Search number or reason"
                    />
                    <ResponsiveList
                        columns={voidedColumns}
                        rows={voided.data}
                        rowKey={(n) => n.id}
                        loading={search.loading}
                        emptyMessage="No voided numbers."
                    />
                    <PaginationBar meta={voided} onNavigate={search.goTo} noun="voided numbers" />
                </section>
            </div>

            <Dialog open={open} onOpenChange={(value) => !form.processing && setOpen(value)}>
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Set counter</DialogTitle>
                            <DialogDescription>
                                The next suggested number will start from this value. It must be above the highest number already
                                recorded for that series and year.
                            </DialogDescription>
                        </DialogHeader>

                        <div className="space-y-2">
                            <Label htmlFor="prop-type">Series</Label>
                            <select
                                id="prop-type"
                                className="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm"
                                value={form.data.type}
                                onChange={(e) => form.setData('type', e.target.value)}
                            >
                                {TYPES.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                            {form.errors.type && <p className="text-sm text-destructive">{form.errors.type}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="prop-year">Year</Label>
                            <Input
                                id="prop-year"
                                inputMode="numeric"
                                value={form.data.year}
                                onChange={(e) => form.setData('year', e.target.value)}
                            />
                            {form.errors.year && <p className="text-sm text-destructive">{form.errors.year}</p>}
                        </div>

                        <div className="space-y-2">
                            <Label htmlFor="prop-next">Next number (digits only, e.g. 25)</Label>
                            <Input
                                id="prop-next"
                                inputMode="numeric"
                                value={form.data.next_number}
                                onChange={(e) => form.setData('next_number', e.target.value)}
                            />
                            {form.errors.next_number && <p className="text-sm text-destructive">{form.errors.next_number}</p>}
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" disabled={form.processing} onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Set counter
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

PropertyNumbersIndex.layout = {
    breadcrumbs: [{ title: 'Property numbers', href: '/admin/property-numbers' }],
};