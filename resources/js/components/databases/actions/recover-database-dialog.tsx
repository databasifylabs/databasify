import { useState } from 'react';
import DatabaseOperationController from '@/actions/App/Http/Controllers/Databases/DatabaseOperationController';
import ConfirmDialog from '@/components/confirm-dialog';
import type { DialogControl } from '@/components/databases/actions/dialog-control';
import { Switch } from '@/components/ui/switch';
import { runAction } from '@/lib/run-action';
import type { DatabaseSummary } from '@/types';

export default function RecoverDatabaseDialog({
    database,
    ...control
}: DialogControl & { database: DatabaseSummary }) {
    const [othersDown, setOthersDown] = useState(false);

    return (
        <ConfirmDialog
            {...control}
            title="Recover the database?"
            description="When the database has lost quorum, the most up-to-date reachable node becomes primary and the others rejoin it. When it still has a primary and a majority, only the members outside it rejoin."
            confirmLabel="Recover"
            onConfirm={() =>
                runAction(
                    DatabaseOperationController.recover({
                        database: database.id,
                    }),
                    {
                        data: { others_down: othersDown },
                        success: 'Recovery started',
                    },
                )
            }
        >
            <div className="rounded-lg border p-3 text-sm">
                <label className="flex items-start gap-3">
                    <Switch
                        checked={othersDown}
                        onCheckedChange={setOthersDown}
                    />
                    <span>
                        <span className="font-medium">
                            The unreachable members are down
                        </span>
                        <span className="block text-muted-foreground">
                            Needed when Databasify reaches only a minority of
                            members. If they are still running but cut off from
                            Databasify, recovering creates a second primary.
                        </span>
                    </span>
                </label>
            </div>
        </ConfirmDialog>
    );
}
