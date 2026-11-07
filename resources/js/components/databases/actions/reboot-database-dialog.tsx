import DatabaseOperationController from '@/actions/App/Http/Controllers/Databases/DatabaseOperationController';
import ConfirmDialog from '@/components/confirm-dialog';
import type { DialogControl } from '@/components/databases/actions/dialog-control';
import { membersOf } from '@/components/databases/describe';
import { runAction } from '@/lib/run-action';
import type { DatabaseSummary } from '@/types';

export default function RebootDatabaseDialog({
    database,
    ...control
}: DialogControl & { database: DatabaseSummary }) {
    const clustered = membersOf(database).length > 1;

    return (
        <ConfirmDialog
            {...control}
            title={`Reboot the servers of ${database.name}?`}
            description={
                clustered
                    ? 'Servers reboot one at a time, read-only nodes and standbys first. Each must serve again before the next one goes. Then the primary moves to a standby and its server reboots last, so clients may reconnect once.'
                    : `${database.name} has a single member, so it is unavailable until its server is back, usually one or two minutes.`
            }
            confirmLabel="Reboot"
            destructive={!clustered}
            onConfirm={() =>
                runAction(
                    DatabaseOperationController.reboot({
                        database: database.id,
                    }),
                    {
                        success: 'Rolling reboot started',
                    },
                )
            }
        />
    );
}
