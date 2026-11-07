import { Link } from '@inertiajs/react';
import {
    ArrowRightLeft,
    ArrowUpCircle,
    BookCopy,
    CircleAlert,
    Gauge,
    Globe,
    Info,
    Loader2,
    Plus,
    RotateCcw,
    Server as ServerIcon,
    ShieldAlert,
    Trash2,
} from 'lucide-react';
import DatabaseNodeController from '@/actions/App/Http/Controllers/Databases/DatabaseNodeController';
import DatabaseOperationController from '@/actions/App/Http/Controllers/Databases/DatabaseOperationController';
import AddNodeDialog from '@/components/databases/nodes/add-node-dialog';
import ConfirmDialog from '@/components/confirm-dialog';
import ConnectionDetails from '@/components/databases/connection/connection-details';
import {
    membersOf,
    readOnlyNodesOf,
    updateActionOf,
    updateTitleOf,
} from '@/components/databases/describe';
import { roleOf } from '@/components/databases/nodes/node-dots';
import ServerSpecs from '@/components/servers/server-specs';
import { runAction } from '@/lib/run-action';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { capitalize } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Database, DatabaseNode, Server } from '@/types';

const roleStyles: Record<string, string> = {
    primary:
        'border-foreground/20 [&_[data-role-icon]]:bg-muted [&_[data-role-icon]]:text-foreground',
    standby:
        '[&_[data-role-icon]]:bg-muted [&_[data-role-icon]]:text-muted-foreground',
    'read-only':
        '[&_[data-role-icon]]:bg-muted [&_[data-role-icon]]:text-muted-foreground',
    unreachable:
        'border-dashed opacity-80 [&_[data-role-icon]]:bg-muted [&_[data-role-icon]]:text-muted-foreground',
};

function isSetUp(database: Database): boolean {
    return database.status !== 'creating' && database.status !== 'failed';
}

function isPlannedPrimary(database: Database, node: DatabaseNode): boolean {
    return isSetUp(database) ? node.role === 'primary' : node.is_seed;
}

function RetrySetup({ database, busy }: { database: Database; busy: boolean }) {
    return (
        <Alert variant="destructive">
            <CircleAlert />
            <AlertTitle>Setup didn't finish</AlertTitle>
            <AlertDescription>
                <p>
                    Check the activity log for what went wrong. Retry setup
                    clears what it left on the nodes and runs it again.
                </p>
                <ConfirmDialog
                    trigger={
                        <Button variant="outline" size="sm" disabled={busy}>
                            <RotateCcw /> Retry setup
                        </Button>
                    }
                    title={`Retry setting up ${database.name}?`}
                    description="The services and data the failed setup left on each node are removed, then the whole setup runs again."
                    confirmLabel="Retry setup"
                    onConfirm={() =>
                        runAction(
                            DatabaseOperationController.retrySetup({
                                database: database.id,
                            }),
                            { success: `Retrying ${database.name} setup` },
                        )
                    }
                />
            </AlertDescription>
        </Alert>
    );
}

