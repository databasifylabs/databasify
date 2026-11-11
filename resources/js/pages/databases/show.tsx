import { Head, usePoll } from '@inertiajs/react';
import {
    ArchiveRestore,
    ChartLine,
    DatabaseZap,
    History,
    LayoutDashboard,
    ScrollText,
    Settings,
    ShieldCheck,
    Users,
    Waypoints,
} from 'lucide-react';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
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
import PoolsTab from '@/components/databases/tabs/pools-tab';
import SettingsTab from '@/components/databases/tabs/settings-tab';
import UsersTab from '@/components/databases/tabs/users-tab';
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
};

function tabsFor(database: Database): TabSpec[] {
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
}: Props) {
    const tabs = tabsFor(database);
    const [tab, navigate] = useQueryTab(
        tabs.map((entry) => entry.value),
        'overview',
    );
    const busy = database.operation !== null;

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
                                status={database.status}
                                className="absolute -right-1 -bottom-1 rounded-full ring-2 ring-background"
                            />
                        </div>
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-3">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {database.name}
                                </h1>
                                <StatusBadge status={database.status} />
                                {database.engine_community && (
                                    <CommunityBadge
                                        author={database.engine_author}
                                    />
                                )}
                                {database.operation && (
                                    <>
                                        <OperationLabel
                                            operation={database.operation}
                                            className="text-sm"
                                        />
                                        <StopOperationButton
                                            database={database}
                                            operation={database.operation}
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
                    <TabsContent value="pools">
                        <PoolsTab database={database} busy={busy} />
                    </TabsContent>
                    <TabsContent value="network">
                        <NetworkTab
                            database={database}
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
