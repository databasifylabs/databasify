import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

export default function AppVersion({
    withName = false,
    className,
}: {
    withName?: boolean;
    className?: string;
}) {
    const { name, version } = usePage().props;

    return (
        <span
            className={cn(
                'text-xs text-muted-foreground tabular-nums',
                className,
            )}
        >
            {withName ? `${name} ${version}` : `v${version}`}
        </span>
    );
}
