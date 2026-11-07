import { Link, router, usePage } from '@inertiajs/react';
import { Check, ChevronsUpDown, Plus, Settings2 } from 'lucide-react';
import { useRef, useState } from 'react';
import type { KeyboardEvent } from 'react';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import ProjectNameDialog from '@/components/projects/project-name-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useIsMobile } from '@/hooks/use-mobile';
import { switchUrlFor } from '@/lib/project-switch-url';
import { cn } from '@/lib/utils';
import { index as projectsIndex } from '@/routes/projects';
import type { Project } from '@/types';

function ProjectAvatar({
    project,
    className,
}: {
    project: Project;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'flex aspect-square shrink-0 items-center justify-center rounded-lg bg-neutral-200 font-semibold text-black dark:bg-neutral-700 dark:text-white',
                className,
            )}
        >
            {project.name.charAt(0).toUpperCase()}
        </span>
    );
}

function matchesQuery(project: Project, query: string): boolean {
    return project.name.toLowerCase().includes(query.trim().toLowerCase());
}

export function ProjectSwitcher() {
    const { currentProject, projects } = usePage().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);
    const [creating, setCreating] = useState(false);
    const [query, setQuery] = useState('');
    const searchRef = useRef<HTMLInputElement>(null);
    const listRef = useRef<HTMLDivElement>(null);

    if (currentProject === null) {
        return null;
    }

    const matches = projects.filter((project) => matchesQuery(project, query));

    const switchTo = (project: Project) => {
        if (project.id === currentProject.id) {
            return;
        }

        router.visit(switchUrlFor(window.location.pathname, project.id));
    };

    const onOpenChange = (next: boolean) => {
        setOpen(next);
        setQuery('');

        if (next) {
            requestAnimationFrame(() => searchRef.current?.focus());
        }
    };

    const onSearchKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
        event.stopPropagation();

        if (event.key !== 'ArrowDown') {
            return;
        }

        event.preventDefault();
        listRef.current
            ?.querySelector<HTMLElement>('[role="menuitem"]')
            ?.focus();
    };

    return (
        <>
            <SidebarMenu>
                <SidebarMenuItem>
                    <DropdownMenu open={open} onOpenChange={onOpenChange}>
                        <DropdownMenuTrigger asChild>
                            <SidebarMenuButton
                                size="lg"
                                tooltip={{ children: currentProject.name }}
                                data-test="project-switcher-trigger"
                                className="data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground"
                            >
                                <ProjectAvatar
                                    project={currentProject}
                                    className="size-8 text-sm"
                                />
                                <div className="grid flex-1 text-left text-sm leading-tight">
                                    <span className="truncate font-semibold">
                                        {currentProject.name}
                                    </span>
                                    <span className="truncate text-xs text-muted-foreground">
                                        Project
                                    </span>
                                </div>
                                <ChevronsUpDown className="ml-auto" />
                            </SidebarMenuButton>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            className="w-(--radix-dropdown-menu-trigger-width) min-w-64 rounded-lg"
                            side={
                                !isMobile && state === 'collapsed'
                                    ? 'right'
                                    : 'bottom'
                            }
                            align="start"
                            sideOffset={4}
                        >
                            <div className="p-1">
                                <Input
                                    ref={searchRef}
                                    value={query}
                                    onChange={(event) =>
                                        setQuery(event.target.value)
                                    }
                                    onKeyDown={onSearchKeyDown}
                                    placeholder="Search projects…"
                                    aria-label="Search projects"
                                    data-test="project-switcher-search"
                                    className="h-8"
                                />
                            </div>
                            <DropdownMenuLabel className="text-xs text-muted-foreground">
                                Projects
                            </DropdownMenuLabel>
                            <div
                                ref={listRef}
                                className="max-h-72 overflow-y-auto"
                            >
                                {matches.length === 0 ? (
                                    <p className="px-2 py-6 text-center text-sm text-muted-foreground">
                                        No projects found
                                    </p>
                                ) : (
                                    matches.map((project) => (
                                        <DropdownMenuItem
                                            key={project.id}
                                            data-test="project-switcher-item"
                                            className="cursor-pointer gap-2 p-2"
                                            onSelect={() => switchTo(project)}
                                        >
                                            <ProjectAvatar
                                                project={project}
                                                className="size-6 text-xs"
                                            />
                                            <span className="truncate">
                                                {project.name}
                                            </span>
                                            {currentProject.id ===
                                                project.id && (
                                                <Check className="ml-auto size-4" />
                                            )}
                                        </DropdownMenuItem>
                                    ))
                                )}
                            </div>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                data-test="project-switcher-create"
                                className="cursor-pointer gap-2 p-2"
                                onSelect={() => setCreating(true)}
                            >
                                <Plus className="size-4" />
                                Create project
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                asChild
                                data-test="project-switcher-manage"
                                className="cursor-pointer gap-2 p-2"
                            >
                                <Link href={projectsIndex()}>
                                    <Settings2 className="size-4" />
                                    Manage projects
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </SidebarMenuItem>
            </SidebarMenu>
            <ProjectNameDialog
                open={creating}
                onOpenChange={setCreating}
                title="New project"
                description="A project groups servers, databases, networks and providers."
                submitLabel="Create project"
                form={ProjectController.store.form()}
            />
        </>
    );
}
