import { useForm } from '@inertiajs/react';
import { CalendarPlus } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

type Props = {
    poId: number;
    poNumber: string;
    dueDate: string | null;
    counting: string; // 'working' or 'calendar', from config('mms.delivery.extension_counting')
    maxDays: number;
};

/**
 * The "Record extension" button plus its two modals:
 * 1. the form (days and reason), 2. a ConfirmDialog before anything is saved.
 * The server does the real checks (permission, PO status, due date); the checks here
 * only save a round trip.
 */
export default function RecordExtensionDialog({ poId, poNumber, dueDate, counting, maxDays }: Props) {
    // React state: is each modal open. Changing it re-draws the page, no reload.
    const [open, setOpen] = useState(false);
    const [confirmOpen, setConfirmOpen] = useState(false);

    // useForm keeps the two fields, the errors and the "sending" flag in one object.
    const form = useForm({ extra_days: '', reason: '' });
    const { errors } = form;
    const formError = Object.entries(errors).find(([key]) => key === 'form')?.[1];

    const unit = counting === 'working' ? 'working days' : 'calendar days';
    const days = Number(form.data.extra_days);

    function review(event: FormEvent) {
        event.preventDefault();
        form.clearErrors();

        if (!Number.isInteger(days) || days < 1 || days > maxDays) {
            form.setError('extra_days', `Enter a whole number from 1 to ${maxDays}.`);

            return;
        }

        if (form.data.reason.trim() === '') {
            form.setError('reason', 'Enter the reason for the extension.');

            return;
        }

        setConfirmOpen(true);
    }

    function save() {
        form.post(`/purchase-orders/${poId}/extensions`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
            onFinish: () => setConfirmOpen(false),
        });
    }

    return (
        <>
            <Button size="sm" onClick={() => setOpen(true)}>
                <CalendarPlus className="size-4" /> Record extension
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
                    <form onSubmit={review} className="space-y-4">
                        <DialogHeader>
                            <DialogTitle>Record extension for PO {poNumber}</DialogTitle>
                            <DialogDescription>
                                Current due date: {dueDate ?? '—'}. The days are added as {unit} and the new due date is saved with who recorded it.
                            </DialogDescription>
                        </DialogHeader>

                        {formError && <p className="rounded-md border border-destructive/40 bg-destructive/10 px-3 py-2 text-sm text-destructive">{formError}</p>}

                        <div className="grid gap-2">
                            <Label htmlFor="extra_days">Days to add ({unit})</Label>
                            <Input
                                id="extra_days"
                                type="number"
                                min={1}
                                max={maxDays}
                                step={1}
                                value={form.data.extra_days}
                                onChange={(e) => form.setData('extra_days', e.target.value)}
                            />
                            <InputError message={errors.extra_days} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="reason">Reason</Label>
                            <textarea
                                id="reason"
                                rows={3}
                                maxLength={500}
                                value={form.data.reason}
                                onChange={(e) => form.setData('reason', e.target.value)}
                                className="border-input placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:ring-[3px]"
                                placeholder="For example: supplier asked for more time, delay at the port"
                            />
                            <InputError message={errors.reason} />
                        </div>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                Review
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={setConfirmOpen}
                title={`Add ${days} ${unit} to PO ${poNumber}?`}
                description="The due date moves immediately and the extension is written to the history and the audit log. It cannot be undone."
                confirmLabel="Record extension"
                onConfirm={save}
            />
        </>
    );
}