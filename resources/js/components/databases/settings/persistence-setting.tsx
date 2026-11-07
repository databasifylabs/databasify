import { HardDrive } from 'lucide-react';
import DatabaseSettingsController from '@/actions/App/Http/Controllers/Databases/DatabaseSettingsController';
import SettingRow from '@/components/databases/settings/setting-row';
import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { runAction } from '@/lib/run-action';
import type { Database } from '@/types';

function turnOn(database: Database) {
    runAction(
        DatabaseSettingsController.persistence({ database: database.id }),
        {
            data: { persistence: 'snapshots' },
            success: 'Turning on snapshots',
        },
    );
}

function turnOff(database: Database) {
    runAction(
        DatabaseSettingsController.persistence({ database: database.id }),
        {
            data: { persistence: 'off' },
            success: 'Turning off persistence',
        },
    );
}

export default function PersistenceSetting({
    database,
    busy,
}: {
    database: Database;
    busy: boolean;
}) {
    const off = database.persistence === 'off';

    return (
        <SettingRow
            icon={HardDrive}
            title="Persistence"
            description="Whether each node also keeps its data on disk."
            action={
                off ? (
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => turnOn(database)}
                    >
                        Turn on snapshots
                    </Button>
                ) : (
                    <ConfirmDialog
                        trigger={
                            <Button variant="outline" disabled={busy}>
                                Turn off
                            </Button>
                        }
                        title={`Turn off persistence on ${database.name}?`}
                        description={
                            <>
                                <p>
                                    Nodes keep data in memory only. A node that
                                    restarts comes back empty and copies the
                                    data from the primary again. If every node
                                    restarts at once, the data is gone.
                                </p>
                            </>
                        }
                        confirmLabel="Turn off persistence"
                        destructive
                        typeToConfirm={database.name}
                        onConfirm={() => turnOff(database)}
                    />
                )
            }
        >
            {off ? (
                <span>
                    Off.{' '}
                    <span className="text-muted-foreground">
                        Data lives in memory only.
                    </span>
                </span>
            ) : (
                <span>
                    Snapshots.{' '}
                    <span className="text-muted-foreground">
                        Data is written to disk and survives restarts.
                    </span>
                </span>
            )}
        </SettingRow>
    );
}
