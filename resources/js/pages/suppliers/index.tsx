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

type SupplierRow = {
    id: number;
    name: string;
    email: string | null;
    contact_person: string | null;
    address: string | null;
    is_active: boolean;
};

type Paginated = PaginationMeta & { data: SupplierRow[] };

type Props = {
    suppliers: Paginated;
    filters: { search: string };
    canManage: boolean;
};

export default function SuppliersIndex({ suppliers, filters, canManage }: Props) {
    useFlashToast();

    const search = useLiveSearch({
        url: '/suppliers',
        initial: filters.search ?? '',
        only: ['suppliers', 'filters'],
    });

    const [pendingDeactivate, setPendingDeactivate] = useState<SupplierRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const sendActive = (supplier: SupplierRow, active: boolean) => {
        router.patch(
            `/suppliers/${supplier.id}/active`,
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

    const toggleActive = (supplier: SupplierRow) => {
        if (supplier.is_active) {
            setPendingDeactivate(supplier);
        } else {
            sendActive(supplier, true);
        }
    };

    const columns: ListColumn<SupplierRow>[] = [
        { key: 'name', header: 'Name', card: 'title', cell: (s) => s.name },
        { key: 'contact_person', header: 'Contact person', card: 'summary', cell: (s) => s.contact_person ?? '—' },
        { key: 'email', header: 'Email', className: 'break-all', cell: (s) => s.email ?? '—' },
        { key: 'address', header: 'Address', cell: (s) => s.address ?? '—' },
        {
            key: 'status',
            header: 'Status',
            card: 'badge',
            cell: (s) => <Badge variant={s.is_active ? 'default' : 'outline'}>{s.is_active ? 'Active' : 'Inactive'}</Badge>,
        },
    ];

    const renderActions = (supplier: SupplierRow) => (
        <>
            <Button asChild size="sm" variant="outline">
                <Link href={`/suppliers/${supplier.id}/edit`}>Edit</Link>
            </Button>
            <Button size="sm" variant="ghost" onClick={() => toggleActive(supplier)}>
                {supplier.is_active ? 'Deactivate' : 'Activate'}
            </Button>
        </>
    );

    return (
        <>
            <Head title="Suppliers" />

            <div className="space-y-4 p-4 md:space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        title="Suppliers"
                        description={canManage ? 'Add and maintain the supplier list' : 'Supplier list (view only)'}
                    />
                    {canManage && (
                        <Button asChild>
                            <Link href="/suppliers/create">
                                <Plus className="size-4" /> New supplier
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
                    placeholder="Search name, contact person, email"
                />

                <ResponsiveList
                    columns={columns}
                    rows={suppliers.data}
                    rowKey={(s) => s.id}
                    renderActions={canManage ? renderActions : undefined}
                    loading={search.loading}
                    emptyMessage="No suppliers found."
                />

                <PaginationBar meta={suppliers} onNavigate={search.goTo} noun="suppliers" />
            </div>

            <ConfirmDialog
                open={pendingDeactivate !== null}
                onOpenChange={(open) => {
                    if (!open && !processing) {
                        setPendingDeactivate(null);
                    }
                }}
                title="Deactivate supplier"
                description={`Deactivate ${pendingDeactivate?.name ?? ''}? It stays in the list but is marked Inactive.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => pendingDeactivate && sendActive(pendingDeactivate, false)}
            />
        </>
    );
}

SuppliersIndex.layout = {
    breadcrumbs: [{ title: 'Suppliers', href: '/suppliers' }],
};