import { FileText } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/reports';

export default function ReportsIndex() {
    return (
        <ComingSoon
            title="Reports"
            description="The archive, search, and exports will appear here."
            icon={FileText}
            step="9.2"
        />
    );
}

ReportsIndex.layout = {
    breadcrumbs: [{ title: 'Reports', href: index() }],
};