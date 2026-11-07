import { Link } from '@inertiajs/react';
import {
    ArrowUpCircle,
    ChevronDown,
    EllipsisVertical,
    FolderInput,
    LifeBuoy,
    Power,
    RefreshCw,
    Repeat,
    Settings,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import DatabaseOperationController from '@/actions/App/Http/Controllers/Databases/DatabaseOperationController';
import DestroyDatabaseDialog from '@/components/databases/actions/destroy-database-dialog';
import FailoverDialog from '@/components/databases/actions/failover-dialog';
import RebootDatabaseDialog from '@/components/databases/actions/reboot-database-dialog';
import RecoverDatabaseDialog from '@/components/databases/actions/recover-database-dialog';
import UpgradeDatabaseDialog from '@/components/databases/actions/upgrade-database-dialog';
import { membersOf, updateActionOf } from '@/components/databases/describe';
import MoveToProjectDialog from '@/components/projects/move-to-project-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useMoveTargets } from '@/hooks/use-move-targets';
import { runAction } from '@/lib/run-action';
import type { DatabaseSummary } from '@/types';

type Dialog =
    | 'upgrade'
    | 'failover'
    | 'recover'
    | 'reboot'
    | 'move'
    | 'destroy';

type Variant = 'button' | 'icon';

export default function DatabaseActions({
    database,
    busy,
    variant = 'icon',
}: {
    database: DatabaseSummary;
    busy: boolean;
    variant?: Variant;
}) {
    const [dialog, setDialog] = useState<Dialog | null>(null);
    const canMove = useMoveTargets().length > 0;
    const controlFor = (name: Dialog) => ({
        open: dialog === name,
        onOpenChange: (open: boolean) => setDialog(open ? name : null),
    });

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    {variant === 'button' ? (
                        <Button variant="outline">
                            Actions
                            <ChevronDown />
                        </Button>
                    ) : (
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Actions"
                        >
                            <EllipsisVertical />
                        </Button>
                    )}
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    <DropdownMenuItem
                        onSelect={() =>
                            runAction(
                                DatabaseOperationController.refresh({
                                    database: database.id,
                                }),
                                { success: 'Refreshing status' },
                            )
                        }
                    >
                        <RefreshCw />
                        Refresh status
                    </DropdownMenuItem>
                    {database.available_version ? (
                        <DropdownMenuItem
                            disabled={busy}
                            onSelect={() => setDialog('upgrade')}
                        >
                            <ArrowUpCircle />
                            {updateActionOf(database)}
                        </DropdownMenuItem>
                    ) : (
                        <DropdownMenuItem
                            disabled={busy}
                            onSelect={() =>
                                runAction(
                                    DatabaseOperationController.checkUpgrade({
                                        database: database.id,
                                    }),
                                    { success: 'Checked for updates' },
                                )
                            }
                        >
                            <ArrowUpCircle />
                            Check for updates
                        </DropdownMenuItem>
                    )}
                    {database.mode === 'ha' && (
                        <DropdownMenuItem
                            disabled={busy || membersOf(database).length < 2}
                            onSelect={() => setDialog('failover')}
                        >
                            <Repeat />
                            Fail over
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem
                        disabled={busy}
                        onSelect={() => setDialog('recover')}
                    >
                        <LifeBuoy />
                        Recover
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={busy}
                        onSelect={() => setDialog('reboot')}
                    >
                        <Power />
                        Reboot servers
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <Link
                            href={DatabaseController.show(
                                { database: database.id },
                                {
                                    query: { tab: 'settings' },
                                },
                            )}
                        >
                            <Settings />
                            Settings
                        </Link>
                    </DropdownMenuItem>
                    {canMove && (
                        <DropdownMenuItem
                            disabled={busy}
                            onSelect={() => setDialog('move')}
                        >
                            <FolderInput />
                            Move to project
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        onSelect={() => setDialog('destroy')}
                    >
                        <Trash2 />
                        Destroy
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            {database.available_version && (
                <UpgradeDatabaseDialog
                    database={database}
                    {...controlFor('upgrade')}
                />
            )}
            {database.mode === 'ha' && (
                <FailoverDialog
                    database={database}
                    {...controlFor('failover')}
                />
            )}
            <RecoverDatabaseDialog
                database={database}
                {...controlFor('recover')}
            />
            <RebootDatabaseDialog
                database={database}
                {...controlFor('reboot')}
            />
            <MoveToProjectDialog
                resource={{
                    type: 'database',
                    id: database.id,
                    name: database.name,
                }}
                description="Its servers move with it."
                {...controlFor('move')}
            />
            <DestroyDatabaseDialog
                database={database}
                {...controlFor('destroy')}
            />
        </>
    );
}
