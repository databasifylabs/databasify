import DatabaseOperationController from '@/actions/App/Http/Controllers/Databases/DatabaseOperationController';
import ConfirmDialog from '@/components/confirm-dialog';
import type { DialogControl } from '@/components/databases/actions/dialog-control';
import { runAction } from '@/lib/run-action';
import type { DatabaseSummary } from '@/types';

export default function FailoverDialog({
    database,
    ...control
}: DialogControl & { database: DatabaseSummary }) {
    return (
        <ConfirmDialog
            {...control}
            title="Move the primary to a standby?"
            description="Writes pause for a few seconds while a standby is promoted."
            confirmLabel="Fail over"
            onConfirm={() =>
                runAction(
                    DatabaseOperationController.failover({
                        database: database.id,
                    }),
                    {
                        success: 'Failover started',
                    },
                )
            }
        />
    );
}
