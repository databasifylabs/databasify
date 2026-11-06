import { Link } from '@inertiajs/react';
import { CornerDownRight } from 'lucide-react';
import type { ReactNode } from 'react';
import DatabaseNodeController from '@/actions/App/Http/Controllers/Databases/DatabaseNodeController';
import { readOnlyNodesOf } from '@/components/databases/describe';
import NodeDots from '@/components/databases/nodes/node-dots';
import { TableCell, TableRow } from '@/components/ui/table';
import { cn } from '@/lib/utils';
import type { DatabaseSummary, DatabaseNode } from '@/types';

export default function ReadOnlyRows({
    database,
    open,
    firstCellClassName,
    trailing,
}: {
    database: DatabaseSummary;
    open: boolean;
    firstCellClassName?: string;
    trailing: (node: DatabaseNode) => ReactNode;
}) {
    if (!open) {
        return null;
    }

    return readOnlyNodesOf(database).map((node) => (
        <TableRow key={`node-${node.id}`} className="group">
            <TableCell className={cn('py-3', firstCellClassName)}>
                <Link
                    href={DatabaseNodeController.show({
                        database: database.id,
                        node: node.id,
                    })}
                    className="flex items-center gap-3 pl-5"
                >
                    <CornerDownRight className="size-4 shrink-0 text-muted-foreground" />
                    <div className="min-w-0">
                        <div className="truncate font-medium group-hover:underline">
                            {node.name}
                        </div>
                        <div className="truncate font-mono text-xs text-muted-foreground">
                            {node.domain ?? node.public_ip}
                        </div>
                    </div>
                </Link>
            </TableCell>
            <TableCell className="text-muted-foreground">
                Read-only node
            </TableCell>
            <TableCell>
                <NodeDots nodes={[node]} />
            </TableCell>
            {trailing(node)}
        </TableRow>
    ));
}
