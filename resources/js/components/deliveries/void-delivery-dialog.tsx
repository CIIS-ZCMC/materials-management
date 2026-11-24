import { useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';

type Props = { deliveryId: number; iarNumber: string };

// Voiding needs a written reason (5 to 500 characters). The IAR number stays recorded and is never reused.
export default function VoidDeliveryDialog({ deliveryId, iarNumber }: Props) {
    const [open, setOpen] = useState(false);
    const form = useForm({ reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();

        form.post(`/deliveries/${deliveryId}/void`, {
            preserveScroll: true,
            onSuccess: () => {
                setOpen(false);
                form.reset();
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);

                if (!next) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    Void delivery
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Void delivery {iarNumber}?</DialogTitle>
                        <DialogDescription>
                            Use this when the delivery was entered by mistake. IAR number {iarNumber} stays recorded and can never be used again.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-2">
                        <Label htmlFor="void_reason">Reason</Label>
                        <textarea
                            id="void_reason"
                            rows={3}
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(e) => form.setData('reason', e.target.value)}
                            className="border-input w-full rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                        />
                        <InputError message={errors.reason} />
                        <InputError message={errors.form} />
                        <InputError message={errors.iar_number} />
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Keep delivery
                        </Button>
                        <Button type="submit" variant="destructive" disabled={form.processing}>
                            Void delivery
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
