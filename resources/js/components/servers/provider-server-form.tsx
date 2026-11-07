import { Form, Link } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Cloud,
    Cpu,
    HardDrive,
    MemoryStick,
    Network,
    Plus,
} from 'lucide-react';
import { useState } from 'react';
import ProviderCatalogController from '@/actions/App/Http/Controllers/Providers/ProviderCatalogController';
import ProviderServerController from '@/actions/App/Http/Controllers/Providers/ProviderServerController';
import ServerProviderController from '@/actions/App/Http/Controllers/Providers/ServerProviderController';
import EmptyState from '@/components/empty-state';
import { ChoiceRow, FormSection } from '@/components/form/choice';
import SubmitPanel from '@/components/form/submit-panel';
import InputError from '@/components/input-error';
import PlanFamilyIcon from '@/components/providers/plan-family-icon';
import BrandIcon from '@/components/brand-icon';
import RegionFlag from '@/components/providers/region-flag';
import DatabasifyNetworkChoices from '@/components/servers/databasify-network-choices';
import NetworkBlockField from '@/components/servers/network-block-field';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useCatalog } from '@/hooks/use-catalog';
import { formatMemory } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    NetworkSummary,
    OperatingSystemOption,
    ProviderOption,
} from '@/types';

type Region = { id: string; name: string; country: string | null };
type Plan = {
    id: string;
    family: string;
    cpus: number;
    memory_mb: number;
    disk_gb: number;
    monthly_price: number | null;
};
type ProviderNetwork = {
    external_id: string;
    name: string;
    cidr: string;
    is_default: boolean;
};

const noNetwork = 'none';
const newNetwork = 'new';
const newDatabasifyNetwork = 'databasify:new';

function databasifyValueOf(network: NetworkSummary): string {
    return `databasify:${network.id}`;
}

function Waiting({ text }: { text: string }) {
    return (
        <p className="rounded-xl border border-dashed px-4 py-6 text-center text-sm text-muted-foreground">
            {text}
        </p>
    );
}

function Spec({ icon: Icon, value }: { icon: LucideIcon; value: string }) {
    return (
        <span className="inline-flex items-center gap-1">
            <Icon className="size-3 text-muted-foreground" />
            {value}
        </span>
    );
}

function SizeCard({
    plan,
    selected,
    onSelect,
}: {
    plan: Plan;
    selected: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            title={plan.family}
            onClick={onSelect}
            className={cn(
                'flex h-16 items-center gap-2.5 rounded-lg border bg-background px-3 text-left transition',
                selected
                    ? 'border-primary shadow-sm ring-1 ring-primary'
                    : 'hover:border-foreground/25 hover:bg-muted/30',
            )}
        >
            <PlanFamilyIcon family={plan.family} size="xs" />
            <span className="min-w-0 flex-1 space-y-0.5">
                <span className="flex items-baseline justify-between gap-2">
                    <span className="truncate text-xs text-muted-foreground">
                        {plan.family}
                    </span>
                    <span className="shrink-0 text-sm font-semibold tabular-nums">
                        {plan.monthly_price === null
                            ? '—'
                            : `$${plan.monthly_price}`}
                        <span className="text-xs font-normal text-muted-foreground">
                            /mo
                        </span>
                    </span>
                </span>
                <span className="flex flex-wrap gap-x-2.5 text-xs">
                    <Spec icon={Cpu} value={`${plan.cpus} vCPU`} />
                    <Spec
                        icon={MemoryStick}
                        value={formatMemory(plan.memory_mb)}
                    />
                    <Spec icon={HardDrive} value={`${plan.disk_gb} GB`} />
                </span>
            </span>
        </button>
    );
}

