import { Badge } from '@/components/ui/badge';

type Props = {
    status: { value: string; label: string };
};

type Variant = 'default' | 'secondary' | 'destructive' | 'outline';

// Colour by status value. The text is always the stored status label from the server.
const VARIANTS: Record<string, Variant> = {
    draft: 'outline',
    uploaded: 'secondary',
    escalated: 'destructive',
    verified: 'default',
    for_termination_approval: 'secondary',
    for_termination: 'destructive',
    cancelled: 'outline',
    terminated: 'destructive',
};

export default function PoStatusBadge({ status }: Props) {
    return <Badge variant={VARIANTS[status.value] ?? 'outline'}>{status.label}</Badge>;
}