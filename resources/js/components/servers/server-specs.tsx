import { Cpu, HardDrive, MemoryStick } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatMemory, formatUsableMemory } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { Server } from '@/types';

type Specs = Pick<Server, 'cpus' | 'memory_mb' | 'disk_gb'>;

export function ServerSpecItems({
    server,
    iconClassName = 'size-3',
}: {
    server: Specs;
    iconClassName?: string;
}) {
    return (
        <>
            <span className="flex items-center gap-1">
                <Cpu className={iconClassName} />
                {server.cpus ?? '—'} vCPU
            </span>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span className="flex items-center gap-1">
                        <MemoryStick className={iconClassName} />
                        {formatMemory(server.memory_mb)}
                    </span>
                </TooltipTrigger>
                {server.memory_mb !== null && (
                    <TooltipContent>
                        {formatUsableMemory(server.memory_mb)}
                    </TooltipContent>
                )}
            </Tooltip>
            <span className="flex items-center gap-1">
                <HardDrive className={iconClassName} />
                {server.disk_gb ?? '—'} GB
            </span>
        </>
    );
}

export default function ServerSpecs({
    server,
    className,
}: {
    server: Specs;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground',
                className,
            )}
        >
            <ServerSpecItems server={server} />
        </div>
    );
}
