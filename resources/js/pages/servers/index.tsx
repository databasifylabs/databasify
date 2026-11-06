import { Head, Link, router, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    CheckCircle2,
    Layers,
    Plus,
    Server as ServerIcon,
    ServerCog,
} from 'lucide-react';
import ServerController from '@/actions/App/Http/Controllers/Servers/ServerController';
import CursorPagination from '@/components/cursor-pagination';
import EmptyState from '@/components/empty-state';
import FilterBar from '@/components/filters/filter-bar';
import FilterSelect from '@/components/filters/filter-select';
import NoMatches from '@/components/filters/no-matches';
import SearchInput from '@/components/filters/search-input';
import StatusDot from '@/components/status-dot';
import StatusBadge from '@/components/status-badge';
import Heading from '@/components/heading';
import BrandIcon from '@/components/brand-icon';
import ServerActions from '@/components/servers/server-actions';
import ServerDatabase from '@/components/servers/server-database';
import ServerSource from '@/components/servers/server-source';
import ServerSpecs from '@/components/servers/server-specs';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useListFilters } from '@/hooks/use-list-filters';
import { statusOptionsOf } from '@/lib/filters';
import { timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { CursorPaginated, Server } from '@/types';

function Summary({
    icon: Icon,
    label,
    value,
    className,
}: {
    icon: LucideIcon;
    label: string;
    value: number;
    className: string;
}) {
    return (
        <div className="flex items-center gap-3 rounded-xl border bg-card p-3 sm:p-4">
            <span
                className={cn(
                    'hidden size-10 shrink-0 items-center justify-center rounded-lg sm:flex',
                    className,
                )}
            >
                <Icon className="size-5" />
            </span>
            <div>
                <div className="text-2xl font-semibold tabular-nums">
                    {value}
                </div>
                <div className="text-xs text-muted-foreground">{label}</div>
            </div>
        </div>
    );
}

type ServerSummary = {
    total: number;
    in_use: number;
    available: number;
};

type Filters = {
    search: string | null;
    status: string | null;
};

export default function ServersIndex({
    servers,
    summary,
    filters: initialFilters,
    filterOptions,
}: {
    servers: CursorPaginated<Server>;
    summary: ServerSummary;
    filters: Filters;
    filterOptions: { statuses: string[] };
}) {
    usePoll(10000, { only: ['servers', 'summary'] });
    const { filters, setFilter, clearFilters, isFiltering } = useListFilters(
        initialFilters,
        ['servers'],
    );

    return (
        <>
            <Head title="Servers" />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Servers"
                        description="Your own machines, set up and managed over SSH. Databases run on these."
                    />
                    <Button asChild>
                        <Link href={ServerController.create()}>
                            <Plus /> Add server
                        </Link>
                    </Button>
                </div>

                {summary.total === 0 ? (
                    <EmptyState
                        icon={ServerCog}
                        title="No servers yet"
                        description="Add a Linux server you can reach over SSH."
                    >
                        <Button asChild>
                            <Link href={ServerController.create()}>
                                <Plus /> Add your first server
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <>
                        <div className="grid grid-cols-3 gap-3 sm:gap-4">
                            <Summary
                                icon={ServerIcon}
                                label="Servers"
                                value={summary.total}
                                className="bg-muted text-muted-foreground"
                            />
                            <Summary
                                icon={Layers}
                                label="Running a database"
                                value={summary.in_use}
                                className="bg-muted text-muted-foreground"
                            />
                            <Summary
                                icon={CheckCircle2}
                                label="Ready for a new database"
                                value={summary.available}
                                className="bg-muted text-muted-foreground"
                            />
                        </div>

                        <FilterBar
                            isFiltering={isFiltering}
                            onClear={clearFilters}
                        >
                            <SearchInput
                                value={filters.search ?? ''}
                                onChange={(value) => setFilter('search', value)}
                                placeholder="Search name or IP"
                            />
                            <FilterSelect
                                label="Status"
                                allLabel="All statuses"
                                value={filters.status}
                                options={statusOptionsOf(
                                    filterOptions.statuses,
                                )}
                                onChange={(value) => setFilter('status', value)}
                            />
                        </FilterBar>

                        {servers.data.length === 0 ? (
                            <NoMatches noun="servers" onClear={clearFilters} />
                        ) : (
                            <>
                                <div className="overflow-hidden rounded-xl border bg-card">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="hover:bg-transparent">
                                                <TableHead className="pl-4">
                                                    Name
                                                </TableHead>
                                                <TableHead className="hidden md:table-cell">
                                                    Provider
                                                </TableHead>
                                                <TableHead>Resources</TableHead>
                                                <TableHead>Database</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Added</TableHead>
                                                <TableHead className="w-12" />
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {servers.data.map((server) => (
                                                <TableRow
                                                    key={server.id}
                                                    className="group cursor-pointer"
                                                    onClick={(event) => {
                                                        if (
                                                            event.defaultPrevented
                                                        ) {
                                                            return;
                                                        }

                                                        router.visit(
                                                            ServerController.show(
                                                                server.id,
                                                            ),
                                                        );
                                                    }}
                                                >
                                                    <TableCell className="py-4 pl-4">
                                                        <Link
                                                            href={ServerController.show(
                                                                server.id,
                                                            )}
                                                            className="flex items-center gap-3"
                                                        >
                                                            <span className="relative flex shrink-0">
                                                                <BrandIcon
                                                                    group="os"
                                                                    src={
                                                                        server.distribution_icon
                                                                    }
                                                                />
                                                                <StatusDot
                                                                    status={
                                                                        server.status
                                                                    }
                                                                    className="absolute -right-0.5 -bottom-0.5 rounded-full ring-2 ring-card"
                                                                />
                                                            </span>
                                                            <div className="min-w-0">
                                                                <div className="font-medium group-hover:underline">
                                                                    {
                                                                        server.name
                                                                    }
                                                                </div>
                                                                <div className="truncate font-mono text-xs text-muted-foreground">
                                                                    {
                                                                        server.public_ip
                                                                    }
                                                                    {server.private_ip &&
                                                                        ` · ${server.private_ip}`}
                                                                </div>
                                                            </div>
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell className="hidden md:table-cell">
                                                        <ServerSource
                                                            server={server}
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <ServerSpecs
                                                            server={server}
                                                        />
                                                        {server.os && (
                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                {server.os}
                                                            </div>
                                                        )}
                                                    </TableCell>
                                                    <TableCell>
                                                        <ServerDatabase
                                                            server={server}
                                                        />
                                                    </TableCell>
                                                    <TableCell>
                                                        <StatusBadge
                                                            status={
                                                                server.status
                                                            }
                                                        />
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground">
                                                        {timeAgo(
                                                            server.created_at ??
                                                                null,
                                                        )}
                                                    </TableCell>
                                                    <TableCell
                                                        onClick={(event) =>
                                                            event.stopPropagation()
                                                        }
                                                    >
                                                        <ServerActions
                                                            server={server}
                                                            busy={
                                                                server.status ===
                                                                'provisioning'
                                                            }
                                                            variant="icon"
                                                        />
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                <CursorPagination
                                    links={servers.links}
                                    only={['servers', 'summary']}
                                />
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

ServersIndex.layout = {
    breadcrumbs: [{ title: 'Servers', href: ServerController.index() }],
};
