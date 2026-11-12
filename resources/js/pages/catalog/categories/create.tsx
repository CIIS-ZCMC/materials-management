import { Head } from '@inertiajs/react';
import CategoryForm from '@/components/catalog/category-form';
import Heading from '@/components/heading';

export default function CreateCategory() {
    return (
        <>
            <Head title="New category" />

            <div className="max-w-4xl space-y-6 p-4">
                <Heading title="New category" description="Add an item category" />
                <CategoryForm mode="create" />
            </div>
        </>
    );
}

CreateCategory.layout = {
    breadcrumbs: [
        { title: 'Catalog', href: '/catalog/items' },
        { title: 'Categories', href: '/catalog/categories' },
        { title: 'New category', href: '/catalog/categories/create' },
    ],
};
