import { ScrollText } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/admin/audit-log';

export default function AuditLogIndex() {
    return (
        <ComingSoon
            title="Audit Log"
            description="The record of who did what and when will appear here."
            icon={ScrollText}
            step="1.3"
        />
    );
}

AuditLogIndex.layout = {
    breadcrumbs: [{ title: 'Audit Log', href: index() }],
};