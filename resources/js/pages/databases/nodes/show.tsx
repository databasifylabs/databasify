import { Head, Link, usePoll } from '@inertiajs/react';
import {
    ChartLine,
    History,
    Info,
    LayoutDashboard,
    ScrollText,
    Server as ServerIcon,
    Settings,
    ShieldCheck,
} from 'lucide-react';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import DatabaseNodeController from '@/actions/App/Http/Controllers/Databases/DatabaseNodeController';
import ConnectionDetails from '@/components/databases/connection/connection-details';
import BrandIcon from '@/components/brand-icon';
import OperationLabel from '@/components/databases/operation-label';
import NodeSettingsTab from '@/components/databases/nodes/node-settings-tab';
import { roleOf } from '@/components/databases/nodes/node-dots';
import InsightsTab from '@/components/databases/tabs/insights-tab';
import LogsTab from '@/components/databases/tabs/logs-tab';
import NetworkTab from '@/components/databases/tabs/network-tab';
import { ServerSpecItems } from '@/components/servers/server-specs';
import TabNav from '@/components/tab-nav';
import type { TabSpec } from '@/components/tab-nav';
import ServerLogList, {
    liveLogProps,
} from '@/components/server-logs/server-log-list';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { useQueryTab } from '@/hooks/use-query-tab';
import { capitalize } from '@/lib/format';
import type {
    CursorPaginated,
    Database,
    DatabaseEndpoints,
    DatabaseNode,
    ServerLog,
    ServerLogWithOutput,
} from '@/types';

type Props = {
    database: { data: Database };
    node: { data: DatabaseNode };
    endpoints: DatabaseEndpoints;
    appEnv: Record<string, string>;
    logs: CursorPaginated<ServerLog>;
    log: ServerLogWithOutput | null;
    latestLogId: string | null;
    clientIp: string | null;
};

const tabs: TabSpec[] = [
    { value: 'overview', label: 'Overview', icon: LayoutDashboard },
    { value: 'insights', label: 'Insights', icon: ChartLine },
    { value: 'network', label: 'Network Access', icon: ShieldCheck },
    { value: 'logs', label: 'Logs', icon: ScrollText },
    { value: 'activity', label: 'Activity', icon: History },
    { value: 'settings', label: 'Settings', icon: Settings },
];

function NodeDetails({
    database,
    node,
}: {
    database: Database;
    node: DatabaseNode;
}) {
    const reachable = node.role !== 'unknown';

    return (
        <div className="space-y-4">
            <Alert variant={reachable ? 'default' : 'warning'}>
                <Info />
                <AlertDescription>
                    {reachable
                        ? `Serves reads from its own copy of ${database.name}. Writes are refused, and the node is never promoted to primary.`
                        : `Databasify can't confirm this node's link to the primary. It keeps answering reads from the data it already has, which may be out of date.`}
                </AlertDescription>
            </Alert>
            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-sm">
                        <ServerIcon className="size-4 text-muted-foreground" />
                        Configuration
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-3 text-sm">
                        <div>
                            <dt className="text-muted-foreground">Server</dt>
                            <dd>{node.server_name}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Version</dt>
                            <dd>
                                {database.engine_label}{' '}
                                {node.engine_version ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Public IP</dt>
                            <dd className="font-mono break-all">
                                {node.public_ip}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">
                                Private IP
                            </dt>
                            <dd className="font-mono break-all">
                                {node.private_ip}
                            </dd>
                        </div>
                        <div className="col-span-2 flex flex-wrap gap-x-4 gap-y-1 text-muted-foreground">
                            <ServerSpecItems
                                server={node}
                                iconClassName="size-3.5"
                            />
                        </div>
                    </dl>
                </CardContent>
            </Card>
        </div>
    );
}

export default function ShowNode({
    database: { data: database },
    node: { data: node },
    endpoints,
    appEnv,
    logs,
    log,
    latestLogId,
    clientIp,
}: Props) {
    const [tab, navigate] = useQueryTab(
        tabs.map((entry) => entry.value),
        'overview',
    );
    const busy = database.operation !== null;
    const role = roleOf(node);

    usePoll(5000, () => ({
        only: ['database', 'node', ...liveLogProps(logs, log)],
    }));

    return (
        <>
            <Head title={node.name} />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <div className="flex items-center gap-4">
                    <BrandIcon
                        group="database-providers"
                        src={database.engine_icon}
                        size="lg"
                    />
                    <div className="min-w-0 space-y-1">
                        <div className="flex flex-wrap items-center gap-3">
                            <h1 className="truncate text-2xl font-semibold tracking-tight">
                                {node.name}
                            </h1>
                            <Badge
                                variant={
                                    role === 'unreachable'
                                        ? 'outline'
                                        : 'secondary'
                                }
                            >
                                {capitalize(role)}
                            </Badge>
                            {database.operation && (
                                <OperationLabel
                                    operation={database.operation}
                                    className="text-sm"
                                />
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            Read-only node of{' '}
                            <Link
                                href={DatabaseController.show(database.id)}
                                className="font-medium text-foreground hover:underline"
                            >
                                {database.name}
                            </Link>{' '}
                            · {node.domain ?? node.public_ip}
                        </p>
                    </div>
                </div>

                <Tabs value={tab} onValueChange={navigate} className="gap-6">
                    <TabNav tabs={tabs} />

                    <TabsContent value="overview">
                        <div className="grid grid-cols-1 gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,26rem)]">
                            <NodeDetails database={database} node={node} />
                            <ConnectionDetails
                                database={database}
                                endpoints={endpoints}
                                appEnv={appEnv}
                            />
                        </div>
                    </TabsContent>
                    <TabsContent value="insights">
                        <InsightsTab database={database} node={node} />
                    </TabsContent>
                    <TabsContent value="network" className="space-y-6">
                        <Alert>
                            <Info />
                            <AlertDescription>
                                Trusted sources are shared with {database.name}:
                                a change here applies to every node in the
                                database.
                            </AlertDescription>
                        </Alert>
                        <NetworkTab
                            database={database}
                            clientIp={clientIp}
                            busy={busy}
                        />
                    </TabsContent>
                    <TabsContent value="logs">
                        <LogsTab database={database} nodes={[node]} />
                    </TabsContent>
                    <TabsContent value="activity">
                        <ServerLogList
                            logs={logs}
                            log={log}
                            latestLogId={latestLogId}
                        />
                    </TabsContent>
                    <TabsContent value="settings">
                        <NodeSettingsTab
                            database={database}
                            node={node}
                            busy={busy}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

ShowNode.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Databases', href: DatabaseController.index() },
        {
            title: props.database.data.name,
            href: DatabaseController.show(props.database.data.id),
        },
        {
            title: props.node.data.name,
            href: DatabaseNodeController.show({
                database: props.database.data.id,
                node: props.node.data.id,
            }),
        },
    ],
});
