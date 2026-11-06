import BrandIcon from '@/components/brand-icon';
import ManualMark from '@/components/servers/manual-mark';
import AppLogoIcon from '@/components/shell/app-logo-icon';
import { cn } from '@/lib/utils';
import type { NetworkSummary } from '@/types';

type Size = 'xs' | 'sm';

export function isDatabasifyNetwork(network: NetworkSummary): boolean {
    return network.kind === 'wireguard';
}

export function networkSource(network: NetworkSummary): string {
    if (isDatabasifyNetwork(network)) {
        return 'Databasify network · encrypted';
    }

    return network.provider_label
        ? [network.provider_label, network.provider, network.region]
              .filter(Boolean)
              .join(' · ')
        : 'Manual';
}

export function DatabasifyMark({ size = 'sm' }: { size?: Size }) {
    return (
        <span
            className={cn(
                'flex shrink-0 items-center justify-center bg-primary text-primary-foreground',
                size === 'xs' ? 'size-6 rounded-md' : 'size-8 rounded-lg',
            )}
        >
            <AppLogoIcon
                className={cn(
                    'fill-current',
                    size === 'xs' ? 'size-3.5' : 'size-5',
                )}
            />
        </span>
    );
}

export default function NetworkMark({
    network,
    size = 'sm',
}: {
    network: NetworkSummary;
    size?: Size;
}) {
    if (isDatabasifyNetwork(network)) {
        return <DatabasifyMark size={size} />;
    }

    return network.provider_type ? (
        <BrandIcon
            group="server-providers"
            src={network.provider_icon}
            size={size}
        />
    ) : (
        <ManualMark size={size} />
    );
}
