import { Plus } from 'lucide-react';
import { useState } from 'react';
import { ChoiceRow } from '@/components/form/choice';
import InputError from '@/components/input-error';
import DatabasifyNetworkChoices from '@/components/servers/databasify-network-choices';
import NetworkBlockField from '@/components/servers/network-block-field';
import NetworkMark, {
    isDatabasifyNetwork,
    networkSource,
} from '@/components/servers/network-mark';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { NetworkSummary } from '@/types';

const none = 'none';
const fresh = 'new';
const freshDatabasify = 'wireguard';

export default function PrivateNetworkFields({
    networks,
    network = null,
    privateIp = null,
    errors,
}: {
    networks: NetworkSummary[];
    network?: NetworkSummary | null;
    privateIp?: string | null;
    errors: Partial<Record<string, string>>;
}) {
    const [choice, setChoice] = useState(network ? network.id : none);
    const [cidr, setCidr] = useState('');
    const [address, setAddress] = useState(privateIp ?? '');
    const chosen = networks.find((entry) => entry.id === choice);
    const range = choice === fresh ? cidr : chosen?.cidr;
    const assigned =
        choice === freshDatabasify ||
        (chosen !== undefined && isDatabasifyNetwork(chosen));

    return (
        <div className="space-y-4">
            <input
                type="hidden"
                name="network"
                value={choice === none ? '' : choice}
            />
            <div
                className="grid grid-cols-1 gap-2 md:grid-cols-2"
                role="radiogroup"
            >
                {networks
                    .filter((entry) => !isDatabasifyNetwork(entry))
                    .map((entry) => (
                        <ChoiceRow
                            key={entry.id}
                            selected={choice === entry.id}
                            onSelect={() => setChoice(entry.id)}
                        >
                            <NetworkMark network={entry} />
                            <span className="min-w-0 flex-1">
                                <span className="flex items-baseline justify-between gap-2">
                                    <span className="truncate font-medium">
                                        {entry.name}
                                    </span>
                                    <span className="shrink-0 font-mono text-xs text-muted-foreground">
                                        {entry.cidr}
                                    </span>
                                </span>
                                <span className="block truncate text-xs text-muted-foreground">
                                    {networkSource(entry)}
                                </span>
                            </span>
                        </ChoiceRow>
                    ))}
                <ChoiceRow
                    selected={choice === fresh}
                    onSelect={() => setChoice(fresh)}
                >
                    <Plus className="size-4 text-muted-foreground" />
                    <span className="font-medium">New network</span>
                </ChoiceRow>
                <DatabasifyNetworkChoices
                    networks={networks.filter(isDatabasifyNetwork)}
                    selected={choice}
                    onSelect={setChoice}
                    valueOf={(entry) => entry.id}
                    freshValue={freshDatabasify}
                />
                <ChoiceRow
                    selected={choice === none}
                    onSelect={() => setChoice(none)}
                >
                    <span className="min-w-0 flex-1">
                        <span className="block font-medium">None</span>
                        <span className="block text-xs text-muted-foreground">
                            Single-node databases only
                        </span>
                    </span>
                </ChoiceRow>
            </div>
            <InputError message={errors.network} />

            {choice === freshDatabasify && (
                <NetworkBlockField error={errors.network_block} />
            )}

            {choice !== none && !assigned && (
                <div className="grid grid-cols-1 gap-4 rounded-xl border bg-muted/30 p-4 md:grid-cols-3">
                    {choice === fresh && (
                        <>
                            <div className="space-y-1.5">
                                <Label htmlFor="network_name">
                                    Network name
                                </Label>
                                <Input
                                    id="network_name"
                                    name="network_name"
                                    placeholder="hetzner-fsn1"
                                    className="bg-background"
                                />
                                <InputError message={errors.network_name} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="network_cidr">Range</Label>
                                <Input
                                    id="network_cidr"
                                    name="network_cidr"
                                    value={cidr}
                                    onChange={(event) =>
                                        setCidr(event.target.value.trim())
                                    }
                                    placeholder="10.0.1.0/24"
                                    className="bg-background font-mono"
                                />
                                <InputError message={errors.network_cidr} />
                            </div>
                        </>
                    )}
                    <div className="space-y-1.5">
                        <Label htmlFor="private_ip">Private IP</Label>
                        <Input
                            id="private_ip"
                            name="private_ip"
                            value={address}
                            onChange={(event) =>
                                setAddress(event.target.value.trim())
                            }
                            placeholder={range ? `In ${range}` : '10.0.1.2'}
                            className="bg-background font-mono"
                        />
                        <InputError message={errors.private_ip} />
                    </div>
                </div>
            )}
        </div>
    );
}
