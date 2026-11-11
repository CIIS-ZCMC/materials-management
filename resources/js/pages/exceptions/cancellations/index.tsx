import { Ban } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/exceptions/cancellations';

export default function CancellationsIndex() {
    return (
        <ComingSoon
            title="Cancellations"
            description="Recommendations to terminate, Notices of Termination, and cancelled POs will appear here."
            icon={Ban}
            step="8.1"
        />
    );
}

CancellationsIndex.layout = {
    breadcrumbs: [{ title: 'Cancellations', href: index() }],
};