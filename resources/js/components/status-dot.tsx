import { statusLabel, statusTone } from '@/lib/status';
import type { StatusTone } from '@/lib/status';
import { cn } from '@/lib/utils';

const colors: Record<StatusTone, string> = {
    success: 'bg-green-500',
    progress: 'bg-blue-500',
    warning: 'bg-amber-500',
    danger: 'bg-red-500',
    neutral: 'bg-neutral-400',
};

export default function StatusDot({
    status,
    className,
}: {
    status: string;
    className?: string;
}) {
    const tone = statusTone(status);

    return (
        <span
            role="img"
            aria-label={statusLabel(status)}
            className={cn(
                'inline-block size-2 shrink-0 rounded-full',
                colors[tone],
                tone === 'progress' && 'animate-pulse',
                className,
            )}
        />
    );
}
