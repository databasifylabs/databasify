import { Head, Link, router, usePoll } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { databaseOperation, databaseStatus } from '@/lib/status';
import {
    ArchiveRestore,
    ChartLine,
    DatabaseZap,
    History,
    Info,
    LayoutDashboard,
    Puzzle,
    ScrollText,
    Settings,
    ShieldCheck,
    Users,
    Waypoints,
    TriangleAlert,
} from 'lucide-react';
import ConnectionPoolController from '@/actions/App/Http/Controllers/Databases/ConnectionPoolController';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import DatabaseOperationController from '@/actions/App/Http/Controllers/Databases/DatabaseOperationController';
import TrustedSourceController from '@/actions/App/Http/Controllers/Databases/TrustedSourceController';
import DatabaseActions from '@/components/databases/actions/database-actions';
import {
    configurationOf,
    membersOf,
    specsOf,
} from '@/components/databases/describe';
import BrandIcon from '@/components/brand-icon';
import CommunityBadge from '@/components/community-badge';
import StatusDot from '@/components/status-dot';
import OperationLabel from '@/components/databases/operation-label';
import StopOperationButton from '@/components/databases/stop-operation-button';
import UnsupportedSdkAlert from '@/components/databases/unsupported-sdk-alert';
import type { UnsupportedSdk } from '@/components/databases/unsupported-sdk-alert';
import StatusBadge from '@/components/status-badge';
import TabNav from '@/components/tab-nav';
import type { TabSpec } from '@/components/tab-nav';
import ServerLogList, {
    liveLogProps,
} from '@/components/server-logs/server-log-list';
import BackupsTab from '@/components/databases/tabs/backups-tab';
import InsightsTab from '@/components/databases/tabs/insights-tab';
import LogsTab from '@/components/databases/tabs/logs-tab';
import MigrationsTab from '@/components/databases/tabs/migrations-tab';
import NetworkTab from '@/components/databases/tabs/network-tab';
import OverviewTab from '@/components/databases/tabs/overview-tab';
import ExtensionsTab from '@/components/databases/tabs/extensions-tab';
import PoolsTab from '@/components/databases/tabs/pools-tab';
import SettingsTab from '@/components/databases/tabs/settings-tab';
import UsersTab from '@/components/databases/tabs/users-tab';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { useQueryTab } from '@/hooks/use-query-tab';
import type {
    CursorPaginated,
    Database,
    DnsZoneOption,
    Server,
    StorageProviderOption,
    ServerLog,
    ServerLogWithOutput,
} from '@/types';

type Props = {
    database: { data: Database };
    appEnv: Record<string, string>;
    availableServers: { data: Server[] };
    dnsZones: DnsZoneOption[];
    storageProviders: StorageProviderOption[];
    logs: CursorPaginated<ServerLog>;
    log: ServerLogWithOutput | null;
    latestLogId: string | null;
    clientIp: string | null;
    sdkUnsupported: UnsupportedSdk | null;
    extensionUpdates?: number;
};

function tabsFor(database: Database, extensionUpdates: number): TabSpec[] {
    return [
        { value: 'overview', label: 'Overview', icon: LayoutDashboard },
        { value: 'insights', label: 'Insights', icon: ChartLine },
        {
            value: 'users',
            label: database.supports_users ? 'Users & Databases' : 'Access',
            icon: Users,
        },
        ...(database.pool_port !== null
            ? [{ value: 'pools', label: 'Connection Pools', icon: Waypoints }]
            : []),
        ...(database.supports_extensions
            ? [
                  {
                      value: 'extensions',
                      label: 'Extensions',
                      icon: Puzzle,
                      count: {
                          value: extensionUpdates,
                          label: `${extensionUpdates} extension ${extensionUpdates === 1 ? 'update' : 'updates'} available`,
                      },
                  },
              ]
            : []),
        { value: 'network', label: 'Network Access', icon: ShieldCheck },
        ...(database.supports_point_in_time_recovery ||
        database.point_in_time_recovery_unavailable !== null
            ? [{ value: 'backups', label: 'Backups', icon: ArchiveRestore }]
            : []),
        { value: 'migrations', label: 'Migrations', icon: DatabaseZap },
        { value: 'logs', label: 'Logs', icon: ScrollText },
        { value: 'activity', label: 'Activity', icon: History },
        { value: 'settings', label: 'Settings', icon: Settings },
    ];
}

