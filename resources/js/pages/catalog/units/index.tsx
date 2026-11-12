import { Head, Link, router } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import Heading from '@/components/heading';
import LiveSearchInput from '@/components/live-search-input';
import PaginationBar from '@/components/pagination-bar';
import type { PaginationMeta } from '@/components/pagination-bar';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLiveSearch } from '@/hooks/use-live-search';

type UnitRow = {
    id: number;
    name: string;
    code: string | null;
    is_active: boolean;
};

type Paginated = PaginationMeta & { data: UnitRow[] };

type Props = {
    units: Paginated;
    filters: { search: string };
    canManage: boolean;
};

export default function UnitsIndex({ units, filters, canManage }: Props) {
    useFlashToast();

    const search = useLiveSearch({
        url: '/catalog/units',
        initial: filters.search ?? '',
        only: ['units', 'filters'],
    });

    const [pendingDeactivate, setPendingDeactivate] = useState<UnitRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const sendActive = (unit: UnitRow, active: boolean) => {
        router.patch(
            `/catalog/units/${unit.id}/active`,
            { active },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Action failed.'),
                onFinish: () => {
                    setProcessing(false);
                    setPendingDeactivate(null);
                },
            },
        );
    };

    const toggleActive = (unit: UnitRow) => {
        if (unit.is_active) {
            setPendingDeactivate(unit);
        } else {
            sendActive(unit, true);
        }
    };

    const columns: ListColumn<UnitRow>[] = [
        { key: 'name', header: 'Name', card: 'title', cell: (u) => u.name },
        { key: 'code', header: 'Short code', card: 'summary', cell: (u) => u.code ?? '—' },
        {
            key: 'status',
            header: 'Status',
            card: 'badge',
            cell: (u) => <Badge variant={u.is_active ? 'default' : 'outline'}>{u.is_active ? 'Active' : 'Inactive'}</Badge>,
        },
    ];

    const renderActions = (unit: UnitRow) => (
        <>
            <Button asChild size="sm" variant="outline">
                <Link href={`/catalog/units/${unit.id}/edit`}>Edit</Link>
            </Button>
            <Button size="sm" variant="ghost" onClick={() => toggleActive(unit)}>
                {unit.is_active ? 'Deactivate' : 'Activate'}
            </Button>
        </>
    );

    return (
        <>
            <Head title="Units" />

            <div className="space-y-4 p-4 md:space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        title="Units"
                        description={canManage ? 'Units of measure for items' : 'Units of measure (view only)'}
                    />
                    {canManage && (
                        <Button asChild>
                            <Link href="/catalog/units/create">
                                <Plus className="size-4" /> New unit
                            </Link>
                        </Button>
                    )}
                </div>

                <LiveSearchInput
                    value={search.value}
                    onChange={search.setValue}
                    onClear={search.clear}
                    onSubmit={search.flush}
                    loading={search.loading}
                    placeholder="Search unit name or short code"
                />

                <ResponsiveList
                    columns={columns}
                    rows={units.data}
                    rowKey={(u) => u.id}
                    renderActions={canManage ? renderActions : undefined}
                    loading={search.loading}
                    emptyMessage="No units found."
                />

                <PaginationBar meta={units} onNavigate={search.goTo} noun="units" />
            </div>

            <ConfirmDialog
                open={pendingDeactivate !== null}
                onOpenChange={(open) => {
                    if (!open && !processing) {
                        setPendingDeactivate(null);
                    }
                }}
                title="Deactivate unit"
                description={`Deactivate ${pendingDeactivate?.name ?? ''}? It stays in the list but is marked Inactive, and new items cannot use it.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => pendingDeactivate && sendActive(pendingDeactivate, false)}
            />
        </>
    );
}

UnitsIndex.layout = {
    breadcrumbs: [
        { title: 'Catalog', href: '/catalog/items' },
        { title: 'Units', href: '/catalog/units' },
    ],
};
