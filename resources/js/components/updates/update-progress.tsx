import { Check, Circle, LoaderCircle, Minus, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Progress } from '@/components/ui/progress';
import { durationOf } from '@/lib/format';
import type { UpdateStatus, UpdateStepState } from '@/types';

const stepIcons: Record<UpdateStepState, ReactNode> = {
    done: <Check className="size-4 text-emerald-500" />,
    running: <LoaderCircle className="size-4 animate-spin" />,
    pending: <Circle className="size-4 text-muted-foreground" />,
    skipped: <Minus className="size-4 text-muted-foreground" />,
    failed: <X className="size-4 text-destructive" />,
};

export default function UpdateProgress({ status }: { status: UpdateStatus }) {
    const steps = status.steps.filter((step) => step.state !== 'skipped');
    const reached = steps.filter((step) => step.state !== 'pending').length;

    return (
        <div className="space-y-4 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div className="font-medium">Updating to {status.to}</div>
                    <div className="text-sm text-muted-foreground">
                        {durationOf(status.startedAt, null)}
                    </div>
                </div>
                <span className="rounded-md border px-2 py-0.5 text-xs font-medium">
                    Step {reached} of {steps.length}
                </span>
            </div>
            <Progress value={(reached / Math.max(steps.length, 1)) * 100} />
            <div>
                {steps.map((step) => (
                    <div
                        key={step.title}
                        className={`flex items-center gap-3 py-1.5 text-sm ${step.state === 'pending' ? 'text-muted-foreground' : ''} ${step.state === 'running' ? 'font-medium' : ''}`}
                    >
                        {stepIcons[step.state]}
                        {step.title}
                    </div>
                ))}
            </div>
            <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                Databasify goes offline for about a minute while it restarts.
                This page reconnects on its own. Your databases keep running the
                whole time.
            </p>
        </div>
    );
}

export function UpdateStarting({ version }: { version: string | null }) {
    return (
        <div className="flex items-center gap-3 rounded-xl border bg-card p-4">
            <LoaderCircle className="size-5 animate-spin" />
            <div>
                <div className="font-medium">
                    Starting the update{version ? ` to ${version}` : ''}
                </div>
                <div className="text-sm text-muted-foreground">
                    The server picks up the request within a few seconds.
                </div>
            </div>
        </div>
    );
}
