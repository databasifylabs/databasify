import { Link } from '@inertiajs/react';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import BrandIcon from '@/components/brand-icon';
import { Badge } from '@/components/ui/badge';
import type { Server } from '@/types';

export default function ServerDatabase({ server }: { server: Server }) {
    if (server.database) {
        return (
            <Link
                href={DatabaseController.show({ database: server.database.id })}
                className="inline-flex items-center gap-2 whitespace-nowrap hover:underline"
            >
                <BrandIcon
                    group="database-providers"
                    src={server.database.engine_icon}
                    size="sm"
                />
                <span className="text-sm font-medium">
                    {server.database.name}
                </span>
                {server.database.kind === 'read_only' && (
                    <Badge variant="outline">read-only</Badge>
                )}
            </Link>
        );
    }

    return server.status === 'ready' ? (
        <Badge variant="secondary">Available</Badge>
    ) : (
        <span className="text-sm text-muted-foreground">—</span>
    );
}