function NodeCard({
    database,
    node,
    busy,
}: {
    database: Database;
    node: DatabaseNode;
    busy: boolean;
}) {
    const settingUp = database.status === 'creating';
    const role = isSetUp(database)
        ? roleOf(node)
        : isPlannedPrimary(database, node)
          ? 'primary'
          : 'standby';

    return (
        <div
            className={cn(
                'group relative rounded-xl border bg-card p-4 transition',
                roleStyles[role],
            )}
        >
            <div className="flex items-start gap-3">
                <span
                    data-role-icon
                    className="flex size-10 shrink-0 items-center justify-center rounded-lg"
                >
                    <ServerIcon className="size-5" />
                </span>
                <div className="min-w-0 flex-1 space-y-1">
                    <div className="flex items-center gap-2">
                        {node.kind === 'read_only' ? (
                            <Link
                                href={DatabaseNodeController.show({
                                    database: database.id,
                                    node: node.id,
                                })}
                                className="truncate font-medium hover:underline"
                            >
                                {node.name}
                            </Link>
                        ) : (
                            <span className="truncate font-medium">
                                {node.name}
                            </span>
                        )}
                        {settingUp ? (
                            <Badge variant="outline">
                                <Loader2 className="animate-spin" />
                                Setting up
                            </Badge>
                        ) : !isSetUp(database) ? (
                            <Badge variant="outline">Not set up</Badge>
                        ) : (
                            <Badge
                                variant={
                                    role === 'primary'
                                        ? 'default'
                                        : role === 'unreachable'
                                          ? 'outline'
                                          : 'secondary'
                                }
                            >
                                {capitalize(role)}
                            </Badge>
                        )}
                    </div>
                    <div className="truncate font-mono text-xs text-muted-foreground">
                        {node.domain ?? node.public_ip} · {node.private_ip}
                        {node.engine_version &&
                            node.engine_version !== database.engine_version &&
                            ` · ${database.engine_label} ${node.engine_version}`}
                    </div>
                    <ServerSpecs server={node} className="pt-1" />
                </div>
                {role !== 'primary' && (
                    <ConfirmDialog
                        trigger={
                            <Button
                                variant="ghost"
                                size="icon"
                                className="absolute top-2 right-2 size-8 opacity-0 transition group-hover:opacity-100 focus-visible:opacity-100"
                                disabled={busy}
                                aria-label={`Remove ${node.name}`}
                            >
                                <Trash2 />
                            </Button>
                        }
                        title={`Remove ${node.name}?`}
                        description={
                            <p>
                                The node leaves {database.name} and its copy of
                                the data is erased. If the server is
                                unreachable, removal can be forced.
                            </p>
                        }
                        confirmLabel="Remove node"
                        destructive
                        onConfirm={() =>
                            runAction(
                                DatabaseNodeController.destroy({
                                    database: database.id,
                                    node: node.id,
                                }),
                                {
                                    data: { force: role === 'unreachable' },
                                    success: `Removing ${node.name}`,
                                },
                            )
                        }
                    />
                )}
            </div>
        </div>
    );
}

function Section({
    title,
    count,
    children,
    action,
}: {
    title: string;
    count?: number;
    children: React.ReactNode;
    action?: React.ReactNode;
}) {
    return (
        <section className="space-y-3">
            <div className="flex items-center justify-between gap-2">
                <h3 className="flex items-center gap-2 font-semibold">
                    {title}
                    {count !== undefined && (
                        <Badge variant="secondary" className="rounded-full">
                            {count}
                        </Badge>
                    )}
                </h3>
                {action}
            </div>
            {children}
        </section>
    );
}

