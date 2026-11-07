import { Form, Head, Link } from '@inertiajs/react';
import {
    CircleAlert,
    CircleCheck,
    CircleDashed,
    Copy,
    Crown,
    Server as ServerIcon,
    ShieldCheck,
    Unplug,
} from 'lucide-react';
import { useState } from 'react';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import ServerController from '@/actions/App/Http/Controllers/Servers/ServerController';
import EmptyState from '@/components/empty-state';
import {
    CheckMark,
    choiceRowClass,
    FormSection,
    Radio,
} from '@/components/form/choice';
import BrandIcon from '@/components/brand-icon';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import NetworkMark, { networkSource } from '@/components/servers/network-mark';
import { ServerMark } from '@/components/servers/server-source';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useClusterMemory } from '@/lib/cluster';
import { formatMemory, pluralize } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { NetworkSummary, Server } from '@/types';

type VersionLine = { value: string; label: string; default: boolean };

type EngineOption = {
    value: string;
    label: string;
    icon: string;
    category: string;
    max_standbys: number;
    lines: VersionLine[];
};

type Props = {
    engines: EngineOption[];
    servers: { data: Server[] };
};

const automaticFailoverMembers = 3;

function EngineList({
    engines,
    engine,
    lineByEngine,
    onEngine,
    onLine,
}: {
    engines: EngineOption[];
    engine: string;
    lineByEngine: Record<string, string>;
    onEngine: (value: string) => void;
    onLine: (value: string, line: string) => void;
}) {
    const categories = [...new Set(engines.map((option) => option.category))];

    return (
        <div className="grid grid-cols-1 gap-x-5 gap-y-5 md:grid-cols-2">
            {categories.map((category) => (
                <div key={category} className="space-y-2">
                    <div className="text-sm font-medium text-muted-foreground">
                        {category}
                    </div>
                    <div className="space-y-2" role="radiogroup">
                        {engines
                            .filter((option) => option.category === category)
                            .map((option) => {
                                const selected = engine === option.value;
                                const line = lineByEngine[option.value] ?? '';

                                return (
                                    <div
                                        key={option.value}
                                        role="radio"
                                        aria-checked={selected}
                                        tabIndex={0}
                                        onClick={() => onEngine(option.value)}
                                        onKeyDown={(event) => {
                                            if (
                                                event.key === ' ' ||
                                                event.key === 'Enter'
                                            ) {
                                                event.preventDefault();
                                                onEngine(option.value);
                                            }
                                        }}
                                        className={choiceRowClass(selected)}
                                    >
                                        <Radio selected={selected} />
                                        <BrandIcon
                                            group="database-providers"
                                            src={option.icon}
                                            size="sm"
                                        />
                                        <span className="min-w-0 flex-1 truncate font-medium">
                                            {option.label}
                                        </span>
                                        {option.lines.length > 1 ? (
                                            <Select
                                                value={line}
                                                onValueChange={(value) =>
                                                    onLine(option.value, value)
                                                }
                                            >
                                                <SelectTrigger
                                                    size="sm"
                                                    className="w-24 shrink-0 bg-background"
                                                    aria-label={`${option.label} version`}
                                                >
                                                    <SelectValue>
                                                        v{line}
                                                    </SelectValue>
                                                </SelectTrigger>
                                                <SelectContent align="end">
                                                    {option.lines
                                                        .toReversed()
                                                        .map((item) => (
                                                            <SelectItem
                                                                key={item.value}
                                                                value={
                                                                    item.value
                                                                }
                                                            >
                                                                v{item.value}
                                                                {item.default && (
                                                                    <span className="text-xs text-muted-foreground">
                                                                        recommended
                                                                    </span>
                                                                )}
                                                            </SelectItem>
                                                        ))}
                                                </SelectContent>
                                            </Select>
                                        ) : (
                                            <span className="w-24 shrink-0 px-3 text-sm text-muted-foreground">
                                                v{line}
                                            </span>
                                        )}
                                    </div>
                                );
                            })}
                    </div>
                </div>
            ))}
        </div>
    );
}

function sharesPrivateNetwork(server: Server, other: Server): boolean {
    return server.network_id !== null && server.network_id === other.network_id;
}

type ServerGroup = {
    key: string;
    network: NetworkSummary | null;
    servers: Server[];
};

function groupsOf(servers: Server[]): ServerGroup[] {
    const groups = new Map<string, ServerGroup>();

    servers.forEach((server) => {
        const key = String(server.network_id ?? 'none');
        const group = groups.get(key) ?? {
            key,
            network: server.network ?? null,
            servers: [],
        };
        groups.set(key, { ...group, servers: [...group.servers, server] });
    });

    return [...groups.values()].sort(
        (a, b) =>
            Number(a.network === null) - Number(b.network === null) ||
            (a.network?.name ?? '').localeCompare(b.network?.name ?? ''),
    );
}

