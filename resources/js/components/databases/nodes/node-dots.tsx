import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { DatabaseNode } from '@/types';

export function roleOf(node: DatabaseNode): string {
    if (node.role === 'unknown') {
        return 'unreachable';
    }

    if (node.role === 'offline') {
        return 'offline';
    }

    return node.kind === 'read_only'
        ? 'read-only'
        : node.role === 'primary'
          ? 'primary'
          : 'standby';
}

export const roleColors: Record<string, string> = {
    primary: 'bg-emerald-500 ring-emerald-600',
    standby: 'bg-sky-500 ring-sky-600',
    'read-only': 'bg-violet-500 ring-violet-600',
    offline: 'bg-amber-500 ring-amber-600',
    unreachable: 'bg-zinc-300 ring-zinc-400 dark:bg-zinc-700',
};

export default function NodeDots({ nodes }: { nodes: DatabaseNode[] }) {
    return (
        <div className="flex items-center gap-1">
            {nodes.map((node) => (
                <Tooltip key={node.id}>
                    <TooltipTrigger asChild>
                        <span
                            className={cn(
                                'size-3 rounded-sm ring-1 ring-inset',
                                roleColors[roleOf(node)],
                            )}
                        />
                    </TooltipTrigger>
                    <TooltipContent>
                        {node.name} · {roleOf(node)}
                    </TooltipContent>
                </Tooltip>
            ))}
        </div>
    );
}
