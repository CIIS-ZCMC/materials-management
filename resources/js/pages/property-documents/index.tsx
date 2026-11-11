import { Package } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/property-documents';

export default function PropertyDocumentsIndex() {
    return (
        <ComingSoon
            title="Property Documents"
            description="The equipment decision and the PAR, Sub-PAR, and ICS documents will appear here."
            icon={Package}
            step="6.2"
        />
    );
}

PropertyDocumentsIndex.layout = {
    breadcrumbs: [{ title: 'Property Documents', href: index() }],
};