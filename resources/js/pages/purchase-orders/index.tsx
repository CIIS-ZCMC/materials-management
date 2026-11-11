import { ClipboardList } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/purchase-orders';

export default function PurchaseOrdersIndex() {
    return (
        <ComingSoon
            title="Purchase Orders"
            description="The PO list, PO details, and verification will appear here."
            icon={ClipboardList}
            step="3.2"
        />
    );
}

PurchaseOrdersIndex.layout = {
    breadcrumbs: [{ title: 'Purchase Orders', href: index() }],
};