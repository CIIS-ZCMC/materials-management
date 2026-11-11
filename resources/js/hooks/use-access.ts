import { usePage } from '@inertiajs/react';

// The data Laravel shares on every page (see HandleInertiaRequests.php).
type AccessProps = {
    auth: {
        permissions: string[];
        sections: string[];
    };
};

/**
 * Lets any component ask what the logged-in user may do.
 *   const { can, canAccess } = useAccess();
 *   can('po.review')           // a single permission
 *   canAccess('deliveries')    // a menu section
 * Hiding things is for convenience only. The server still enforces access.
 */
export function useAccess() {
    const { auth } = usePage<AccessProps>().props;

    const permissions = auth.permissions ?? [];
    const sections = auth.sections ?? [];

    return {
        can: (permission: string) => permissions.includes(permission),
        canAccess: (section: string) => sections.includes(section),
    };
}