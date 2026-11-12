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

type CategoryRow = {
    id: number;
    name: string;
    is_equipment: boolean;
    is_active: boolean;
};

type Paginated = PaginationMeta & { data: CategoryRow[] };

type Props = {
    categories: Paginated;
    filters: { search: string };
    canManage: boolean;
};

export default function CategoriesIndex({ categories, filters, canManage }: Props) {
    useFlashToast();

    const search = useLiveSearch({
        url: '/catalog/categories',
        initial: filters.search ?? '',
        only: ['categories', 'filters'],
    });

    const [pendingDeactivate, setPendingDeactivate] = useState<CategoryRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const sendActive = (category: CategoryRow, active: boolean) => {
        router.patch(
            `/catalog/categories/${category.id}/active`,
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

    const toggleActive = (category: CategoryRow) => {
        if (category.is_active) {
            setPendingDeactivate(category);
        } else {
            sendActive(category, true);
        }
    };

    const columns: ListColumn<CategoryRow>[] = [
        { key: 'name', header: 'Name', card: 'title', cell: (c) => c.name },
        { key: 'is_equipment', header: 'Equipment?', card: 'summary', cell: (c) => (c.is_equipment ? 'Yes' : 'No') },
        {
            key: 'status',
            header: 'Status',
            card: 'badge',
            cell: (c) => <Badge variant={c.is_active ? 'default' : 'outline'}>{c.is_active ? 'Active' : 'Inactive'}</Badge>,
        },
    ];

    const renderActions = (category: CategoryRow) => (
        <>
            <Button asChild size="sm" variant="outline">
                <Link href={`/catalog/categories/${category.id}/edit`}>Edit</Link>
            </Button>
            <Button size="sm" variant="ghost" onClick={() => toggleActive(category)}>
                {category.is_active ? 'Deactivate' : 'Activate'}
            </Button>
        </>
    );

    return (
        <>
            <Head title="Categories" />

            <div className="space-y-4 p-4 md:space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        title="Categories"
                        description={canManage ? 'Group items and mark which groups are equipment' : 'Item categories (view only)'}
                    />
                    {canManage && (
                        <Button asChild>
                            <Link href="/catalog/categories/create">
                                <Plus className="size-4" /> New category
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
                    placeholder="Search category name"
                />

                <ResponsiveList
                    columns={columns}
                    rows={categories.data}
                    rowKey={(c) => c.id}
                    renderActions={canManage ? renderActions : undefined}
                    loading={search.loading}
                    emptyMessage="No categories found."
                />

                <PaginationBar meta={categories} onNavigate={search.goTo} noun="categories" />
            </div>

            <ConfirmDialog
                open={pendingDeactivate !== null}
                onOpenChange={(open) => {
                    if (!open && !processing) {
                        setPendingDeactivate(null);
                    }
                }}
                title="Deactivate category"
                description={`Deactivate ${pendingDeactivate?.name ?? ''}? It stays in the list but is marked Inactive, and new items cannot use it.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => pendingDeactivate && sendActive(pendingDeactivate, false)}
            />
        </>
    );
}

CategoriesIndex.layout = {
    breadcrumbs: [
        { title: 'Catalog', href: '/catalog/items' },
        { title: 'Categories', href: '/catalog/categories' },
    ],
};
