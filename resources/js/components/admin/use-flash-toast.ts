import { usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';

type FlashProps = {
    flash?: { success?: string | null };
};

/**
 * Shows `->with('success', ...)` messages as a toast.
 * Requires HandleInertiaRequests to share `flash.success` (see notes).
 */
export function useFlashToast() {
    const { flash } = usePage<FlashProps>().props;
    const message = flash?.success ?? null;

    useEffect(() => {
        if (message) {
            toast.success(message);
        }
    }, [message]);
}