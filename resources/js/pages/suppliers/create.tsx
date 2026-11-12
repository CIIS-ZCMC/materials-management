import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import SupplierForm from '@/components/suppliers/supplier-form';

export default function CreateSupplier() {
    return (
        <>
            <Head title="New supplier" />

            <div className="max-w-4xl space-y-6 p-4">
                <Heading title="New supplier" description="Add a supplier to the list" />
                <SupplierForm mode="create" />
            </div>
        </>
    );
}

CreateSupplier.layout = {
    breadcrumbs: [
        { title: 'Suppliers', href: '/suppliers' },
        { title: 'New supplier', href: '/suppliers/create' },
    ],
};