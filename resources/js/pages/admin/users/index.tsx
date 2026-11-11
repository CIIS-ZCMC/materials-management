import { Users } from 'lucide-react';
import { ComingSoon } from '@/components/coming-soon';
import { index } from '@/routes/admin/users';

export default function UsersIndex() {
    return (
        <ComingSoon
            title="Users"
            description="Creating users, deactivating them, and applying job presets will appear here."
            icon={Users}
            step="1.2"
        />
    );
}

UsersIndex.layout = {
    breadcrumbs: [{ title: 'Users', href: index() }],
};