import { Square } from 'lucide-react';
import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { runAction } from '@/lib/run-action';

const UNSTOPPABLE = ['recovering', 'migrating', 'deleting'];

export default function StopOperationButton({
    route,
    name,
    status,
    runningTask,
}: {
    route: { url: string; method: 'post' };
    name: string;
    status: string;
    runningTask: boolean;
}) {
    if (!runningTask || UNSTOPPABLE.includes(status)) {
        return null;
    }

    return (
        <ConfirmDialog
            trigger={
                <Button size="sm" variant="outline">
                    <Square /> Stop
                </Button>
            }
            title={`Stop ${name}?`}
            description="The running step is cancelled and the operation ends as failed: a new node is removed, a new database is erased, anything else returns to ready."
            confirmLabel="Stop"
            destructive
            onConfirm={() => runAction(route, { success: 'Stopping…' })}
        />
    );
}
