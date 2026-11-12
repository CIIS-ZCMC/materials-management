import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import UnitForm from '@/components/catalog/unit-form';
import type { EditableUnit } from '@/components/catalog/unit-form';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
    unit: EditableUnit;
};

export default function EditUnit({ unit }: Props) {
    useFlashToast();

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const sendActive = (active: boolean) => {
        router.patch(
            `/catalog/units/${unit.id}/active`,
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
            <Head title={`Edit ${unit.name}`} />

            <div className="max-w-4xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Heading title={unit.name} description="Edit unit" />
                        <Badge variant={unit.is_active ? 'default' : 'outline'}>
                            {unit.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </div>

                    <Button
                        type="button"
                        variant={unit.is_active ? 'destructive' : 'default'}
                        onClick={() => (unit.is_active ? setConfirmOpen(true) : sendActive(true))}
                    >
                        {unit.is_active ? 'Deactivate' : 'Activate'}
                    </Button>
                </div>

                <UnitForm mode="edit" unit={unit} />
            </div>

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={(open) => {
                    if (!processing) {
                        setConfirmOpen(open);
                    }
                }}
                title="Deactivate unit"
                description={`Deactivate ${unit.name}? It stays in the list but is marked Inactive, and new items cannot use it.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => sendActive(false)}
            />
        </>
    );
}

EditUnit.layout = {
    breadcrumbs: [
        { title: 'Catalog', href: '/catalog/items' },
        { title: 'Units', href: '/catalog/units' },
        { title: 'Edit unit', href: '/catalog/units' },
    ],
};
