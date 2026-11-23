import { Printer } from 'lucide-react';
import { Button } from '@/components/ui/button';

/**
 * Print PO is switched off for now (2026-11-23): Procurement attaches its own PDF as the PO Bundle.
 * Change this to true to show the button again. The server side (route, service, tests) stays as it is.
 */
const PRINT_PO_ENABLED = false;

type Props = {
    poId: number;
    /** 'Manual' (made in the system) or 'BizBox'. A BizBox PO keeps its own document, so no button. */
    source: string;
};

/**
 * "Print PO" (step 4.6). It is a plain link, not an Inertia link: the server answers with a PDF, not a page.
 * target="_blank" opens the PDF in a new tab, where the browser's viewer has Print and Download.
 * Hiding the button for BizBox is for convenience; the server refuses a BizBox PO with a 403 anyway.
 */
export default function PoPrintButton({ poId, source }: Props) {
    if (!PRINT_PO_ENABLED || source !== 'Manual') {
        return null;
    }

    return (
        <Button asChild size="sm" variant="outline">
            <a href={`/purchase-orders/${poId}/print`} target="_blank" rel="noopener noreferrer">
                <Printer className="size-4" /> Print PO
            </a>
        </Button>
    );
}