export default function OverviewTab({
    database,
    appEnv,
    availableServers,
    busy,
    onNavigate,
}: {
    database: Database;
    appEnv: Record<string, string>;
    availableServers: Server[];
    busy: boolean;
    onNavigate: (tab: string) => void;
}) {
    const members = membersOf(database);
    const primary = members.filter((node) => isPlannedPrimary(database, node));
    const standbys = members.filter(
        (node) => !isPlannedPrimary(database, node),
    );
    const readers = readOnlyNodesOf(database);
    const membersFull = members.length >= database.max_standbys + 1;
    const readersFull = readers.length >= database.max_read_only_nodes;

    return (
        <div className="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
            <div className="min-w-0 space-y-8">
                {database.status === 'failed' && (
                    <RetrySetup database={database} busy={busy} />
                )}
                {database.alerts.map((alert) => (
                    <Alert key={alert.id} variant="destructive">
                        <Gauge />
                        <AlertTitle>
                            {alert.label} above 90% on {alert.node_name}
                        </AlertTitle>
                        <AlertDescription>
                            It has stayed there for at least five minutes. Check
                            Insights, or move to bigger servers.
                        </AlertDescription>
                    </Alert>
                ))}
                {database.mode === 'ha' && !database.domain && (
                    <Alert variant="warning">
                        <Globe />
                        <AlertTitle>
                            Clients connect to the primary's IP
                        </AlertTitle>
                        <AlertDescription>
                            <p>
                                After a failover the primary moves to another
                                node, and apps keep using the old address until
                                you change it. Add a domain so {database.name}'s
                                hostname always follows the primary.
                            </p>
                            <Button
                                variant="link"
                                className="h-auto p-0 text-current underline"
                                onClick={() => onNavigate('settings')}
                            >
                                Add a domain
                            </Button>
                        </AlertDescription>
                    </Alert>
                )}

                <Section title="Primary">
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-[repeat(auto-fill,minmax(18rem,1fr))]">
                        {primary.map((member) => (
                            <NodeCard
                                key={member.id}
                                database={database}
                                node={member}
                                busy={busy}
                            />
                        ))}
                        {primary.length === 0 && (
                            <Alert
                                variant="destructive"
                                className="sm:col-span-2"
                            >
                                <ShieldAlert />
                                <AlertDescription>
                                    No node is serving as primary right now. Use
                                    Recover in the Actions menu if the old
                                    primary is gone.
                                </AlertDescription>
                            </Alert>
                        )}
                    </div>
                </Section>

                <Section
                    title="Standby nodes"
                    count={standbys.length}
                    action={
                        <AddNodeDialog
                            database={database}
                            servers={availableServers}
                            kind="member"
                            trigger={
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={busy || membersFull}
                                >
                                    <Plus /> Add standby
                                </Button>
                            }
                        />
                    }
                >
                    {standbys.length > 0 ? (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[repeat(auto-fill,minmax(18rem,1fr))]">
                            {standbys.map((member) => (
                                <NodeCard
                                    key={member.id}
                                    database={database}
                                    node={member}
                                    busy={busy}
                                />
                            ))}
                        </div>
                    ) : (
                        <Alert>
                            <Info />
                            <AlertDescription>
                                Add standby nodes to prevent downtime and data
                                loss: a standby takes over automatically when
                                the primary fails.
                            </AlertDescription>
                        </Alert>
                    )}
                    {standbys.length > 0 && !database.automatic_failover && (
                        <Alert variant="warning">
                            <ShieldAlert />
                            <AlertDescription>
                                {database.failover_hint}
                            </AlertDescription>
                        </Alert>
                    )}
                </Section>

                <Section
                    title="Read-only nodes"
                    count={readers.length}
                    action={
                        <AddNodeDialog
                            database={database}
                            servers={availableServers}
                            kind="read_only"
                            trigger={
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={busy || readersFull}
                                >
                                    <Plus /> Add read-only
                                </Button>
                            }
                        />
                    }
                >
                    {readers.length > 0 ? (
                        <div className="grid grid-cols-1 gap-3 sm:grid-cols-[repeat(auto-fill,minmax(18rem,1fr))]">
                            {readers.map((member) => (
                                <NodeCard
                                    key={member.id}
                                    database={database}
                                    node={member}
                                    busy={busy}
                                />
                            ))}
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No read-only nodes yet. Each one gets its own name
                            and host, serves reads from its own copy on port{' '}
                            {database.port}, and is never promoted.
                        </p>
                    )}
                </Section>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-sm">
                            <ArrowUpCircle className="size-4 text-muted-foreground" />
                            Current version
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3 text-sm">
                        <div className="text-2xl font-semibold">
                            {database.engine_label}{' '}
                            <span className="font-mono text-base text-muted-foreground">
                                {database.engine_version ?? '—'}
                            </span>
                        </div>
                        {database.available_version ? (
                            <ConfirmDialog
                                trigger={
                                    <Button size="sm" disabled={busy}>
                                        <ArrowUpCircle />{' '}
                                        {updateActionOf(database)}
                                    </Button>
                                }
                                title={updateTitleOf(database)}
                                description={
                                    <p>
                                        Nodes are upgraded one at a time:
                                        read-only nodes, then standbys, then the
                                        primary after a failover.
                                    </p>
                                }
                                confirmLabel="Start rolling upgrade"
                                onConfirm={() =>
                                    runAction(
                                        DatabaseOperationController.upgrade({
                                            database: database.id,
                                        }),
                                        {
                                            success: 'Rolling upgrade started',
                                        },
                                    )
                                }
                            />
                        ) : (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    runAction(
                                        DatabaseOperationController.checkUpgrade(
                                            { database: database.id },
                                        ),
                                        { success: 'Checked for upgrades' },
                                    )
                                }
                            >
                                Check for upgrades
                            </Button>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="min-w-0 space-y-4">
                <ConnectionDetails
                    database={database}
                    endpoints={database.endpoints}
                    appEnv={appEnv}
                    discovers
                />
                <Card>
                    <CardContent className="flex items-start gap-4">
                        <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <BookCopy className="size-5" />
                        </span>
                        <div className="space-y-2">
                            <div className="font-medium">
                                Migrate an existing database
                            </div>
                            <p className="text-sm text-muted-foreground">
                                Copy a {database.engine_label} database from
                                anywhere, and keep it in sync until you switch
                                over.
                            </p>
                            <Button
                                variant="link"
                                className="h-auto p-0"
                                onClick={() => onNavigate('migrations')}
                            >
                                <ArrowRightLeft /> Set up migration
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
