import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { Auth } from '@/types';

type UserRow = {
    id: number;
    employee_no: string | null;
    name: string;
    position: string | null;
    email: string;
    role: string | null;
    job: string | null;
    is_active: boolean;
};

type Paginated = {
    data: UserRow[];
    current_page: number;
    last_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};

type Props = {
    users: Paginated;
    filters: { search: string };
};

export default function UsersIndex({ users, filters }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [search, setSearch] = useState(filters.search ?? '');

    useFlashToast();

    // Debounced server-side search.
    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timer = setTimeout(() => {
            router.get('/admin/users', { search }, { preserveState: true, replace: true });
        }, 300);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [search]);

    const [pendingDeactivate, setPendingDeactivate] = useState<UserRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const sendActive = (user: UserRow, active: boolean) => {
        router.patch(
            `/admin/users/${user.id}/active`,
            { active },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onError: (errors) => toast.error(Object.values(errors)[0] ?? 'Action failed.'),
                onFinish: () => {
                    setProcessing(false);
                    setPendingDeactivate(null);
                },
            },
        );
    };

    const toggleActive = (user: UserRow) => {
        if (user.is_active) {
            setPendingDeactivate(user);
        } else {
            sendActive(user, true);
        }
    };

    const go = (url: string | null) => {
        if (url) {
            router.get(url, {}, { preserveState: true });
        }
    };

    return (
        <>
            <Head title="Users" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading title="Users" description="Manage MMS accounts, roles, and permissions" />
                    <Button asChild>
                        <Link href="/admin/users/create">
                            <Plus className="size-4" /> New user
                        </Link>
                    </Button>
                </div>

                <div className="relative max-w-sm">
                    <Search className="absolute top-2.5 left-3 size-4 text-muted-foreground" />
                    <Input
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                        placeholder="Search employee no., name, position, email"
                        className="pl-9"
                    />
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/50 text-left">
                            <tr>
                                <th className="px-3 py-2 font-medium">Employee No.</th>
                                <th className="px-3 py-2 font-medium">Name</th>
                                <th className="px-3 py-2 font-medium">Position</th>
                                <th className="px-3 py-2 font-medium">Email</th>
                                <th className="px-3 py-2 font-medium">Role</th>
                                <th className="px-3 py-2 font-medium">Job</th>
                                <th className="px-3 py-2 font-medium">Status</th>
                                <th className="px-3 py-2" />
                            </tr>
                        </thead>
                        <tbody>
                            {users.data.length === 0 && (
                                <tr>
                                    <td colSpan={8} className="px-3 py-8 text-center text-muted-foreground">
                                        No users found.
                                    </td>
                                </tr>
                            )}
                            {users.data.map((user) => {
                                const self = user.id === auth.user.id;

                                return (
                                    <tr key={user.id} className="border-t">
                                        <td className="px-3 py-2 font-mono">{user.employee_no}</td>
                                        <td className="px-3 py-2">{user.name}</td>
                                        <td className="px-3 py-2">{user.position ?? '—'}</td>
                                        <td className="px-3 py-2">{user.email}</td>
                                        <td className="px-3 py-2">
                                            <Badge variant="secondary">{user.role ?? '—'}</Badge>
                                        </td>
                                        <td className="px-3 py-2">{user.job ?? '—'}</td>
                                        <td className="px-3 py-2">
                                            <Badge variant={user.is_active ? 'default' : 'outline'}>
                                                {user.is_active ? 'Active' : 'Inactive'}
                                            </Badge>
                                        </td>
                                        <td className="px-3 py-2">
                                            <div className="flex justify-end gap-2">
                                                <Button asChild size="sm" variant="outline">
                                                    <Link href={`/admin/users/${user.id}/edit`}>Edit</Link>
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    disabled={self && user.is_active}
                                                    title={self ? 'You cannot deactivate yourself' : undefined}
                                                    onClick={() => toggleActive(user)}
                                                >
                                                    {user.is_active ? 'Deactivate' : 'Activate'}
                                                </Button>
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    <span>
                        {users.total === 0
                            ? '0 users'
                            : `Showing ${users.from}–${users.to} of ${users.total}`}
                    </span>
                    <div className="flex items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!users.prev_page_url}
                            onClick={() => go(users.prev_page_url)}
                        >
                            Previous
                        </Button>
                        <span>
                            Page {users.current_page} of {users.last_page}
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={!users.next_page_url}
                            onClick={() => go(users.next_page_url)}
                        >
                            Next
                        </Button>
                    </div>
                </div>
            </div>

            <ConfirmDialog
                open={pendingDeactivate !== null}
                onOpenChange={(open) => {
                    if (!open && !processing) {
                        setPendingDeactivate(null);
                    }
                }}
                title="Deactivate user"
                description={`Deactivate ${pendingDeactivate?.name ?? ''}? They will no longer be able to log in.`}
                confirmLabel="Deactivate"
                destructive
                processing={processing}
                onConfirm={() => pendingDeactivate && sendActive(pendingDeactivate, false)}
            />
        </>
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Users', href: '/admin/users' }],
};