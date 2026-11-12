import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import ItemForm from '@/components/catalog/item-form';
import type { EditableItem, ItemFormOptions } from '@/components/catalog/item-form';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
    item: EditableItem;
    options: ItemFormOptions;
};

export default function EditItem({ item, options }: Props) {
    useFlashToast();

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const sendActive = (active: boolean) => {
        router.patch(
            `/catalog/items/${item.id}/active`,
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
            <Head title={`Edit ${item.name}`} />

            <div className="max-w-4xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Heading title={item.name} description="Edit item" />
                        <Badge variant={item.is_active ? 'default' : 'outline'}>
                            {item.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </div>

                    <Button
                        type="button"
                        variant={item.is_active ? 'destructive' : 'default'}
                        onClick={() => (item.is_active ? setConfirmOpen(true) : sendActive(true))}
                    >
                        {item.is_active ? 'Deactivate' : 'Activate'}
                    </Button>
                </div>

                <ItemForm mode="edit" item={item} options={options} />
            </div>

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={(open) => {
                    if (!processing) {
                        setConfirmOpen(open);
                    }
                }}
                title="Deactivate item"
                description={`Deactivate ${item.name}? It stays in the list but is marked Inactive.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => sendActive(false)}
            />
        </>
    );
}

EditItem.layout = {
    breadcrumbs: [
        { title: 'Catalog', href: '/catalog/items' },
        { title: 'Items', href: '/catalog/items' },
        { title: 'Edit item', href: '/catalog/items' },
    ],
};
