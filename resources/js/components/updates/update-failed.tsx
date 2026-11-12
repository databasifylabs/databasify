import { CircleAlert } from 'lucide-react';
import UpdateController from '@/actions/App/Http/Controllers/Updates/UpdateController';
import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { runAction } from '@/lib/run-action';
import type { UpdateStatus } from '@/types';

function runningVersionAfter(status: UpdateStatus): string {
    if (!status.from) {
        return 'Databasify did not finish starting.';
    }

    return status.rolledBack
        ? `Databasify rolled back and is running ${status.from}.`
        : `Databasify is still running ${status.from}.`;
}

function failureSummary(status: UpdateStatus): string {
    const running = runningVersionAfter(status);
    const step = status.steps.find((candidate) => candidate.state === 'failed');

    return step ? `${running} The step "${step.title}" failed.` : running;
}

export default function UpdateFailed({
    status,
    logTail,
}: {
    status: UpdateStatus;
    logTail: string[];
}) {
    const log = logTail.join('\n');

    return (
        <div className="space-y-3 rounded-xl border border-destructive/50 bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                    <div className="flex size-10 items-center justify-center rounded-md bg-destructive/15 text-destructive">
                        <CircleAlert className="size-5" />
                    </div>
                    <div>
                        <div className="font-medium">
                            The update to {status.to} failed
                        </div>
                        <div className="text-sm text-muted-foreground">
                            {failureSummary(status)}
                        </div>
                    </div>
                </div>
                <div className="flex gap-2">
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button variant="outline">Show full log</Button>
                        </DialogTrigger>
                        <DialogContent className="sm:max-w-2xl">
                            <DialogHeader>
                                <DialogTitle>Update log</DialogTitle>
                            </DialogHeader>
                            <pre className="max-h-80 overflow-auto rounded-lg bg-muted p-3 font-mono text-xs whitespace-pre-wrap text-muted-foreground">
                                {log}
                            </pre>
                            <DialogFooter>
                                <Button variant="outline" asChild>
                                    <a href={UpdateController.log().url}>
                                        Download
                                    </a>
                                </Button>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                    <ConfirmDialog
                        trigger={<Button>Try again</Button>}
                        title={`Try the update to ${status.to} again?`}
                        description="Databasify pulls the new release and restarts. The app is offline for about a minute and running jobs finish first; your databases keep running."
                        confirmLabel="Update"
                        onConfirm={() => runAction(UpdateController.store())}
                    />
                </div>
            </div>
            {status.message && !status.rolledBack && (
                <p className="text-sm text-muted-foreground">
                    {status.message}
                </p>
            )}
            <pre className="rounded-lg bg-muted p-3 font-mono text-xs whitespace-pre-wrap text-muted-foreground">
                {logTail.slice(-4).join('\n')}
            </pre>
        </div>
    );
}