export default function ShowDatabase({
    database: { data: database },
    appEnv,
    availableServers: { data: servers },
    dnsZones,
    storageProviders,
    logs,
    log,
    latestLogId,
    clientIp,
    sdkUnsupported,
    extensionUpdates = 0,
}: Props) {
    const tabs = tabsFor(database, extensionUpdates);
    const [tab, navigate] = useQueryTab(
        tabs.map((entry) => entry.value),
        'overview',
    );
    const operation = databaseOperation(database);
    const busy = operation !== null;

    const wasBusy = useRef(busy);

    useEffect(() => {
        if (wasBusy.current && !busy) {
            router.reload({ only: ['extensionUpdates'] });
        }

        wasBusy.current = busy;
    }, [busy]);

    usePoll(5000, () => ({
        only: ['database', 'availableServers', ...liveLogProps(logs, log)],
    }));

    return (
        <>
            <Head title={database.name} />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="relative">
                            <BrandIcon
                                group="database-providers"
                                src={database.engine_icon}
                                size="lg"
                            />
                            <StatusDot
                                status={databaseStatus(database)}
                                className="absolute -right-1 -bottom-1 rounded-full ring-2 ring-background"
                            />
                        </div>
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-3">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {database.name}
                                </h1>
                                <StatusBadge
                                    status={databaseStatus(database)}
                                />
                                {database.engine_community && (
                                    <CommunityBadge
                                        author={database.engine_author}
                                    />
                                )}
                                {operation && (
                                    <>
                                        <OperationLabel
                                            operation={operation}
                                            className="text-sm"
                                        />
                                        <StopOperationButton
                                            route={DatabaseOperationController.stop(
                                                { database: database.id },
                                            )}
                                            name={database.name}
                                            status={database.status}
                                            runningTask={database.running_task}
                                        />
                                    </>
                                )}
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {specsOf(database)}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {configurationOf(database)}
                            </p>
                        </div>
                    </div>
                    <DatabaseActions
                        database={database}
                        busy={busy}
                        variant="button"
                    />
                </div>

                {sdkUnsupported && (
                    <UnsupportedSdkAlert
                        name={database.name}
                        unsupported={sdkUnsupported}
                    />
                )}

                {!busy && database.last_failure && (
                    <Alert variant="destructive">
                        <TriangleAlert />
                        <AlertTitle>
                            {database.last_failure.name} failed
                        </AlertTitle>
                        <AlertDescription>
                            <Link
                                href={DatabaseController.show(
                                    { database: database.id },
                                    {
                                        query: {
                                            tab: 'logs',
                                            log: database.last_failure.log_id,
                                        },
                                    },
                                )}
                                className="underline"
                            >
                                See what went wrong
                            </Link>
                        </AlertDescription>
                    </Alert>
                )}

                <Tabs value={tab} onValueChange={navigate} className="gap-6">
                    <TabNav tabs={tabs} />

                    <TabsContent value="overview">
                        <OverviewTab
                            database={database}
                            appEnv={appEnv}
                            availableServers={servers}
                            busy={busy}
                            onNavigate={navigate}
                        />
                    </TabsContent>
                    <TabsContent value="insights">
                        <InsightsTab database={database} />
                    </TabsContent>
                    <TabsContent value="users">
                        <UsersTab database={database} appEnv={appEnv} />
                    </TabsContent>
                    <TabsContent value="extensions">
                        <ExtensionsTab database={database} busy={busy} />
                    </TabsContent>
                    <TabsContent value="pools" className="space-y-6">
                        {database.nodes.some(
                            (node) => node.kind === 'read_only',
                        ) && (
                            <Alert>
                                <Info />
                                <AlertDescription>
                                    These pools run on the primary and the
                                    standbys. Each read-only node has its own,
                                    on its page.
                                </AlertDescription>
                            </Alert>
                        )}
                        <PoolsTab
                            database={database}
                            pools={database.pools}
                            host={database.endpoints.public.host}
                            store={ConnectionPoolController.store({
                                database: database.id,
                            })}
                            destroy={(poolId) =>
                                ConnectionPoolController.destroy({
                                    database: database.id,
                                    connectionPool: poolId,
                                })
                            }
                            busy={busy}
                        />
                    </TabsContent>
                    <TabsContent value="network" className="space-y-6">
                        {database.nodes.some(
                            (node) => node.kind === 'read_only',
                        ) && (
                            <Alert>
                                <Info />
                                <AlertDescription>
                                    These trusted sources apply to the primary
                                    and the standbys. Each read-only node has
                                    its own, on its page.
                                </AlertDescription>
                            </Alert>
                        )}
                        <NetworkTab
                            sources={database.trusted_sources}
                            store={TrustedSourceController.store.form({
                                database: database.id,
                            })}
                            destroy={(sourceId) =>
                                TrustedSourceController.destroy({
                                    database: database.id,
                                    trustedSource: sourceId,
                                })
                            }
                            clientIp={clientIp}
                            busy={busy}
                        />
                    </TabsContent>
                    <TabsContent value="backups">
                        <BackupsTab
                            database={database}
                            storageProviders={storageProviders}
                            servers={servers}
                            busy={busy}
                        />
                    </TabsContent>
                    <TabsContent value="migrations">
                        <MigrationsTab database={database} busy={busy} />
                    </TabsContent>
                    <TabsContent value="logs">
                        <LogsTab
                            database={database}
                            nodes={membersOf(database)}
                        />
                    </TabsContent>
                    <TabsContent value="activity">
                        <ServerLogList
                            logs={logs}
                            log={log}
                            latestLogId={latestLogId}
                        />
                    </TabsContent>
                    <TabsContent value="settings">
                        <SettingsTab
                            database={database}
                            zones={dnsZones}
                            busy={busy}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

ShowDatabase.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Databases', href: DatabaseController.index() },
        {
            title: props.database.data.name,
            href: DatabaseController.show({ database: props.database.data.id }),
        },
    ],
});
