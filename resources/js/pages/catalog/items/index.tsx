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

type ItemRow = {
    id: number;
    name: string;
    category: string | null;
    is_equipment: boolean;
    unit: string | null;
    unit_code: string | null;
    is_active: boolean;
};

type Paginated = PaginationMeta & { data: ItemRow[] };

type Props = {
    items: Paginated;
    filters: { search: string };
    canManage: boolean;
};

export default function ItemsIndex({ items, filters, canManage }: Props) {
    useFlashToast();

    const search = useLiveSearch({
        url: '/catalog/items',
        initial: filters.search ?? '',
        only: ['items', 'filters'],
    });

    const [pendingDeactivate, setPendingDeactivate] = useState<ItemRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const sendActive = (item: ItemRow, active: boolean) => {
        router.patch(
            `/catalog/items/${item.id}/active`,
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

    const toggleActive = (item: ItemRow) => {
        if (item.is_active) {
            setPendingDeactivate(item);
        } else {
            sendActive(item, true);
        }
    };

    const columns: ListColumn<ItemRow>[] = [
        { key: 'name', header: 'Name', card: 'title', cell: (i) => i.name },
        { key: 'category', header: 'Category', card: 'summary', cell: (i) => i.category ?? '—' },
        {
            key: 'unit',
            header: 'Unit',
            cell: (i) => (i.unit ? (i.unit_code ? `${i.unit} (${i.unit_code})` : i.unit) : '—'),
        },
        { key: 'is_equipment', header: 'Equipment?', cell: (i) => (i.is_equipment ? 'Yes' : 'No') },
        {
            key: 'status',
            header: 'Status',
            card: 'badge',
            cell: (i) => <Badge variant={i.is_active ? 'default' : 'outline'}>{i.is_active ? 'Active' : 'Inactive'}</Badge>,
        },
    ];

    const renderActions = (item: ItemRow) => (
        <>
            <Button asChild size="sm" variant="outline">
                <Link href={`/catalog/items/${item.id}/edit`}>Edit</Link>
            </Button>
            <Button size="sm" variant="ghost" onClick={() => toggleActive(item)}>
                {item.is_active ? 'Deactivate' : 'Activate'}
            </Button>
        </>
    );

    return (
        <>
            <Head title="Items" />

            <div className="space-y-4 p-4 md:space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        title="Items"
                        description={canManage ? 'Add and maintain the item catalog' : 'Item catalog (view only)'}
                    />
                    {canManage && (
                        <Button asChild>
                            <Link href="/catalog/items/create">
                                <Plus className="size-4" /> New item
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
                    placeholder="Search item, category, unit"
                />

                <ResponsiveList
                    columns={columns}
                    rows={items.data}
                    rowKey={(i) => i.id}
                    renderActions={canManage ? renderActions : undefined}
                    loading={search.loading}
                    emptyMessage="No items found."
                />

                <PaginationBar meta={items} onNavigate={search.goTo} noun="items" />
            </div>

            <ConfirmDialog
                open={pendingDeactivate !== null}
                onOpenChange={(open) => {
                    if (!open && !processing) {
                        setPendingDeactivate(null);
                    }
                }}
                title="Deactivate item"
                description={`Deactivate ${pendingDeactivate?.name ?? ''}? It stays in the list but is marked Inactive.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => pendingDeactivate && sendActive(pendingDeactivate, false)}
            />
        </>
    );
}

ItemsIndex.layout = {
    breadcrumbs: [
        { title: 'Catalog', href: '/catalog/items' },
        { title: 'Items', href: '/catalog/items' },
    ],
};
