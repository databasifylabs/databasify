import { Head, Link } from '@inertiajs/react';
import { Cloud, Network, Plus } from 'lucide-react';
import { useState } from 'react';
import NetworkController from '@/actions/App/Http/Controllers/Providers/NetworkController';
import ServerProviderController from '@/actions/App/Http/Controllers/Providers/ServerProviderController';
import ConfirmDialog from '@/components/confirm-dialog';
import EditAccountDialog from '@/components/providers/edit-account-dialog';
import DeleteButton from '@/components/delete-button';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import BrandIcon from '@/components/brand-icon';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { runAction } from '@/lib/run-action';
import NetworkMark, {
    isDatabasifyNetwork,
    networkSource,
} from '@/components/servers/network-mark';
import type { NetworkSummary, ProviderType } from '@/types';

type ProviderAccount = {
    id: string;
    name: string;
    type: ProviderType;
    servers_count: number;
    networks_count: number;
};

type NetworkRow = NetworkSummary & {
    provider_label: string | null;
    servers_count: number;
    connected_servers_count: number;
};

function Accounts({ providers }: { providers: ProviderAccount[] }) {
    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="pl-4">Name</TableHead>
                        <TableHead>Provider</TableHead>
                        <TableHead>Servers</TableHead>
                        <TableHead>Networks</TableHead>
                        <TableHead className="pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {providers.map((provider) => (
                        <TableRow key={provider.id}>
                            <TableCell className="pl-4">
                                <div className="flex items-center gap-3">
                                    <BrandIcon
                                        group="server-providers"
                                        src={provider.type.icon}
                                        size="sm"
                                    />
                                    <span className="font-medium">
                                        {provider.name}
                                    </span>
                                </div>
                            </TableCell>
                            <TableCell>{provider.type.label}</TableCell>
                            <TableCell className="tabular-nums">
                                {provider.servers_count}
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {provider.networks_count}
                            </TableCell>
                            <TableCell className="pr-4 text-right">
                                <EditAccountDialog
                                    name={provider.name}
                                    form={ServerProviderController.update.form(
                                        provider.id,
                                    )}
                                    secrets={provider.type.form
                                        .filter(
                                            (field) => field.type !== 'select',
                                        )
                                        .map((field) => ({
                                            name: `credentials[${field.name}]`,
                                            label: field.label,
                                            secret: field.type === 'password',
                                        }))}
                                    description="New credentials are checked before saving and must belong to the same account, so every server stays reachable."
                                />
                                <ConfirmDialog
                                    trigger={
                                        <DeleteButton
                                            aria-label={`Remove ${provider.name}`}
                                        />
                                    }
                                    title={`Remove ${provider.name}?`}
                                    description="Databasify forgets the token. Nothing changes in the account itself."
                                    confirmLabel="Remove provider"
                                    destructive
                                    onConfirm={() =>
                                        runAction(
                                            ServerProviderController.destroy(
                                                provider.id,
                                            ),
                                            {
                                                success: `Removed ${provider.name}`,
                                            },
                                        )
                                    }
                                />
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function RemoveNetwork({ network }: { network: NetworkRow }) {
    const [destroyAtProvider, setDestroyAtProvider] = useState(true);

    return (
        <ConfirmDialog
            trigger={<DeleteButton aria-label={`Remove ${network.name}`} />}
            title={`Remove ${network.name}?`}
            description={
                network.provider_label
                    ? 'Databasify forgets this network.'
                    : 'Databasify forgets this network. Nothing on your servers changes.'
            }
            confirmLabel="Remove network"
            destructive
            onConfirm={() =>
                runAction(NetworkController.destroy(network.id), {
                    data: {
                        destroy_at_provider:
                            network.provider_label !== null &&
                            destroyAtProvider,
                    },
                    success: `Removed ${network.name}`,
                })
            }
        >
            {network.provider_label && (
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={destroyAtProvider}
                        onCheckedChange={(checked) =>
                            setDestroyAtProvider(checked === true)
                        }
                    />
                    Also delete it at {network.provider_label}
                </label>
            )}
        </ConfirmDialog>
    );
}

function Networks({ networks }: { networks: NetworkRow[] }) {
    return (
        <div className="overflow-hidden rounded-xl border bg-card">
            <Table>
                <TableHeader>
                    <TableRow className="hover:bg-transparent">
                        <TableHead className="pl-4">Name</TableHead>
                        <TableHead>Range</TableHead>
                        <TableHead className="hidden sm:table-cell">
                            Source
                        </TableHead>
                        <TableHead>Servers</TableHead>
                        <TableHead className="pr-4" />
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {networks.map((network) => (
                        <TableRow key={network.id}>
                            <TableCell className="pl-4 font-medium">
                                {network.name}
                            </TableCell>
                            <TableCell className="font-mono text-sm">
                                {network.cidr}
                            </TableCell>
                            <TableCell className="hidden sm:table-cell">
                                <span className="inline-flex items-center gap-2">
                                    <NetworkMark network={network} size="xs" />
                                    {networkSource(network)}
                                </span>
                            </TableCell>
                            <TableCell className="tabular-nums">
                                {network.servers_count}
                                {isDatabasifyNetwork(network) &&
                                    network.servers_count > 0 && (
                                        <span className="ml-1.5 text-xs text-muted-foreground">
                                            {network.connected_servers_count ===
                                            network.servers_count
                                                ? 'all connected'
                                                : `${network.servers_count - network.connected_servers_count} not connected`}
                                        </span>
                                    )}
                            </TableCell>
                            <TableCell className="pr-4 text-right">
                                {network.servers_count === 0 && (
                                    <RemoveNetwork network={network} />
                                )}
                            </TableCell>
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

export default function Providers({
    providers,
    networks,
}: {
    providers: ProviderAccount[];
    networks: NetworkRow[];
}) {
    return (
        <>
            <Head title="Providers" />
            <div className="mx-auto w-full max-w-6xl space-y-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Providers"
                        description="Cloud accounts Databasify can create servers in."
                    />
                    <Button asChild>
                        <Link href={ServerProviderController.create()}>
                            <Plus /> Add provider
                        </Link>
                    </Button>
                </div>

                {providers.length === 0 ? (
                    <EmptyState
                        icon={Cloud}
                        title="No providers yet"
                        description="Add a cloud account to create servers in its private network from Databasify."
                    >
                        <Button asChild>
                            <Link href={ServerProviderController.create()}>
                                <Plus /> Add your first provider
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <Accounts providers={providers} />
                )}

                {networks.length > 0 && (
                    <section className="space-y-3">
                        <div className="flex items-center gap-2">
                            <Network className="size-4 text-muted-foreground" />
                            <h2 className="font-semibold">Private networks</h2>
                        </div>
                        <Networks networks={networks} />
                    </section>
                )}
            </div>
        </>
    );
}

Providers.layout = {
    breadcrumbs: [
        { title: 'Providers', href: ServerProviderController.index() },
    ],
};
