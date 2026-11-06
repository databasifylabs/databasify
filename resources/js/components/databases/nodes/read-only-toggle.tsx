import { ChevronRight } from 'lucide-react';
import { readOnlyNodesOf } from '@/components/databases/describe';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { DatabaseSummary } from '@/types';

export default function ReadOnlyToggle({
    database,
    open,
    onToggle,
}: {
    database: DatabaseSummary;
    open: boolean;
    onToggle: () => void;
}) {
    const count = readOnlyNodesOf(database).length;

    if (count === 0) {
        return null;
    }

    return (
        <Button
            type="button"
            variant="ghost"
            size="sm"
            className="h-6 gap-1 px-1.5 text-xs text-muted-foreground"
            aria-expanded={open}
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                onToggle();
            }}
        >
            <ChevronRight
                className={cn(
                    'size-3.5 transition-transform',
                    open && 'rotate-90',
                )}
            />
            {count} read-only
        </Button>
    );
}
