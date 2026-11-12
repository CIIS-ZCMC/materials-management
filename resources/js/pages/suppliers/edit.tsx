import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import Heading from '@/components/heading';
import SupplierForm from '@/components/suppliers/supplier-form';
import type { EditableSupplier } from '@/components/suppliers/supplier-form';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
    supplier: EditableSupplier;
};

export default function EditSupplier({ supplier }: Props) {
    useFlashToast();

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const sendActive = (active: boolean) => {
        router.patch(
            `/suppliers/${supplier.id}/active`,
            { active },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Action failed.'),
                onFinish: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                },
            },
        );
    };

    return (
        <>
            <Head title={`Edit ${supplier.name}`} />

            <div className="max-w-4xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Heading title={supplier.name} description="Edit supplier" />
                        <Badge variant={supplier.is_active ? 'default' : 'outline'}>
                            {supplier.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </div>

                    <Button
                        type="button"
                        variant={supplier.is_active ? 'destructive' : 'default'}
                        onClick={() => (supplier.is_active ? setConfirmOpen(true) : sendActive(true))}
                    >
                        {supplier.is_active ? 'Deactivate' : 'Activate'}
                    </Button>
                </div>

                <SupplierForm mode="edit" supplier={supplier} />
            </div>

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={(open) => {
                    if (!processing) {
                        setConfirmOpen(open);
                    }
                }}
                title="Deactivate supplier"
                description={`Deactivate ${supplier.name}? It stays in the list but is marked Inactive.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => sendActive(false)}
            />
        </>
    );
}

EditSupplier.layout = {
    breadcrumbs: [
        { title: 'Suppliers', href: '/suppliers' },
        { title: 'Edit supplier', href: '/suppliers' },
    ],
};