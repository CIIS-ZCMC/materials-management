import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import PoForm from '@/components/purchase-orders/po-form';
import type { DueDateRules, SupplierOption } from '@/components/purchase-orders/po-form';

type Props = {
    supplierOptions: SupplierOption[];
    dueDateRules: DueDateRules;
};

export default function PurchaseOrderCreate({ supplierOptions, dueDateRules }: Props) {
    return (
        <>
            <Head title="New purchase order" />

            <div className="space-y-4 p-4 md:space-y-6">
                <Heading title="New purchase order" description="Create a PO in the system with its items and delivery dates" />
                <PoForm mode="create" supplierOptions={supplierOptions} dueDateRules={dueDateRules} />
            </div>
        </>
    );
}

PurchaseOrderCreate.layout = {
    breadcrumbs: [
        { title: 'Purchase Orders', href: '/purchase-orders' },
        { title: 'New', href: '/purchase-orders/create' },
    ],
};