import { Head } from '@inertiajs/react';
import UnitForm from '@/components/catalog/unit-form';
import Heading from '@/components/heading';

export default function CreateUnit() {
    return (
        <>
            <Head title="New unit" />

            <div className="max-w-4xl space-y-6 p-4">
                <Heading title="New unit" description="Add a unit of measure" />
                <UnitForm mode="create" />
            </div>
        </>
    );
}

CreateUnit.layout = {
    breadcrumbs: [
        { title: 'Catalog', href: '/catalog/items' },
        { title: 'Units', href: '/catalog/units' },
        { title: 'New unit', href: '/catalog/units/create' },
    ],
};
