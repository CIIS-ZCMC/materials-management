import { Wallet } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/dv-finance';

export default function DvFinanceIndex() {
    return (
        <ComingSoon
            title="DV and Finance"
            description="The supplier checklist review, DV and IAR Transmittal, and Finance processing will appear here."
            icon={Wallet}
            step="7.1"
        />
    );
}

DvFinanceIndex.layout = {
    breadcrumbs: [{ title: 'DV and Finance', href: index() }],
};