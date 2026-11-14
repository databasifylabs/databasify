import { CircleStop } from 'lucide-react';
import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { runAction } from '@/lib/run-action';
import type { RouteDefinition } from '@/wayfinder';

export default function StopOperationButton({
    stop,
    operation,
}: {
    stop: RouteDefinition<'post'>;
    operation: string;
}) {
    const label = operation.toLowerCase();

    return (
        <ConfirmDialog
            trigger={
                <Button variant="outline" size="sm">
                    <CircleStop />
                    Stop
                </Button>
            }
            title={`Stop ${label}?`}
            description={
                <>
                    <p>
                        The step running now finishes, then nothing else runs.
                    </p>
                    <p>
                        You can run it again or delete what it was working on.
                    </p>
                </>
            }
            confirmLabel="Stop"
            destructive
            onConfirm={() =>
                runAction(stop, {
                    success: `Stopping ${label}`,
                })
            }
        />
    );
}
