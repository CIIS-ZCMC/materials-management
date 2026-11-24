import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import DeliveryForm from '@/components/deliveries/delivery-form';
import type { DeliveryFormOptions, PoSummary } from '@/components/deliveries/delivery-types';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';

type Props = { purchaseOrder: PoSummary; options: DeliveryFormOptions };

export default function DeliveryCreate({ purchaseOrder, options }: Props) {
    return (
        <>
            <Head title={`Record delivery for PO ${purchaseOrder.po_number}`} />

            <div className="space-y-4 p-4 md:space-y-6">
                <Button asChild variant="outline" size="sm">
                    <Link href={`/purchase-orders/${purchaseOrder.id}`}>
                        <ArrowLeft className="size-4" /> Back to PO {purchaseOrder.po_number}
                    </Link>
                </Button>

                <Heading title="Record delivery" description={`PO ${purchaseOrder.po_number}${purchaseOrder.supplier ? ` · ${purchaseOrder.supplier}` : ''}`} />

                <DeliveryForm mode="create" purchaseOrder={purchaseOrder} options={options} />
            </div>
        </>
    );
}

DeliveryCreate.layout = {
    breadcrumbs: [
        { title: 'Deliveries', href: '/deliveries' },
        { title: 'Record delivery', href: '#' },
    ],
};
