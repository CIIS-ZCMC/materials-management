import { Link } from '@inertiajs/react';
import {
    Ban,
    Building2,
    ClipboardList,
    Clock,
    FileText,
    LayoutGrid,
    Package,
    RefreshCw,
    ScrollText,
    Settings,
    Truck,
    Users,
    Wallet,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useAccess } from '@/hooks/use-access';
import { dashboard } from '@/routes';
import { index as deliveries } from '@/routes/deliveries';
import { index as dvFinance } from '@/routes/dv-finance';
import { index as auditLog } from '@/routes/admin/audit-log';
import { index as users } from '@/routes/admin/users';
import { index as cancellations } from '@/routes/exceptions/cancellations';
import { index as delays } from '@/routes/exceptions/delays';
import { index as replacements } from '@/routes/exceptions/replacements';
import { index as propertyDocuments } from '@/routes/property-documents';
import { edit } from '@/routes/profile';
import { index as purchaseOrders } from '@/routes/purchase-orders';
import { index as reports } from '@/routes/reports';
import { index as suppliers } from '@/routes/suppliers';
import type { NavItem } from '@/types';

// A menu item plus the section that unlocks it (see "sections" in
// config/mms_access.php). No section means everyone may see it.
type MmsNavItem = NavItem & { section?: string };

const mainNavItems: MmsNavItem[] = [
    { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
    { title: 'Purchase Orders', href: purchaseOrders(), icon: ClipboardList, section: 'purchase-orders' },
    { title: 'Suppliers', href: suppliers(), icon: Building2, section: 'suppliers' },
    { title: 'Deliveries', href: deliveries(), icon: Truck, section: 'deliveries' },
    { title: 'Property Documents', href: propertyDocuments(), icon: Package, section: 'property-documents' },
    { title: 'DV and Finance', href: dvFinance(), icon: Wallet, section: 'dv-finance' },
    { title: 'Reports', href: reports(), icon: FileText, section: 'reports' },
];

const exceptionNavItems: MmsNavItem[] = [
    { title: 'Cancellations', href: cancellations(), icon: Ban, section: 'cancellations' },
    { title: 'Replacements', href: replacements(), icon: RefreshCw, section: 'replacements' },
    { title: 'Delays', href: delays(), icon: Clock, section: 'delays' },
];

const adminNavItems: MmsNavItem[] = [
    { title: 'Users', href: users(), icon: Users, section: 'users' },
    { title: 'Audit Log', href: auditLog(), icon: ScrollText, section: 'audit-log' },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Settings',
        href: edit(),
        icon: Settings,
    },
];

export function AppSidebar() {
    const { canAccess } = useAccess();

    // Keep only the items this user may open.
    const visible = (items: MmsNavItem[]) =>
        items.filter((item) => !item.section || canAccess(item.section));

    const main = visible(mainNavItems);
    const exceptions = visible(exceptionNavItems);
    const admin = visible(adminNavItems);

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader className="pt-3 pb-2">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            size="lg"
                            asChild
                            className="hover:bg-sidebar-accent/60"
                        >
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <div className="mx-2 h-px bg-sidebar-border/60" />

            <SidebarContent>
                <NavMain items={main} label="Main Menu" />
                {exceptions.length > 0 && (
                    <NavMain items={exceptions} label="Exceptions" />
                )}
                {admin.length > 0 && <NavMain items={admin} label="Admin" />}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto p-0" />
                <div className="h-px bg-sidebar-border/60" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}