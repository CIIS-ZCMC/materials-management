import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Props = {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description: string;
    confirmLabel: string;
    destructive?: boolean;
    processing?: boolean;
    onConfirm: () => void;
};

export default function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    destructive = false,
    processing = false,
    onConfirm,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                        disabled={processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        variant={destructive ? 'destructive' : 'default'}
                        onClick={onConfirm}
                        disabled={processing}
                    >
                        {confirmLabel}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}