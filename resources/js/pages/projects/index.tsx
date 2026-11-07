import { Head, Link, usePage } from '@inertiajs/react';
import { Pencil, Plus } from 'lucide-react';
import ProjectController from '@/actions/App/Http/Controllers/Projects/ProjectController';
import ConfirmDialog from '@/components/confirm-dialog';
import DeleteButton from '@/components/delete-button';
import Heading from '@/components/heading';
import ProjectNameDialog from '@/components/projects/project-name-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { runAction } from '@/lib/run-action';
import { dashboard } from '@/routes';
import type { ProjectUsage } from '@/types';

function providersIn(project: ProjectUsage): number {
    return (
        project.server_providers_count +
        project.dns_providers_count +
        project.storage_providers_count
    );
}

function ProjectRow({
    project,
    current,
}: {
    project: ProjectUsage;
    current: boolean;
}) {
    return (
        <TableRow>
            <TableCell className="pl-4">
                <div className="flex items-center gap-2">
                    <Link
                        href={dashboard({ project: project.id })}
                        className="font-medium hover:underline"
                    >
                        {project.name}
                    </Link>
                    {current && <Badge variant="secondary">Current</Badge>}
                </div>
            </TableCell>
            <TableCell className="tabular-nums">
                {project.servers_count}
            </TableCell>
            <TableCell className="tabular-nums">
                {project.databases_count}
            </TableCell>
            <TableCell className="hidden tabular-nums sm:table-cell">
                {project.networks_count}
            </TableCell>
            <TableCell className="hidden tabular-nums sm:table-cell">
                {providersIn(project)}
            </TableCell>
            <TableCell className="pr-4">
                <div className="flex justify-end gap-1">
                    <ProjectNameDialog
                        trigger={
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={`Rename ${project.name}`}
                            >
                                <Pencil />
                            </Button>
                        }
                        title={`Rename ${project.name}`}
                        description="Its URL stays the same."
                        submitLabel="Rename project"
                        form={ProjectController.update.form({
                            project: project.id,
                        })}
                        name={project.name}
                    />
                    <ConfirmDialog
                        trigger={
                            <DeleteButton
                                aria-label={`Delete ${project.name}`}
                            />
                        }
                        title={`Delete ${project.name}?`}
                        description="Only an empty project can be deleted. Move or remove its servers, databases, networks and providers first."
                        confirmLabel="Delete project"
                        destructive
                        onConfirm={() =>
                            runAction(
                                ProjectController.destroy({
                                    project: project.id,
                                }),
                                { success: `Deleted ${project.name}` },
                            )
                        }
                    />
                </div>
            </TableCell>
        </TableRow>
    );
}

export default function ProjectsIndex({
    projects,
}: {
    projects: ProjectUsage[];
}) {
    const { currentProject } = usePage().props;

    return (
        <>
            <Head title="Projects" />
            <div className="mx-auto w-full max-w-6xl space-y-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Projects"
                        description="Keep the servers, databases, networks and providers of each environment apart."
                    />
                    <ProjectNameDialog
                        trigger={
                            <Button>
                                <Plus /> New project
                            </Button>
                        }
                        title="New project"
                        description="A project groups servers, databases, networks and providers."
                        submitLabel="Create project"
                        form={ProjectController.store.form()}
                    />
                </div>

                <div className="overflow-hidden rounded-xl border bg-card">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-4">Name</TableHead>
                                <TableHead>Servers</TableHead>
                                <TableHead>Databases</TableHead>
                                <TableHead className="hidden sm:table-cell">
                                    Networks
                                </TableHead>
                                <TableHead className="hidden sm:table-cell">
                                    Providers
                                </TableHead>
                                <TableHead className="pr-4" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {projects.map((project) => (
                                <ProjectRow
                                    key={project.id}
                                    project={project}
                                    current={project.id === currentProject?.id}
                                />
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </>
    );
}

ProjectsIndex.layout = {
    breadcrumbs: [{ title: 'Projects', href: ProjectController.index() }],
};
