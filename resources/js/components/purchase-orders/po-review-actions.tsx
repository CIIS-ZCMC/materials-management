import { useForm } from '@inertiajs/react';
import { Check, Flag, Undo2 } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useId, useState } from 'react';
import type { FormEvent } from 'react';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

export type ReviewFlags = {
    can_verify: boolean;
    can_escalate: boolean;
    can_send_back: boolean;
};

type Props = {
    poId: number;
    poNumber: string;
    dueDate: string | null;
    review: ReviewFlags;
};

type ReasonDialogProps = {
    url: string;
    icon: LucideIcon;
    triggerLabel: string;
    title: string;
    description: string;
    fieldLabel: string;
    placeholder: string;
    requiredMessage: string;
    submitLabel: string;
};

/**
 * A button that opens a modal with one required text box, then posts it as "reason".
 * Used for Escalate (remarks for the Admin) and Send back (note for the Reviewer).
 * The server does the real checks (permission, PO status); the empty-box check here only saves a round trip.
 */
function ReasonDialog({ url, icon: Icon, triggerLabel, title, description, fieldLabel, placeholder, requiredMessage, submitLabel }: ReasonDialogProps) {
    // React state: is the modal open. Changing it re-draws the page, no reload.
    const [open, setOpen] = useState(false);
    const fieldId = useId();

    // useForm keeps the text, the errors and the "sending" flag in one object.
    const form = useForm({ reason: '' });
    const { errors } = form;
    const formError = Object.entries(errors).find(([key]) => key === 'form')?.[1];

    function submit(event: FormEvent) {
        event.preventDefault();
        form.clearErrors();

        if (form.data.reason.trim() === '') {
            form.setError('reason', requiredMessage);

            return;
        }

        form.post(url, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    }

    return (
        <>
            <Button size="sm" variant="outline" onClick={() => setOpen(true)}>
                <Icon className="size-4" /> {triggerLabel}
            </Button>

            <Dialog
                open={open}
                onOpenChange={(next) => {
                    setOpen(next);

                    if (!next) {
                        form.clearErrors();
                    }
                }}
            >
                <DialogContent>
                    <form onSubmit={submit} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>{title}</DialogTitle>
                            <DialogDescription>{description}</DialogDescription>
                        </DialogHeader>

                        {formError && <p className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>}

                        <div className="grid gap-2">
                            <Label htmlFor={fieldId}>{fieldLabel}</Label>
                            <textarea
                                id={fieldId}
                                rows={4}
                                maxLength={500}
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                                className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                placeholder={placeholder}
                            />
                            <InputError message={errors.reason} />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {submitLabel}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}

/**
 * The review buttons on the PO detail page: Verify (confirm dialog), Escalate (modal with remarks)
 * and "Send back to Reviewer" (modal with a note). The server decides which of them are allowed
 * (`review` flags); a button the person may not use is simply not drawn.
 */
export default function PoReviewActions({ poId, poNumber, dueDate, review }: Props) {
    const [verifyOpen, setVerifyOpen] = useState(false);

    // No fields here, so useForm({}) is just a way to post and to get errors back.
    const verifyForm = useForm({});
    const verifyError = (Object.values(verifyForm.errors) as string[])[0];

    function verify() {
        verifyForm.post(`/purchase-orders/${poId}/verify`, {
            preserveScroll: true,
            onFinish: () => setVerifyOpen(false),
        });
    }

    // Verifying locks the details, so a PO without a due date can never get one afterwards.
    const verifyDescription = dueDate
        ? 'The PO moves to Verified. Its details can no longer be edited, and deliveries and extensions can be recorded. This cannot be undone.'
        : 'Warning: this PO has no due date (no emailed date or no delivery term). Once verified, its details can no longer be edited, so a due date cannot be added later and no extension can be recorded. The PO moves to Verified and this cannot be undone.';

    return (
        <>
            {review.can_verify && (
                <Button size="sm" onClick={() => setVerifyOpen(true)}>
                    <Check className="size-4" /> Verify
                </Button>
            )}

            {review.can_escalate && (
                <ReasonDialog
                    url={`/purchase-orders/${poId}/escalate`}
                    icon={Flag}
                    triggerLabel="Escalate"
                    title={`Escalate PO ${poNumber} to the Admin`}
                    description="Describe what is wrong with this PO. It moves to Escalated to Admin and cannot be reviewed again until the Admin fixes it and sends it back."
                    fieldLabel="Remarks for the Admin"
                    placeholder="For example: wrong supplier, item quantity does not match the PO"
                    requiredMessage="Enter the remarks for the Admin: what is wrong with this PO."
                    submitLabel="Escalate to Admin"
                />
            )}

            {review.can_send_back && (
                <ReasonDialog
                    url={`/purchase-orders/${poId}/send-back`}
                    icon={Undo2}
                    triggerLabel="Send back to Reviewer"
                    title={`Send PO ${poNumber} back to the Reviewer`}
                    description="Fix the details first (Edit). The PO returns to Uploaded so the PO Reviewer can review it again."
                    fieldLabel="Note for the Reviewer"
                    placeholder="For example: corrected the supplier and the quantity"
                    requiredMessage="Enter a note for the Reviewer: what did you fix?"
                    submitLabel="Send back"
                />
            )}

            {verifyError && <p className="basis-full text-right text-sm text-destructive">{verifyError}</p>}

            <ConfirmDialog
                open={verifyOpen}
                onOpenChange={setVerifyOpen}
                title={`Verify PO ${poNumber}?`}
                description={verifyDescription}
                confirmLabel="Verify"
                onConfirm={verify}
            />
        </>
    );
}