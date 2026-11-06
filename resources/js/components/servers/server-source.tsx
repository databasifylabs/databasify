import BrandIcon from '@/components/brand-icon';
import ManualMark from '@/components/servers/manual-mark';
import type { Server } from '@/types';

export function ServerMark({ server }: { server: Server }) {
    return server.provider ? (
        <BrandIcon
            group="server-providers"
            src={server.provider.type.icon}
            size="sm"
        />
    ) : (
        <ManualMark />
    );
}

export default function ServerSource({ server }: { server: Server }) {
    const provider = server.provider;
    const details = provider
        ? [provider.name, server.region].filter(Boolean).join(' · ')
        : 'Added over SSH';

    return (
        <span className="flex min-w-0 items-center gap-2.5">
            <ServerMark server={server} />
            <span className="min-w-0">
                <span className="block truncate text-sm font-medium">
                    {provider ? provider.type.label : 'Manual'}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {details}
                </span>
            </span>
        </span>
    );
}
