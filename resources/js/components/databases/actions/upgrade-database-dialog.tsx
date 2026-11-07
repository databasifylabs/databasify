import DatabaseOperationController from '@/actions/App/Http/Controllers/Databases/DatabaseOperationController';
import ConfirmDialog from '@/components/confirm-dialog';
import type { DialogControl } from '@/components/databases/actions/dialog-control';
import { updateTitleOf } from '@/components/databases/describe';
import { runAction } from '@/lib/run-action';
import type { DatabaseSummary } from '@/types';

export default function UpgradeDatabaseDialog({
    database,
    ...control
}: DialogControl & { database: DatabaseSummary }) {
    return (
        <ConfirmDialog
            {...control}
            title={updateTitleOf(database)}
            description="Each node restarts on the new image in turn, standbys first. Clients may reconnect once when the primary moves."
            confirmLabel="Upgrade"
            onConfirm={() =>
                runAction(
                    DatabaseOperationController.upgrade({
                        database: database.id,
                    }),
                    {
                        success: 'Upgrade started',
                    },
                )
            }
        />
    );
}
