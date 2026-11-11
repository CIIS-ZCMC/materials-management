import { Building2 } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/suppliers';

export default function SuppliersIndex() {
    return (
        <ComingSoon
            title="Suppliers"
            description="The supplier list (name and optional email) maintained by Procurement will appear here."
            icon={Building2}
            step="2.1"
        />
    );
}

SuppliersIndex.layout = {
    breadcrumbs: [{ title: 'Suppliers', href: index() }],
};