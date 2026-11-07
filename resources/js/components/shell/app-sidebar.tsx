import { Link, usePage } from '@inertiajs/react';
import {
    Cloud,
    DatabaseIcon,
    FolderKanban,
    Globe,
    HardDrive,
    LayoutGrid,
    ListEnd,
    Puzzle,
    Server,
} from 'lucide-react';
import AppLogo from '@/components/shell/app-logo';
import { NavFooter } from '@/components/shell/nav-footer';
import { NavMain } from '@/components/shell/nav-main';
import { NavUser } from '@/components/shell/nav-user';
import { ProjectSwitcher } from '@/components/shell/project-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as databasesIndex } from '@/routes/databases';
import { index as dnsIndex } from '@/routes/dns';
import { index as pluginsIndex } from '@/routes/plugins';
import { index as projectsIndex } from '@/routes/projects';
import { index as providersIndex } from '@/routes/providers';
import { index as serversIndex } from '@/routes/servers';
import { index as storageIndex } from '@/routes/storage-providers';
import type { NavItem } from '@/types';

function projectNavItems(): NavItem[] {
    return [
        { title: 'Dashboard', href: dashboard(), icon: LayoutGrid },
        { title: 'Databases', href: databasesIndex(), icon: DatabaseIcon },
        { title: 'Servers', href: serversIndex(), icon: Server },
        { title: 'Providers', href: providersIndex(), icon: Cloud },
        { title: 'DNS', href: dnsIndex(), icon: Globe },
        { title: 'Storage', href: storageIndex(), icon: HardDrive },
    ];
}

function globalNavItems(): NavItem[] {
    return [
        { title: 'Projects', href: projectsIndex(), icon: FolderKanban },
        { title: 'Plugins', href: pluginsIndex(), icon: Puzzle },
    ];
}

export function AppSidebar() {
    const { horizonUrl, currentProject } = usePage().props;

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <ProjectSwitcher />
            </SidebarHeader>

            <SidebarContent>
                {currentProject && (
                    <NavMain label="Project" items={projectNavItems()} />
                )}
                <NavMain label="Global" items={globalNavItems()} />
            </SidebarContent>

            <SidebarFooter>
                {horizonUrl && (
                    <NavFooter
                        items={[
                            {
                                title: 'Horizon',
                                href: horizonUrl,
                                icon: ListEnd,
                                external: true,
                            },
                        ]}
                        className="mt-auto"
                    />
                )}
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
