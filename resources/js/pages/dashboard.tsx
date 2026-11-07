import { Head, Link, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Activity as ActivityIcon,
    ArrowRight,
    Boxes,
    Check,
    CircleCheck,
    DatabaseIcon,
    DatabaseZap,
    Loader2,
    Plus,
    Server as ServerIcon,
} from 'lucide-react';
import { Fragment } from 'react';
import type { ReactNode } from 'react';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import ServerController from '@/actions/App/Http/Controllers/Servers/ServerController';
import {
    configurationOf,
    membersOf,
    versionOf,
} from '@/components/databases/describe';
import BrandIcon from '@/components/brand-icon';
import StarPromptCard from '@/components/dashboard/star-prompt-card';
import NodeDots from '@/components/databases/nodes/node-dots';
import ReadOnlyRows from '@/components/databases/nodes/read-only-rows';
import ReadOnlyToggle from '@/components/databases/nodes/read-only-toggle';
import Heading from '@/components/heading';
import ServerDatabase from '@/components/servers/server-database';
import ServerSpecs from '@/components/servers/server-specs';
import LiveDuration from '@/components/server-logs/live-duration';
import StatCard from '@/components/stat-card';
import StatusBadge from '@/components/status-badge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useExpandedDatabases } from '@/hooks/use-expanded-databases';
import { timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import type { DatabaseSummary, Server, ServerLog } from '@/types';

type Summary = {
    databases: { total: number; online: number };
    servers: { total: number; in_use: number; available: number };
    nodes: { total: number; read_only: number };
    running_logs: number;
};

type Props = {
    summary: Summary;
    databases: { data: DatabaseSummary[] };
    servers: { data: Server[] };
    logs: { data: ServerLog[] };
    requirements: Requirements;
    showStarPrompt: boolean;
};

type Requirements = {
    systems: string;
    engines: string;
};

function GettingStarted({
    hasServer,
    hasDatabase,
    requirements,
}: {
    hasServer: boolean;
    hasDatabase: boolean;
    requirements: Requirements;
}) {
    const steps = [
        {
            done: hasServer,
            title: 'Add a server',
            description: `${requirements.systems}, reachable over SSH.`,
            href: ServerController.create.url(),
            action: 'Add server',
        },
        {
            done: hasDatabase,
            title: 'Create a database',
            description: `${requirements.engines}, on one server or several.`,
            href: DatabaseController.create.url(),
            action: 'Create database',
        },
        {
            done: false,
            title: 'Connect your app',
            description: 'Copy the connection details or a Laravel .env.',
            href: DatabaseController.index.url(),
            action: 'View databases',
        },
    ];
    const next = steps.findIndex((step) => !step.done);

    return (
        <Card>
            <CardHeader>
                <CardTitle>Get started</CardTitle>
                <CardDescription>
                    Three steps from a bare server to a running database.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ol className="grid grid-cols-1 gap-3 md:grid-cols-3">
                    {steps.map((step, index) => (
                        <li
                            key={step.title}
                            className={cn(
                                'flex flex-col gap-3 rounded-xl border p-4',
                                index === next && 'border-foreground/30',
                            )}
                        >
                            <div className="flex items-center gap-3">
                                <span
                                    className={cn(
                                        'flex size-7 items-center justify-center rounded-full border text-sm font-semibold',
                                        (step.done || index === next) &&
                                            'border-primary bg-primary text-primary-foreground',
                                    )}
                                >
                                    {step.done ? (
                                        <Check className="size-4" />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                <span className="font-medium">
                                    {step.title}
                                </span>
                            </div>
                            <p className="flex-1 text-sm text-muted-foreground">
                                {step.description}
                            </p>
                            {!step.done && (
                                <Button
                                    asChild
                                    size="sm"
                                    variant={
                                        index === next ? 'default' : 'outline'
                                    }
                                    className="self-start"
                                >
                                    <Link href={step.href}>
                                        {step.action}
                                        <ArrowRight />
                                    </Link>
                                </Button>
                            )}
                        </li>
                    ))}
                </ol>
            </CardContent>
        </Card>
    );
}

function Panel({
    icon: Icon,
    title,
    count,
    href,
    className,
    children,
}: {
    icon: LucideIcon;
    title: string;
    count?: number;
    href?: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <Card className={cn('gap-0 overflow-hidden py-0', className)}>
            <CardHeader className="flex flex-row items-center justify-between gap-4 border-b py-4">
                <CardTitle className="flex items-center gap-2">
                    <Icon className="size-4 text-muted-foreground" />
                    {title}
                    {count !== undefined && count > 0 && (
                        <Badge variant="secondary">{count}</Badge>
                    )}
                </CardTitle>
                {href && (
                    <Link
                        href={href}
                        className="flex items-center gap-1 text-sm text-muted-foreground hover:text-foreground"
                    >
                        View all
                        <ArrowRight className="size-3.5" />
                    </Link>
                )}
            </CardHeader>
            {children}
        </Card>
    );
}

function PanelEmpty({
    icon: Icon,
    text,
    action,
}: {
    icon: LucideIcon;
    text: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-1 flex-col items-center justify-center gap-3 px-6 py-10 text-center">
            <Icon className="size-8 text-muted-foreground" />
            <p className="text-sm text-muted-foreground">{text}</p>
            {action}
        </div>
    );
}

function DatabasesPanel({
    databases,
    total,
}: {
    databases: DatabaseSummary[];
    total: number;
}) {
    const { isOpen, toggle } = useExpandedDatabases();

    return (
        <Panel
            icon={DatabaseIcon}
            title="Databases"
            count={total}
            href={DatabaseController.index.url()}
        >
            {databases.length === 0 ? (
                <PanelEmpty
                    icon={DatabaseZap}
                    text="No databases yet."
                    action={
                        <Button asChild size="sm">
                            <Link href={DatabaseController.create()}>
                                <Plus />
                                Create database
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-6">Database</TableHead>
                            <TableHead>Configuration</TableHead>
                            <TableHead>Nodes</TableHead>
                            <TableHead className="pr-6 text-right">
                                Status
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {databases.map((database) => (
                            <Fragment key={database.id}>
                                <TableRow>
                                    <TableCell className="py-3 pl-6">
                                        <Link
                                            href={DatabaseController.show({
                                                database: database.id,
                                            })}
                                            className="flex items-center gap-3"
                                        >
                                            <BrandIcon
                                                group="database-providers"
                                                src={database.engine_icon}
                                                size="sm"
                                            />
                                            <div className="min-w-0">
                                                <p className="font-medium hover:underline">
                                                    {database.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {versionOf(database)}
                                                </p>
                                            </div>
                                        </Link>
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {configurationOf(database)}
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <NodeDots
                                                nodes={membersOf(database)}
                                            />
                                            <ReadOnlyToggle
                                                database={database}
                                                open={isOpen(database.id)}
                                                onToggle={() =>
                                                    toggle(database.id)
                                                }
                                            />
                                        </div>
                                    </TableCell>
                                    <TableCell className="pr-6 text-right">
                                        <StatusBadge status={database.status} />
                                    </TableCell>
                                </TableRow>
                                <ReadOnlyRows
                                    database={database}
                                    open={isOpen(database.id)}
                                    firstCellClassName="pl-6"
                                    trailing={() => (
                                        <TableCell className="pr-6" />
                                    )}
                                />
                            </Fragment>
                        ))}
                    </TableBody>
                </Table>
            )}
        </Panel>
    );
}

function ServersPanel({
    servers,
    total,
}: {
    servers: Server[];
    total: number;
}) {
    return (
        <Panel
            icon={ServerIcon}
            title="Servers"
            count={total}
            href={ServerController.index.url()}
        >
            {servers.length === 0 ? (
                <PanelEmpty
                    icon={ServerIcon}
                    text="No servers yet."
                    action={
                        <Button asChild size="sm">
                            <Link href={ServerController.create()}>
                                <Plus />
                                Add server
                            </Link>
                        </Button>
                    }
                />
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-6">Server</TableHead>
                            <TableHead>Resources</TableHead>
                            <TableHead>Database</TableHead>
                            <TableHead className="pr-6 text-right">
                                Status
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {servers.map((server) => (
                            <TableRow key={server.id}>
                                <TableCell className="py-3 pl-6">
                                    <Link
                                        href={ServerController.show({
                                            server: server.id,
                                        })}
                                        className="flex items-center gap-3"
                                    >
                                        <BrandIcon
                                            group="os"
                                            src={server.distribution_icon}
                                        />
                                        <div className="min-w-0">
                                            <p className="font-medium hover:underline">
                                                {server.name}
                                            </p>
                                            <p className="font-mono text-xs text-muted-foreground">
                                                {server.public_ip}
                                            </p>
                                        </div>
                                    </Link>
                                </TableCell>
                                <TableCell>
                                    <ServerSpecs server={server} />
                                </TableCell>
                                <TableCell>
                                    <ServerDatabase server={server} />
                                </TableCell>
                                <TableCell className="pr-6 text-right">
                                    <StatusBadge status={server.status} />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </Panel>
    );
}

function ServerLogsPanel({ logs }: { logs: ServerLog[] }) {
    return (
        <Panel icon={ActivityIcon} title="Recent activity">
            {logs.length === 0 ? (
                <PanelEmpty icon={ActivityIcon} text="Nothing has run yet." />
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-6">Log</TableHead>
                            <TableHead>Resource</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead>Duration</TableHead>
                            <TableHead className="pr-6 text-right">
                                When
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {logs.map((log) => (
                            <TableRow key={log.id}>
                                <TableCell className="max-w-72 py-3 pl-6 font-medium">
                                    <span className="block truncate">
                                        {log.name}
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <span className="flex flex-col gap-0.5">
                                        {log.database && (
                                            <Link
                                                href={DatabaseController.show.url(
                                                    {
                                                        database:
                                                            log.database.id,
                                                    },
                                                    {
                                                        query: {
                                                            tab: 'activity',
                                                        },
                                                    },
                                                )}
                                                className="flex items-center gap-2 text-muted-foreground hover:text-foreground hover:underline"
                                            >
                                                <DatabaseIcon className="size-4" />
                                                {log.database.name}
                                            </Link>
                                        )}
                                        <Link
                                            href={ServerController.show.url(
                                                { server: log.server.id },
                                                {
                                                    query: { tab: 'activity' },
                                                },
                                            )}
                                            className="flex items-center gap-2 text-muted-foreground hover:text-foreground hover:underline"
                                        >
                                            <ServerIcon className="size-4" />
                                            {log.server.name}
                                        </Link>
                                    </span>
                                </TableCell>
                                <TableCell>
                                    <StatusBadge status={log.status} />
                                </TableCell>
                                <TableCell className="text-muted-foreground tabular-nums">
                                    <LiveDuration log={log} fallback="—" />
                                </TableCell>
                                <TableCell className="pr-6 text-right text-muted-foreground">
                                    {timeAgo(log.created_at)}
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </Panel>
    );
}

export default function Dashboard({
    summary,
    databases: { data: databases },
    servers: { data: servers },
    logs: { data: logs },
    requirements,
    showStarPrompt,
}: Props) {
    usePoll(10000, { only: ['summary', 'databases', 'servers', 'logs'] });

    const running = summary.running_logs;
    const voting = summary.nodes.total - summary.nodes.read_only;

    return (
        <>
            <Head title="Dashboard" />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Dashboard"
                        description="Your databases and the servers they run on"
                    />
                    <div className="flex gap-2">
                        <Button asChild variant="outline">
                            <Link href={ServerController.create()}>
                                <ServerIcon />
                                Add server
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={DatabaseController.create()}>
                                <Plus />
                                Create database
                            </Link>
                        </Button>
                    </div>
                </div>

                {showStarPrompt && <StarPromptCard />}

                {(summary.servers.total === 0 ||
                    summary.databases.total === 0) && (
                    <GettingStarted
                        hasServer={summary.servers.total > 0}
                        requirements={requirements}
                        hasDatabase={summary.databases.total > 0}
                    />
                )}

                <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                    <StatCard
                        icon={DatabaseIcon}
                        label="Databases"
                        value={summary.databases.total}
                        detail={`${summary.databases.online} online`}
                        href={DatabaseController.index()}
                    />
                    <StatCard
                        icon={ServerIcon}
                        label="Servers"
                        value={summary.servers.total}
                        detail={`${summary.servers.available} available`}
                        href={ServerController.index()}
                    />
                    <StatCard
                        icon={Boxes}
                        label="Nodes"
                        value={summary.nodes.total}
                        detail={`${voting} voting, ${summary.nodes.read_only} read-only`}
                    />
                    <StatCard
                        icon={running > 0 ? Loader2 : CircleCheck}
                        spin={running > 0}
                        label="Running scripts"
                        value={running}
                        detail={
                            logs[0]
                                ? `Last ${timeAgo(logs[0].created_at)}`
                                : 'Nothing has run yet'
                        }
                    />
                </div>

                <DatabasesPanel
                    databases={databases}
                    total={summary.databases.total}
                />

                <ServersPanel servers={servers} total={summary.servers.total} />

                <ServerLogsPanel logs={logs} />
            </div>
        </>
    );
}

Dashboard.layout = () => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
});
