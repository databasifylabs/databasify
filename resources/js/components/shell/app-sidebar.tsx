import { Link, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Cloud,
    DatabaseIcon,
    FolderGit2,
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
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useRepositoryStars } from '@/hooks/use-repository-stars';
import { documentationUrl, repositoryUrl } from '@/lib/repository';
import { dashboard } from '@/routes';
import { index as databasesIndex } from '@/routes/databases';
import { index as dnsIndex } from '@/routes/dns';
import { index as pluginsIndex } from '@/routes/plugins';
import { index as providersIndex } from '@/routes/providers';
import { index as serversIndex } from '@/routes/servers';
import { index as storageIndex } from '@/routes/storage-providers';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Databases',
        href: databasesIndex(),
        icon: DatabaseIcon,
    },
    {
        title: 'Servers',
        href: serversIndex(),
        icon: Server,
    },
    {
        title: 'Providers',
        href: providersIndex(),
        icon: Cloud,
    },
    {
        title: 'DNS',
        href: dnsIndex(),
        icon: Globe,
    },
    {
        title: 'Storage',
        href: storageIndex(),
        icon: HardDrive,
    },
    {
        title: 'Plugins',
        href: pluginsIndex(),
        icon: Puzzle,
    },
];

export function AppSidebar() {
    const { horizonUrl } = usePage().props;
    const stars = useRepositoryStars();
    const footerNavItems: NavItem[] = [
        {
            title: 'Repository',
            href: repositoryUrl,
            icon: FolderGit2,
            badge: stars,
        },
        {
            title: 'Documentation',
            href: documentationUrl,
            icon: BookOpen,
        },
    ];
    const navItems: NavItem[] = horizonUrl
        ? [
              ...mainNavItems,
              {
                  title: 'Horizon',
                  href: horizonUrl,
                  icon: ListEnd,
                  external: true,
              },
          ]
        : mainNavItems;

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
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
