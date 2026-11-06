import { ChoiceRow } from '@/components/form/choice';
import {
    DatabasifyMark,
    networkSource,
} from '@/components/servers/network-mark';
import type { NetworkSummary } from '@/types';

export default function DatabasifyNetworkChoices({
    networks,
    selected,
    onSelect,
    valueOf,
    freshValue,
}: {
    networks: NetworkSummary[];
    selected: string;
    onSelect: (value: string) => void;
    valueOf: (network: NetworkSummary) => string;
    freshValue: string;
}) {
    return (
        <>
            {networks.map((network) => (
                <ChoiceRow
                    key={network.id}
                    selected={selected === valueOf(network)}
                    onSelect={() => onSelect(valueOf(network))}
                >
                    <DatabasifyMark />
                    <span className="min-w-0 flex-1">
                        <span className="flex items-baseline justify-between gap-2">
                            <span className="truncate font-medium">
                                {network.name}
                            </span>
                            <span className="shrink-0 font-mono text-xs text-muted-foreground">
                                {network.cidr}
                            </span>
                        </span>
                        <span className="block truncate text-xs text-muted-foreground">
                            {networkSource(network)}
                        </span>
                    </span>
                </ChoiceRow>
            ))}
            <ChoiceRow
                selected={selected === freshValue}
                onSelect={() => onSelect(freshValue)}
            >
                <DatabasifyMark />
                <span className="min-w-0 flex-1">
                    <span className="block font-medium">
                        New Databasify network
                    </span>
                    <span className="block text-xs text-muted-foreground">
                        Encrypted WireGuard mesh, works across providers
                    </span>
                </span>
            </ChoiceRow>
        </>
    );
}
