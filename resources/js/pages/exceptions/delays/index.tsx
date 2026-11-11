import { Clock } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/exceptions/delays';

export default function DelaysIndex() {
    return (
        <ComingSoon
            title="Delays"
            description="The day count, overdue indicator, and grace period for late deliveries will appear here."
            icon={Clock}
            step="8.3"
        />
    );
}

DelaysIndex.layout = {
    breadcrumbs: [{ title: 'Delays', href: index() }],
};