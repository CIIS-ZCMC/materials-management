import { Head } from '@inertiajs/react';
import type { AccessOptions } from '@/components/admin/user-form';
import UserForm from '@/components/admin/user-form';
import Heading from '@/components/heading';

type Props = {
    access: AccessOptions;
    canManageAccess: boolean;
};

export default function CreateUser({ access, canManageAccess }: Props) {
    return (
        <>
            <Head title="New user" />

            <div className="max-w-4xl space-y-6 p-4">
                <Heading
                    title="New user"
                    description="Create an MMS account and set its role and permissions"
                />
                <UserForm mode="create" access={access} canManageAccess={canManageAccess} />
            </div>
        </>
    );
}

CreateUser.layout = {
    breadcrumbs: [
        { title: 'Users', href: '/admin/users' },
        { title: 'New user', href: '/admin/users/create' },
    ],
};