function GroupHeading({ network }: { network: NetworkSummary | null }) {
    if (network === null) {
        return (
            <div className="flex items-center gap-2 px-1 pt-1 text-xs text-muted-foreground">
                <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-muted">
                    <Unplug className="size-3.5" />
                </span>
                <span className="font-medium text-foreground">
                    No private network
                </span>
                <span className="truncate">Single-node only</span>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-2 px-1 pt-1 text-xs text-muted-foreground">
            <NetworkMark network={network} size="xs" />
            <span className="truncate font-medium text-foreground">
                {network.name}
            </span>
            <span className="shrink-0 font-mono">{network.cidr}</span>
            <span className="hidden truncate sm:inline">
                · {networkSource(network)}
            </span>
        </div>
    );
}

function StandbyNote({ children }: { children: React.ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed px-4 py-3 text-sm text-muted-foreground">
            {children}
        </p>
    );
}

function ServerRow({
    server,
    selected,
    disabled = false,
    multiple = false,
    note,
    onToggle,
}: {
    server: Server;
    selected: boolean;
    disabled?: boolean;
    multiple?: boolean;
    note?: string;
    onToggle: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onToggle}
            disabled={disabled && !selected}
            className={cn(
                choiceRowClass(selected),
                'disabled:cursor-not-allowed disabled:opacity-50',
            )}
        >
            {multiple ? (
                <CheckMark selected={selected} />
            ) : (
                <Radio selected={selected} />
            )}
            <ServerMark server={server} />
            <span className="min-w-0 flex-1">
                <span className="flex items-center gap-2">
                    <span className="truncate font-medium">{server.name}</span>
                    {note && (
                        <Badge variant="secondary" className="px-1.5 py-0">
                            {note}
                        </Badge>
                    )}
                </span>
                <span className="block truncate text-xs text-muted-foreground">
                    {server.cpus ?? '—'} vCPU · {formatMemory(server.memory_mb)}{' '}
                    · {server.disk_gb ?? '—'} GB ·{' '}
                    <span className="font-mono">
                        {server.private_ip ?? server.public_ip}
                    </span>
                </span>
            </span>
        </button>
    );
}

function RoleHeading({
    icon: Icon,
    title,
    hint,
    aside,
}: {
    icon: typeof Crown;
    title: string;
    hint: string;
    aside?: React.ReactNode;
}) {
    return (
        <div className="flex items-center gap-2 text-sm">
            <Icon className="size-4 text-muted-foreground" />
            <span className="font-medium">{title}</span>
            <span className="truncate text-muted-foreground">{hint}</span>
            {aside && <span className="ml-auto shrink-0">{aside}</span>}
        </div>
    );
}

