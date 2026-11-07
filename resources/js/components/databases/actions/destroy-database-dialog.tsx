import { useState } from 'react';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import ConfirmDialog from '@/components/confirm-dialog';
import type { DialogControl } from '@/components/databases/actions/dialog-control';
import { Switch } from '@/components/ui/switch';
import { runAction } from '@/lib/run-action';
import type { DatabaseSummary } from '@/types';

export default function DestroyDatabaseDialog({
    database,
    ...control
}: DialogControl & { database: DatabaseSummary }) {
    const [erase, setErase] = useState(false);
    const [force, setForce] = useState(false);
    const [deleteBackups, setDeleteBackups] = useState(false);
    const recovery = database.point_in_time_recovery;

    return (
        <ConfirmDialog
            {...control}
            title={`Destroy ${database.name}?`}
            description={
                <p>
                    Services stop on every node and the database is removed from
                    Databasify. Without erasing, data stays on each server's
                    disk.
                </p>
            }
            confirmLabel="Destroy database"
            destructive
            typeToConfirm={database.name}
            onConfirm={() =>
                runAction(
                    DatabaseController.destroy({ database: database.id }),
                    {
                        data: {
                            erase_data: erase,
                            force,
                            delete_backups: erase && deleteBackups,
                        },
                        success: `Destroying ${database.name}`,
                    },
                )
            }
        >
            <div className="space-y-3 rounded-lg border p-3 text-sm">
                <label className="flex items-start gap-3">
                    <Switch checked={erase} onCheckedChange={setErase} />
                    <span>
                        <span className="font-medium">Erase all data</span>
                        <span className="block text-muted-foreground">
                            Deletes data, certificates and config from every
                            server. This cannot be undone.
                        </span>
                    </span>
                </label>
                {erase && recovery && (
                    <label className="flex items-start gap-3">
                        <Switch
                            checked={deleteBackups}
                            onCheckedChange={setDeleteBackups}
                        />
                        <span>
                            <span className="font-medium">
                                Also delete backups from the bucket
                            </span>
                            <span className="block text-muted-foreground">
                                Removes every base backup and log of{' '}
                                {database.name} from {recovery.storage.bucket}.
                            </span>
                        </span>
                    </label>
                )}
                <label className="flex items-start gap-3">
                    <Switch checked={force} onCheckedChange={setForce} />
                    <span>
                        <span className="font-medium">Force</span>
                        <span className="block text-muted-foreground">
                            Remove the database even if some servers can't be
                            reached.
                        </span>
                    </span>
                </label>
            </div>
        </ConfirmDialog>
    );
}
