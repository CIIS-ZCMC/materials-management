import PoStatusBadge from '@/components/purchase-orders/po-status-badge';
import { Badge } from '@/components/ui/badge';

type Status = { value: string; label: string };
type Progress = { value: string; label: string } | null | undefined;

// Step 6.1. A VERIFIED PO shows how much of it has arrived (not delivered / partially / fully delivered).
// That progress is worked out on the server from the delivered quantities every time; it is never saved.
// Every other status (draft, uploaded, escalated ...) shows the saved status as before.
export default function PoProgressBadge({ status, progress }: { status: Status; progress?: Progress }) {
    if (status.value !== 'verified' || !progress) {
        return <PoStatusBadge status={status} />;
    }

    const variant = progress.value === 'full' ? 'default' : progress.value === 'partial' ? 'secondary' : 'outline';

    return <Badge variant={variant}>{progress.label}</Badge>;
}