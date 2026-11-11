import { Head, router, useForm, usePage } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import type { AccessOptions, EditableUser } from '@/components/admin/user-form';
import UserForm from '@/components/admin/user-form';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Auth } from '@/types';

type Props = {
    user: EditableUser;
    access: AccessOptions;
    canManageAccess: boolean;
};

function ResetPasswordDialog({ user }: { user: EditableUser }) {
    const [open, setOpen] = useState(false);
    const [show, setShow] = useState(false);
    const form = useForm({ password: '' });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(`/admin/users/${user.id}/password`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);

                if (!value) {
                    form.reset();
                    form.clearErrors();
                }
            }}
        >
            <DialogTrigger asChild>
                <Button type="button" variant="outline">
                    Reset password
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-4">
                    <DialogHeader>
                        <DialogTitle>Reset password</DialogTitle>
                        <DialogDescription>
                            Type a new password for {user.name}. Tell them in person; no email is sent.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="new-password">New password</Label>
                        <div className="relative">
                            <Input
                                id="new-password"
                                type={show ? 'text' : 'password'}
                                value={form.data.password}
                                onChange={(e) => form.setData('password', e.target.value)}
                                autoComplete="new-password"
                                className="pr-10"
                                required
                            />
                            <button
                                type="button"
                                onClick={() => setShow((v) => !v)}
                                className="absolute inset-y-0 right-0 flex items-center px-3 text-muted-foreground"
                                aria-label={show ? 'Hide password' : 'Show password'}
                            >
                                {show ? <EyeOff className="size-4" /> : <Eye className="size-4" />}
                            </button>
                        </div>
                        <InputError message={form.errors.password} />
                    </div>

                    <DialogFooter>
                        <Button type="submit" disabled={form.processing}>
                            Set password
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function EditUser({ user, access, canManageAccess }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const self = auth.user.id === user.id;

    useFlashToast();

    const [confirmOpen, setConfirmOpen] = useState(false);
    const [processing, setProcessing] = useState(false);

    const sendActive = (active: boolean) => {
        router.patch(
            `/admin/users/${user.id}/active`,
            { active },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Action failed.'),
                onFinish: () => {
                    setProcessing(false);
                    setConfirmOpen(false);
                },
            },
        );
    };

    return (
        <>
            <Head title={`Edit ${user.name}`} />

            <div className="max-w-4xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Heading title={user.name} description={`Employee No. ${user.employee_no ?? '—'}`} />
                        <Badge variant={user.is_active ? 'default' : 'outline'}>
                            {user.is_active ? 'Active' : 'Inactive'}
                        </Badge>
                    </div>

                    <div className="flex gap-2">
                        <ResetPasswordDialog user={user} />
                        <Button
                            type="button"
                            variant={user.is_active ? 'destructive' : 'default'}
                            disabled={self && user.is_active}
                            title={self ? 'You cannot deactivate yourself' : undefined}
                            onClick={() => (user.is_active ? setConfirmOpen(true) : sendActive(true))}
                        >
                            {user.is_active ? 'Deactivate' : 'Activate'}
                        </Button>
                    </div>
                </div>

                <UserForm mode="edit" user={user} access={access} canManageAccess={canManageAccess} />
            </div>

            <ConfirmDialog
                open={confirmOpen}
                onOpenChange={(open) => {
                    if (!processing) {
                        setConfirmOpen(open);
                    }
                }}
                title="Deactivate user"
                description={`Deactivate ${user.name}? They will no longer be able to log in.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => sendActive(false)}
            />
        </>
    );
}

EditUser.layout = {
    breadcrumbs: [
        { title: 'Users', href: '/admin/users' },
        { title: 'Edit user', href: '/admin/users' },
    ],
};