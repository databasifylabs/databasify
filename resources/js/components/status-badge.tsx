import StatusDot from '@/components/status-dot';
import { statusLabel, statusTone } from '@/lib/status';
import type { StatusTone } from '@/lib/status';
import { cn } from '@/lib/utils';

const tones: Record<StatusTone, string> = {
    success:
        'border-green-600/20 bg-green-50 text-green-700 dark:border-green-400/20 dark:bg-green-400/10 dark:text-green-400',
    progress:
        'border-blue-600/20 bg-blue-50 text-blue-700 dark:border-blue-400/20 dark:bg-blue-400/10 dark:text-blue-400',
    warning:
        'border-amber-600/20 bg-amber-50 text-amber-700 dark:border-amber-400/20 dark:bg-amber-400/10 dark:text-amber-400',
    danger: 'border-red-600/20 bg-red-50 text-red-700 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-400',
    neutral: 'border-border bg-muted text-muted-foreground dark:bg-muted/50',
};

export default function StatusBadge({
    status,
    className,
}: {
    status: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-0.5 text-xs font-medium whitespace-nowrap',
                tones[statusTone(status)],
                className,
            )}
        >
            <StatusDot status={status} className="size-1.5" />
            {statusLabel(status)}
        </span>
    );
}
