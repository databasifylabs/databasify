import {
    ArrowUpCircle,
    Info,
    RefreshCw,
    Repeat,
    Tag,
    Trash2,
} from 'lucide-react';
import DatabaseOperationController from '@/actions/App/Http/Controllers/Databases/DatabaseOperationController';
import DestroyDatabaseDialog from '@/components/databases/actions/destroy-database-dialog';
import FailoverDialog from '@/components/databases/actions/failover-dialog';
import UpgradeDatabaseDialog from '@/components/databases/actions/upgrade-database-dialog';
import ConfigCard from '@/components/databases/settings/config-card';
import DomainSetting from '@/components/databases/settings/domain-setting';
import MaintenanceSetting from '@/components/databases/settings/maintenance-setting';
import PersistenceSetting from '@/components/databases/settings/persistence-setting';
import {
    configurationOf,
    membersOf,
    updateActionOf,
    versionOf,
} from '@/components/databases/describe';
import SettingRow from '@/components/databases/settings/setting-row';
import { runAction } from '@/lib/run-action';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { timeAgo } from '@/lib/format';
import type { Database, DnsZoneOption } from '@/types';

export default function SettingsTab({
    database,
    zones,
    busy,
}: {
    database: Database;
    zones: DnsZoneOption[];
    busy: boolean;
}) {
    const members = membersOf(database);

    return (
        <div className="space-y-6">
            <Card className="py-0">
                <CardContent className="divide-y">
                    <SettingRow
                        icon={Info}
                        title="Database"
                        description="General details."
                    >
                        <dl className="grid grid-cols-[8rem_1fr] gap-y-1.5">
                            <dt className="text-muted-foreground">Engine</dt>
                            <dd>{database.engine_label}</dd>
                            <dt className="text-muted-foreground">Nodes</dt>
                            <dd>{configurationOf(database)}</dd>
                            <dt className="text-muted-foreground">Port</dt>
                            <dd className="font-mono">{database.port}</dd>
                            <dt className="text-muted-foreground">Created</dt>
                            <dd>{timeAgo(database.created_at)}</dd>
                        </dl>
                    </SettingRow>

                    <SettingRow
                        icon={Tag}
                        title="Version"
                        description={`Patch releases of ${database.version_label} roll through the nodes one at a time.`}
                        action={
                            database.available_version ? (
                                <UpgradeDatabaseDialog
                                    database={database}
                                    trigger={
                                        <Button disabled={busy}>
                                            <ArrowUpCircle />
                                            {updateActionOf(database)}
                                        </Button>
                                    }
                                />
                            ) : (
                                <Button
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        runAction(
                                            DatabaseOperationController.checkUpgrade(
                                                { database: database.id },
                                            ),
                                            { success: 'Checked for updates' },
                                        )
                                    }
                                >
                                    <RefreshCw />
                                    Check for updates
                                </Button>
                            )
                        }
                    >
                        <div className="flex items-center gap-2">
                            <Badge variant="secondary" className="font-mono">
                                {versionOf(database)}
                            </Badge>
                            {database.available_version ? (
                                <span className="text-sm text-primary">
                                    {database.available_update_refreshes_image
                                        ? 'A rebuilt image is available'
                                        : `${database.available_version} is available`}
                                </span>
                            ) : (
                                <span className="text-sm text-muted-foreground">
                                    Up to date
                                </span>
                            )}
                        </div>
                    </SettingRow>

                    <MaintenanceSetting database={database} busy={busy} />

                    {database.offers_persistence && (
                        <PersistenceSetting database={database} busy={busy} />
                    )}

                    <DomainSetting
                        database={database}
                        zones={zones}
                        busy={busy}
                    />

                    {database.mode === 'ha' && (
                        <SettingRow
                            icon={Repeat}
                            title="Failover"
                            description={
                                database.automatic_failover
                                    ? 'Failover is automatic. You can also move the primary by hand.'
                                    : 'Move the primary role to a standby.'
                            }
                            action={
                                <FailoverDialog
                                    database={database}
                                    trigger={
                                        <Button
                                            variant="outline"
                                            disabled={
                                                busy || members.length < 2
                                            }
                                        >
                                            <Repeat />
                                            Fail over
                                        </Button>
                                    }
                                />
                            }
                        />
                    )}
                </CardContent>
            </Card>

            <ConfigCard database={database} busy={busy} />

            <Card className="border-destructive/40 py-0">
                <CardContent>
                    <SettingRow
                        icon={Trash2}
                        title="Destroy database"
                        description="Stop the database on every server and remove it."
                        action={
                            <DestroyDatabaseDialog
                                database={database}
                                trigger={
                                    <Button variant="destructive">
                                        <Trash2 />
                                        Destroy
                                    </Button>
                                }
                            />
                        }
                    />
                </CardContent>
            </Card>
        </div>
    );
}