export default function CreateDatabase({
    engines,
    servers: { data: servers },
}: Props) {
    const [name, setName] = useState('');
    const [engine, setEngine] = useState(engines[0]?.value ?? 'mysql');
    const [lineByEngine, setLineByEngine] = useState<Record<string, string>>(
        () =>
            Object.fromEntries(
                engines.map((option) => [
                    option.value,
                    option.lines.find((line) => line.default)?.value ??
                        option.lines[0]?.value ??
                        '',
                ]),
            ),
    );
    const [primary, setPrimary] = useState<string | null>(null);
    const [standbys, setStandbys] = useState<string[]>([]);
    const selectedEngine = engines.find((option) => option.value === engine);
    const maxStandbys = selectedEngine?.max_standbys ?? 0;
    const versionLine = lineByEngine[engine] ?? '';

    const primaryServer =
        servers.find((server) => server.id === primary) ?? null;
    const fitsCluster = useClusterMemory();
    const choosePrimary = (id: string) => {
        const chosen = servers.find((server) => server.id === id);
        setPrimary((current) => (current === id ? null : id));
        setStandbys((ids) =>
            ids.filter((value) => {
                const standby = servers.find((server) => server.id === value);

                return (
                    value !== id &&
                    chosen !== undefined &&
                    standby !== undefined &&
                    fitsCluster(chosen.memory_mb) &&
                    sharesPrivateNetwork(chosen, standby)
                );
            }),
        );
    };
    const candidates =
        primaryServer === null
            ? []
            : servers.filter(
                  (server) =>
                      server.id !== primaryServer.id &&
                      sharesPrivateNetwork(primaryServer, server),
              );
    const elsewhere = servers.length - 1 - candidates.length;
    const toggleStandby = (id: string) =>
        setStandbys((ids) =>
            ids.includes(id)
                ? ids.filter((value) => value !== id)
                : [...ids, id],
        );

    const failover =
        standbys.length === 0
            ? {
                  icon: CircleDashed,
                  tone: 'text-muted-foreground',
                  title: 'No failover',
                  detail: 'If the primary goes down, so does the database.',
              }
            : 1 + standbys.length >= automaticFailoverMembers
              ? {
                    icon: CircleCheck,
                    tone: 'text-green-600 dark:text-green-500',
                    title: 'Automatic failover',
                    detail: 'A standby is promoted when the primary goes down.',
                }
              : {
                    icon: CircleAlert,
                    tone: 'text-amber-600 dark:text-amber-500',
                    title: 'Manual failover',
                    detail: 'With two nodes, use Recover when the primary goes down. Add another standby to make it automatic.',
                };
    const FailoverIcon = failover.icon;
    const missing =
        name === ''
            ? 'Name the database'
            : primary === null
              ? 'Pick a primary server'
              : null;

    return (
        <>
            <Head title="Create database" />
            <Form
                {...DatabaseController.store.form()}
                className="mx-auto w-full max-w-5xl space-y-5 p-4 pb-10 md:p-6"
            >
                {({ processing, errors }) => (
                    <>
                        <Heading title="Create database" />

                        <FormSection title="Choose a database engine">
                            <input type="hidden" name="engine" value={engine} />
                            <input
                                type="hidden"
                                name="version_line"
                                value={versionLine}
                            />
                            <EngineList
                                engines={engines}
                                engine={engine}
                                lineByEngine={lineByEngine}
                                onEngine={setEngine}
                                onLine={(value, line) => {
                                    setEngine(value);
                                    setLineByEngine((current) => ({
                                        ...current,
                                        [value]: line,
                                    }));
                                }}
                            />
                            <InputError message={errors.engine} />
                            <InputError message={errors.version_line} />
                        </FormSection>

                        <FormSection title="Choose nodes">
                            {servers.length === 0 ? (
                                <EmptyState
                                    icon={ServerIcon}
                                    title="No free servers"
                                    description="Every ready server is already in use."
                                >
                                    <Button asChild variant="outline">
                                        <Link href={ServerController.create()}>
                                            Add a server
                                        </Link>
                                    </Button>
                                </EmptyState>
                            ) : (
                                <div className="grid grid-cols-1 gap-x-5 gap-y-6 md:grid-cols-2">
                                    <div className="space-y-2">
                                        <RoleHeading
                                            icon={Crown}
                                            title="Primary"
                                            hint="Reads and writes"
                                        />
                                        <div
                                            className="space-y-4"
                                            role="radiogroup"
                                        >
                                            {groupsOf(servers).map((group) => (
                                                <div
                                                    key={group.key}
                                                    className="space-y-2"
                                                >
                                                    <GroupHeading
                                                        network={group.network}
                                                    />
                                                    {group.servers.map(
                                                        (server) => (
                                                            <ServerRow
                                                                key={server.id}
                                                                server={server}
                                                                selected={
                                                                    primary ===
                                                                    server.id
                                                                }
                                                                note={
                                                                    standbys.includes(
                                                                        server.id,
                                                                    )
                                                                        ? 'Standby'
                                                                        : fitsCluster(
                                                                                server.memory_mb,
                                                                            )
                                                                          ? undefined
                                                                          : 'Single-node only'
                                                                }
                                                                onToggle={() =>
                                                                    choosePrimary(
                                                                        server.id,
                                                                    )
                                                                }
                                                            />
                                                        ),
                                                    )}
                                                </div>
                                            ))}
                                        </div>
                                        <InputError
                                            message={errors.primary_server_id}
                                        />
                                    </div>

                                    <div className="space-y-2">
                                        <RoleHeading
                                            icon={Copy}
                                            title="Standbys"
                                            hint="Optional"
                                            aside={
                                                <span className="text-xs text-muted-foreground tabular-nums">
                                                    {standbys.length} of{' '}
                                                    {maxStandbys}
                                                </span>
                                            }
                                        />
                                        {primaryServer === null ? (
                                            <StandbyNote>
                                                Pick a primary first. Standbys
                                                must share its private network.
                                            </StandbyNote>
                                        ) : !fitsCluster(
                                              primaryServer.memory_mb,
                                          ) ? (
                                            <StandbyNote>
                                                {primaryServer.name} has{' '}
                                                {formatMemory(
                                                    primaryServer.memory_mb,
                                                )}{' '}
                                                of memory. Standbys need 1 GB on
                                                every node, so it runs as a
                                                single-node database.
                                            </StandbyNote>
                                        ) : !primaryServer.network ? (
                                            <StandbyNote>
                                                {primaryServer.name} has no
                                                private network, so it can't
                                                have standbys.
                                            </StandbyNote>
                                        ) : candidates.length === 0 ? (
                                            <StandbyNote>
                                                No other free server on{' '}
                                                {primaryServer.network.name}.
                                                Add one to that network to use
                                                it as a standby.
                                            </StandbyNote>
                                        ) : (
                                            <div className="space-y-2">
                                                <GroupHeading
                                                    network={
                                                        primaryServer.network
                                                    }
                                                />
                                                {candidates.map((server) => {
                                                    const fits = fitsCluster(
                                                        server.memory_mb,
                                                    );

                                                    return (
                                                        <ServerRow
                                                            key={server.id}
                                                            server={server}
                                                            multiple
                                                            selected={standbys.includes(
                                                                server.id,
                                                            )}
                                                            disabled={
                                                                standbys.length >=
                                                                    maxStandbys ||
                                                                !fits
                                                            }
                                                            note={
                                                                fits
                                                                    ? undefined
                                                                    : 'Needs 1 GB'
                                                            }
                                                            onToggle={() =>
                                                                toggleStandby(
                                                                    server.id,
                                                                )
                                                            }
                                                        />
                                                    );
                                                })}
                                            </div>
                                        )}
                                        {primaryServer?.network &&
                                            elsewhere > 0 && (
                                                <p className="px-1 text-xs text-muted-foreground">
                                                    {pluralize(
                                                        elsewhere,
                                                        'server',
                                                    )}{' '}
                                                    on other networks can't be
                                                    standbys.
                                                </p>
                                            )}
                                        <InputError
                                            message={
                                                errors.standby_server_ids ??
                                                Object.entries(errors).find(
                                                    ([key]) =>
                                                        key.startsWith(
                                                            'standby_server_ids.',
                                                        ),
                                                )?.[1]
                                            }
                                        />
                                    </div>
                                </div>
                            )}
                            {primary !== null && (
                                <input
                                    type="hidden"
                                    name="primary_server_id"
                                    value={primary}
                                />
                            )}
                            {standbys.map((id) => (
                                <input
                                    key={id}
                                    type="hidden"
                                    name="standby_server_ids[]"
                                    value={id}
                                />
                            ))}
                        </FormSection>

                        <section className="space-y-4 rounded-2xl border bg-muted/30 p-5 sm:p-6">
                            <div className="grid grid-cols-1 gap-5 md:grid-cols-[minmax(0,20rem)_1fr] md:items-start">
                                <div className="space-y-1.5">
                                    <Label htmlFor="name">Database name</Label>
                                    <Input
                                        id="name"
                                        name="name"
                                        required
                                        value={name}
                                        onChange={(event) =>
                                            setName(
                                                event.target.value.toLowerCase(),
                                            )
                                        }
                                        placeholder="app-db"
                                        className="bg-background"
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Must be unique. Lowercase letters,
                                        numbers and dashes.
                                    </p>
                                    <InputError message={errors.name} />
                                </div>
                                <div className="space-y-3 md:justify-self-end">
                                    <div className="flex items-center gap-3">
                                        <BrandIcon
                                            group="database-providers"
                                            src={selectedEngine?.icon}
                                        />
                                        <div>
                                            <div className="font-semibold">
                                                {selectedEngine?.label} v
                                                {versionLine}
                                            </div>
                                            <div className="text-sm text-muted-foreground">
                                                {standbys.length > 0
                                                    ? `Primary + ${pluralize(standbys.length, 'standby')}`
                                                    : 'Primary only'}
                                            </div>
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-2 md:max-w-xs">
                                        <FailoverIcon
                                            className={cn(
                                                'mt-0.5 size-4 shrink-0',
                                                failover.tone,
                                            )}
                                        />
                                        <div className="text-sm">
                                            <div className="font-medium">
                                                {failover.title}
                                            </div>
                                            <p className="text-xs text-muted-foreground">
                                                {failover.detail}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="flex flex-col-reverse items-stretch gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-end">
                                <div className="flex items-center gap-3">
                                    {missing && (
                                        <span className="text-xs whitespace-nowrap text-muted-foreground">
                                            {missing}
                                        </span>
                                    )}
                                    <Button
                                        size="lg"
                                        disabled={
                                            processing || missing !== null
                                        }
                                        className="flex-1 sm:flex-none"
                                    >
                                        <ShieldCheck />
                                        Create database
                                    </Button>
                                </div>
                            </div>
                        </section>
                    </>
                )}
            </Form>
        </>
    );
}

CreateDatabase.layout = () => ({
    breadcrumbs: [
        { title: 'Databases', href: DatabaseController.index() },
        { title: 'Create', href: DatabaseController.create() },
    ],
});
