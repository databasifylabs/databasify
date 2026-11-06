import { Loader2 } from 'lucide-react';
import { cn } from '@/lib/utils';

export default function OperationLabel({
    operation,
    className,
}: {
    operation: string;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'flex min-w-0 items-center gap-1.5 text-xs text-primary',
                className,
            )}
        >
            <Loader2 className="size-3 shrink-0 animate-spin" />
            <span className="truncate">{operation}</span>
        </span>
    );
}
