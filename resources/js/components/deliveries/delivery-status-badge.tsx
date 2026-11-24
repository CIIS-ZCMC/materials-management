import { Badge } from '@/components/ui/badge';

// Delivery status (stored): for_uploading, complete, voided. Unknown values still show their label.
export default function DeliveryStatusBadge({ status }: { status: { value: string; label: string } }) {
    const variant = status.value === 'voided' ? 'destructive' : status.value === 'complete' ? 'default' : 'outline';

    return <Badge variant={variant}>{status.label}</Badge>;
}
