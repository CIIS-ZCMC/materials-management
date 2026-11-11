import { Truck } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/deliveries';

export default function DeliveriesIndex() {
    return (
        <ComingSoon
            title="Deliveries"
            description="Delivery records, IAR details, DR/OR/IAR uploads, and delivered-item updates will appear here."
            icon={Truck}
            step="5.2"
        />
    );
}

DeliveriesIndex.layout = {
    breadcrumbs: [{ title: 'Deliveries', href: index() }],
};