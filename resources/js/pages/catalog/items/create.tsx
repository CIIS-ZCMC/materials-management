import { Head } from '@inertiajs/react';
import ItemForm from '@/components/catalog/item-form';
import type { ItemFormOptions } from '@/components/catalog/item-form';
import Heading from '@/components/heading';

type Props = {
    options: ItemFormOptions;
};

export default function CreateItem({ options }: Props) {
    return (
        <>
            <Head title="New item" />

            <div className="max-w-4xl space-y-6 p-4">
                <Heading title="New item" description="Add an item to the catalog" />
                <ItemForm mode="create" options={options} />
            </div>
        </>
    );
}

CreateItem.layout = {
    breadcrumbs: [
        { title: 'Catalog', href: '/catalog/items' },
        { title: 'Items', href: '/catalog/items' },
        { title: 'New item', href: '/catalog/items/create' },
    ],
};
