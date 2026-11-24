import { Head, Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import DeliveryForm from '@/components/deliveries/delivery-form';
import type { DeliveryDetail, DeliveryFormOptions, PoSummary } from '@/components/deliveries/delivery-types';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';

type Props = { delivery: DeliveryDetail; purchaseOrder: PoSummary; options: DeliveryFormOptions };

export default function DeliveryEdit({ delivery, purchaseOrder, options }: Props) {
    return (
        <>
            <Head title={`Edit delivery ${delivery.iar_number}`} />

            <div className="space-y-4 p-4 md:space-y-6">
                <Button asChild variant="outline" size="sm">
                    <Link href={`/deliveries/${delivery.id}`}>
                        <ArrowLeft className="size-4" /> Back to delivery {delivery.iar_number}
                    </Link>
                </Button>

                <Heading title={`Edit delivery ${delivery.iar_number}`} description={`PO ${purchaseOrder.po_number}${purchaseOrder.supplier ? ` · ${purchaseOrder.supplier}` : ''}`} />

                <DeliveryForm mode="edit" purchaseOrder={purchaseOrder} options={options} delivery={delivery} />
            </div>
        </>
    );
}

DeliveryEdit.layout = {
    breadcrumbs: [
        { title: 'Deliveries', href: '/deliveries' },
        { title: 'Edit delivery', href: '#' },
    ],
};
