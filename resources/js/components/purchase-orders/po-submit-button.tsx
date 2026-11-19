import { router } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { useState } from 'react';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import type { ConsolidatedInfo } from '@/components/purchase-orders/po-documents-panel';
import { Button } from '@/components/ui/button';

type Props = {
    poId: number;
    // documents.can_submit from the server: permission AND the PO is a draft AND it has at least one active document.
    canSubmit: boolean;
    consolidated: ConsolidatedInfo;
};

// The "Submit to Reviewer" button of the PO page header (shown on every tab). It asks first in the project's
// confirmation modal, then calls the same submit route the Documents tab used before. Hiding the button is for
// convenience; the policy and PoDocumentRule on the server stay the real protection.
export default function PoSubmitButton({ poId, canSubmit, consolidated }: Props) {
    // React state: "open" = the confirmation modal, "busy" = the request is running, "error" = the server's message.
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    if (!canSubmit) {
        return null;
    }

    const pagesText =
        consolidated.exists && consolidated.pages !== null
            ? `The combined PDF has ${consolidated.pages} ${consolidated.pages === 1 ? 'page' : 'pages'}.`
            : 'The combined PDF is not built yet.';
    const staleText = consolidated.stale ? ' It is out of date (the last rebuild failed), so that count is from the previous version.' : '';

    function submit() {
        setOpen(false);

        router.post(
            `/purchase-orders/${poId}/documents/submit`,
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    setBusy(true);
                    setError(null);
                },
                onError: (errors: Record<string, string>) => setError(errors.form ?? 'The PO could not be submitted.'),
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <>
            <Button type="button" size="sm" disabled={busy} onClick={() => setOpen(true)}>
                <Send className="size-4" /> {busy ? 'Submitting...' : 'Submit to Reviewer'}
            </Button>

            {/* basis-full: the message takes its own line under the header buttons */}
            {error && <p className="basis-full text-right text-sm text-destructive">{error}</p>}

            <ConfirmDialog
                open={open}
                onOpenChange={setOpen}
                title="Submit this PO to the PO Reviewer?"
                description={`${pagesText}${staleText} Check the combined PDF on the Documents tab first. After you submit, the PO becomes Uploaded and a file can no longer be removed (a wrong document is voided with a reason).`}
                confirmLabel="Submit to Reviewer"
                onConfirm={submit}
            />
        </>
    );
}