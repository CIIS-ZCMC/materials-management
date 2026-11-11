import { Head, Link, router, usePage } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/admin/confirm-dialog';
import { useFlashToast } from '@/components/admin/use-flash-toast';
import Heading from '@/components/heading';
import LiveSearchInput from '@/components/live-search-input';
import PaginationBar from '@/components/pagination-bar';
import type { PaginationMeta } from '@/components/pagination-bar';
import ResponsiveList from '@/components/responsive-list';
import type { ListColumn } from '@/components/responsive-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useLiveSearch } from '@/hooks/use-live-search';
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

type Paginated = PaginationMeta & { data: UserRow[] };

type Props = {
    users: Paginated;
    filters: { search: string };
};

export default function UsersIndex({ users, filters }: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;

    useFlashToast();

    // Live search: debounce, partial reload, cancel stale requests, URL keeps ?search=
    const search = useLiveSearch({
        url: '/admin/users',
        initial: filters.search ?? '',
        only: ['users', 'filters'],
    });

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

    const columns: ListColumn<UserRow>[] = [
        { key: 'employee_no', header: 'Employee No.', className: 'font-mono', cell: (u) => u.employee_no ?? '—' },
        { key: 'name', header: 'Name', card: 'title', cell: (u) => u.name },
        { key: 'position', header: 'Position', cell: (u) => u.position ?? '—' },
        { key: 'email', header: 'Email', className: 'break-all', cell: (u) => u.email },
        {
            key: 'role',
            header: 'Role',
            card: 'summary',
            cell: (u) => <Badge variant="secondary">{u.role ?? '—'}</Badge>,
        },
        {
            key: 'job',
            header: 'Job',
            card: 'summary',
            cell: (u) => u.job ?? '—',
            cardCell: (u) => u.job, // no job: nothing shown in the card
        },
        {
            key: 'status',
            header: 'Status',
            card: 'badge',
            cell: (u) => <Badge variant={u.is_active ? 'default' : 'outline'}>{u.is_active ? 'Active' : 'Inactive'}</Badge>,
        },
    ];

    const renderActions = (user: UserRow) => {
        const self = user.id === auth.user.id;

        return (
            <>
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
            </>
        );
    };

    return (
        <>
            <Head title="Users" />

            <div className="space-y-4 p-4 md:space-y-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading title="Users" description="Manage MMS accounts, roles, and permissions" />
                    <Button asChild>
                        <Link href="/admin/users/create">
                            <Plus className="size-4" /> New user
                        </Link>
                    </Button>
                </div>

                <LiveSearchInput
                    value={search.value}
                    onChange={search.setValue}
                    onClear={search.clear}
                    onSubmit={search.flush}
                    loading={search.loading}
                    placeholder="Search employee no., name, position, email"
                />

                <ResponsiveList
                    columns={columns}
                    rows={users.data}
                    rowKey={(u) => u.id}
                    renderActions={renderActions}
                    loading={search.loading}
                    emptyMessage="No users found."
                />

                <PaginationBar meta={users} onNavigate={search.goTo} noun="users" />
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