export default function ProviderServerForm({
    providers,
    operatingSystems,
    databasifyNetworks,
}: {
    providers: ProviderOption[];
    operatingSystems: OperatingSystemOption[];
    databasifyNetworks: NetworkSummary[];
}) {
    const [providerId, setProviderId] = useState(providers[0]?.id ?? null);
    const [region, setRegion] = useState('');
    const [plan, setPlan] = useState('');
    const [network, setNetwork] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [system, setSystem] = useState<string | null>(null);
    const provider = providers.find((entry) => entry.id === providerId);
    const offeredSystems = operatingSystems.filter(
        (entry) => provider && entry.providers.includes(provider.type.value),
    );
    const chosenSystem =
        offeredSystems.find((entry) => entry.value === system) ??
        offeredSystems.find((entry) => entry.recommended) ??
        offeredSystems[0];

    const regions = useCatalog<Region>(
        provider
            ? ProviderCatalogController.regions.url({ provider: provider.id })
            : null,
    );
    const plans = useCatalog<Plan>(
        provider && region
            ? ProviderCatalogController.plans.url({
                  provider: provider.id,
                  region,
              })
            : null,
    );
    const networks = useCatalog<ProviderNetwork>(
        provider && region
            ? ProviderCatalogController.networks.url({
                  provider: provider.id,
                  region,
              })
            : null,
    );

    const chosenRegion = regions.items.find((entry) => entry.id === region);
    const chosenPlan = plans.items.find((entry) => entry.id === plan);
    const chosenNetwork =
        network ??
        networks.items.find((entry) => entry.is_default)?.external_id ??
        networks.items[0]?.external_id ??
        newNetwork;
    const networkName =
        chosenNetwork === newNetwork
            ? 'new private network'
            : chosenNetwork === newDatabasifyNetwork
              ? 'new Databasify network'
              : (networks.items.find(
                    (entry) => entry.external_id === chosenNetwork,
                )?.name ??
                databasifyNetworks.find(
                    (entry) => databasifyValueOf(entry) === chosenNetwork,
                )?.name);
    const missing = !region
        ? 'Pick a region'
        : !plan
          ? 'Pick a size'
          : name === ''
            ? 'Name the server'
            : null;

    const chooseProvider = (id: string) => {
        setProviderId(id);
        setRegion('');
        setPlan('');
        setNetwork(null);
    };
    const chooseRegion = (id: string) => {
        setRegion(id);
        setPlan('');
        setNetwork(null);
    };

    if (providers.length === 0) {
        return (
            <EmptyState
                icon={Cloud}
                title="No providers yet"
                description="Add a cloud account to create servers in its private network."
            >
                <Button asChild>
                    <Link href={ServerProviderController.index()}>
                        Add a provider
                    </Link>
                </Button>
            </EmptyState>
        );
    }

    return (
        <Form {...ProviderServerController.store.form()} className="space-y-5">
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="server_provider_id"
                        value={providerId ?? ''}
                    />
                    <input type="hidden" name="region" value={region} />
                    <input type="hidden" name="plan" value={plan} />
                    <input
                        type="hidden"
                        name="os"
                        value={chosenSystem?.value ?? ''}
                    />
                    <input
                        type="hidden"
                        name="network"
                        value={chosenNetwork === noNetwork ? '' : chosenNetwork}
                    />

                    <FormSection title="Account">
                        <div
                            className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3"
                            role="radiogroup"
                        >
                            {providers.map((entry) => (
                                <ChoiceRow
                                    key={entry.id}
                                    selected={entry.id === providerId}
                                    onSelect={() => chooseProvider(entry.id)}
                                >
                                    <BrandIcon
                                        group="server-providers"
                                        src={entry.type.icon}
                                        size="sm"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate font-medium">
                                            {entry.name}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {entry.type.label}
                                        </span>
                                    </span>
                                </ChoiceRow>
                            ))}
                        </div>
                        <InputError message={errors.server_provider_id} />
                    </FormSection>

                    <FormSection title="Region">
                        {regions.loading ? (
                            <Waiting text="Loading regions…" />
                        ) : (
                            <div
                                className="grid max-h-62 grid-cols-1 gap-2 overflow-y-auto overscroll-contain pr-1 sm:grid-cols-2 lg:grid-cols-4"
                                role="radiogroup"
                            >
                                {regions.items.map((entry) => (
                                    <ChoiceRow
                                        key={entry.id}
                                        selected={entry.id === region}
                                        onSelect={() => chooseRegion(entry.id)}
                                        className="h-14 rounded-lg px-3 py-0"
                                    >
                                        <RegionFlag country={entry.country} />
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate text-sm font-medium">
                                                {entry.name}
                                            </span>
                                            <span className="block font-mono text-xs text-muted-foreground">
                                                {entry.id}
                                            </span>
                                        </span>
                                    </ChoiceRow>
                                ))}
                            </div>
                        )}
                        <InputError
                            message={
                                errors.region ?? regions.error ?? undefined
                            }
                        />
                    </FormSection>

                    <FormSection title="Operating system">
                        <div
                            className="grid grid-cols-1 gap-2 sm:grid-cols-2"
                            role="radiogroup"
                        >
                            {offeredSystems.map((entry) => (
                                <ChoiceRow
                                    key={entry.value}
                                    selected={
                                        entry.value === chosenSystem?.value
                                    }
                                    onSelect={() => setSystem(entry.value)}
                                    className="h-14 rounded-lg px-3 py-0"
                                >
                                    <BrandIcon group="os" src={entry.icon} />
                                    <span className="min-w-0 flex-1 font-medium">
                                        {entry.label}
                                    </span>
                                    {entry.recommended && (
                                        <Badge variant="secondary">
                                            Recommended
                                        </Badge>
                                    )}
                                </ChoiceRow>
                            ))}
                        </div>
                        <InputError message={errors.os} />
                    </FormSection>

                    <FormSection
                        title="Size"
                        description={
                            region
                                ? `What ${chosenRegion?.name ?? region} offers, from 1 GB of memory.`
                                : undefined
                        }
                    >
                        {!region ? (
                            <Waiting text="Pick a region to see its sizes." />
                        ) : plans.loading ? (
                            <Waiting text="Loading sizes…" />
                        ) : plans.items.length === 0 && !plans.error ? (
                            <Waiting
                                text={`${chosenRegion?.name ?? region} has no sizes in stock right now. Pick another region.`}
                            />
                        ) : (
                            <div
                                className="grid max-h-70 grid-cols-1 gap-2 overflow-y-auto overscroll-contain pr-1 sm:grid-cols-2 lg:grid-cols-3"
                                role="radiogroup"
                            >
                                {plans.items.map((entry) => (
                                    <SizeCard
                                        key={entry.id}
                                        plan={entry}
                                        selected={plan === entry.id}
                                        onSelect={() => setPlan(entry.id)}
                                    />
                                ))}
                            </div>
                        )}
                        <InputError
                            message={errors.plan ?? plans.error ?? undefined}
                        />
                    </FormSection>

                    <FormSection
                        title="Private network"
                        description="Standby and read-only nodes must share the primary's network."
                    >
                        {!region ? (
                            <Waiting text="Pick a region to see its networks." />
                        ) : networks.loading ? (
                            <Waiting text="Loading networks…" />
                        ) : (
                            <div
                                className="grid grid-cols-1 gap-2 md:grid-cols-2"
                                role="radiogroup"
                            >
                                {networks.items.map((entry) => (
                                    <ChoiceRow
                                        key={entry.external_id}
                                        selected={
                                            chosenNetwork === entry.external_id
                                        }
                                        onSelect={() =>
                                            setNetwork(entry.external_id)
                                        }
                                    >
                                        <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                            <Network className="size-4" />
                                        </span>
                                        <span className="min-w-0 flex-1">
                                            <span className="block truncate font-medium">
                                                {entry.name}
                                            </span>
                                            <span className="block font-mono text-xs text-muted-foreground">
                                                {entry.cidr}
                                            </span>
                                        </span>
                                        {entry.is_default && (
                                            <Badge variant="secondary">
                                                Default
                                            </Badge>
                                        )}
                                    </ChoiceRow>
                                ))}
                                <ChoiceRow
                                    selected={chosenNetwork === newNetwork}
                                    onSelect={() => setNetwork(newNetwork)}
                                >
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                        <Plus className="size-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-medium">
                                            New private network
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {provider?.type.label} creates one
                                            in {chosenRegion?.name ?? region}
                                        </span>
                                    </span>
                                </ChoiceRow>
                                <DatabasifyNetworkChoices
                                    networks={databasifyNetworks}
                                    selected={chosenNetwork}
                                    onSelect={setNetwork}
                                    valueOf={databasifyValueOf}
                                    freshValue={newDatabasifyNetwork}
                                />
                                <ChoiceRow
                                    selected={chosenNetwork === noNetwork}
                                    onSelect={() => setNetwork(noNetwork)}
                                >
                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <Network className="size-4" />
                                    </span>
                                    <span className="min-w-0 flex-1">
                                        <span className="block font-medium">
                                            None
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            Single-node databases only
                                        </span>
                                    </span>
                                </ChoiceRow>
                            </div>
                        )}
                        <InputError
                            message={
                                errors.network ?? networks.error ?? undefined
                            }
                        />
                        {chosenNetwork === newDatabasifyNetwork && (
                            <NetworkBlockField error={errors.network_block} />
                        )}
                    </FormSection>

                    <SubmitPanel
                        name={name}
                        onName={setName}
                        nameLabel="Server name"
                        placeholder="db-04"
                        error={errors.name}
                        processing={processing}
                        missing={missing}
                        action={`Create ${provider?.type.server_label ?? 'server'}`}
                        icon={Cloud}
                        summary={
                            <div className="flex items-center gap-3 md:flex-row-reverse">
                                {provider && (
                                    <BrandIcon
                                        group="server-providers"
                                        src={provider.type.icon}
                                    />
                                )}
                                <div>
                                    <div className="flex items-center gap-2 font-semibold md:justify-end">
                                        {chosenRegion && (
                                            <RegionFlag
                                                country={chosenRegion.country}
                                            />
                                        )}
                                        {chosenRegion?.name ??
                                            provider?.type.label}
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        {chosenSystem?.label ?? ''}
                                        {' · '}
                                        {chosenPlan
                                            ? `${chosenPlan.cpus} vCPU · ${formatMemory(chosenPlan.memory_mb)} · $${chosenPlan.monthly_price ?? '—'}/mo`
                                            : 'No size yet'}
                                        {' · '}
                                        {networkName ?? 'no private network'}
                                    </div>
                                </div>
                            </div>
                        }
                    />
                </>
            )}
        </Form>
    );
}
