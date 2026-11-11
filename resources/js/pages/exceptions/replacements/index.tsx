import { RefreshCw } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/exceptions/replacements';

export default function ReplacementsIndex() {
    return (
        <ComingSoon
            title="Replacements"
            description="Items for replacement, Pull-Out Forms, and replacements received will appear here."
            icon={RefreshCw}
            step="8.2"
        />
    );
}

ReplacementsIndex.layout = {
    breadcrumbs: [{ title: 'Replacements', href: index() }],
};