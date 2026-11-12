import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import CategoryForm from '@/components/catalog/category-form';
import type { EditableCategory } from '@/components/catalog/category-form';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

type Props = {
    category: EditableCategory;
};

export default function EditCategory({ category }: Props) {
    useFlashToast();

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const sendActive = (active: boolean) => {
        router.patch(
            `/catalog/categories/${category.id}/active`,
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
            <Head title={`Edit ${category.name}`} />

            <div className="max-w-4xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Heading title={category.name} description="Edit category" />
                        <Badge variant={category.is_active ? 'default' : 'outline'}>
                            {category.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </div>

                    <Button
                        type="button"
                        variant={category.is_active ? 'destructive' : 'default'}
                        onClick={() => (category.is_active ? setConfirmOpen(true) : sendActive(true))}
                    >
                        {category.is_active ? 'Deactivate' : 'Activate'}
                    </Button>
                </div>

                <CategoryForm mode="edit" category={category} />
            </div>

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={(open) => {
                    if (!processing) {
                        setConfirmOpen(open);
                    }
                }}
                title="Deactivate category"
                description={`Deactivate ${category.name}? It stays in the list but is marked Inactive, and new items cannot use it.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => sendActive(false)}
            />
        </>
    );
}

EditCategory.layout = {
    breadcrumbs: [
        { title: 'Catalog', href: '/catalog/items' },
        { title: 'Categories', href: '/catalog/categories' },
        { title: 'Edit category', href: '/catalog/categories' },
    ],
};
