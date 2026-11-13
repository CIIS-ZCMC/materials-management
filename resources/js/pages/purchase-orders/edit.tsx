import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import PoForm from '@/components/purchase-orders/po-form';
import type { DueDateRules, EditablePo, SupplierOption } from '@/components/purchase-orders/po-form';

type Props = {
    purchaseOrder: EditablePo;
    supplierOptions: SupplierOption[];
    dueDateRules: DueDateRules;
};

export default function PurchaseOrderEdit({ purchaseOrder, supplierOptions, dueDateRules }: Props) {
    return (
        <>
            <Head title={`Edit PO ${purchaseOrder.po_number}`} />

            <div className="space-y-4 p-4 md:space-y-6">
                <Heading title={`Edit ${purchaseOrder.po_number}`} description="Change the details, items and delivery dates" />
                <PoForm mode="edit" purchaseOrder={purchaseOrder} supplierOptions={supplierOptions} dueDateRules={dueDateRules} />
            </div>
        </>
    );
}

PurchaseOrderEdit.layout = {
    breadcrumbs: [
        { title: 'Purchase Orders', href: '/purchase-orders' },
        { title: 'Edit', href: '#' },
    ],
};