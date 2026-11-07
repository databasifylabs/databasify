import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowUpCircle, DatabaseZap, Plus } from 'lucide-react';
import { Fragment } from 'react';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import {
    configurationOf,
    membersOf,
    specsOf,
    updateNoticeOf,
} from '@/components/databases/describe';
import CursorPagination from '@/components/cursor-pagination';
import DatabaseActions from '@/components/databases/actions/database-actions';
import EmptyState from '@/components/empty-state';
import FilterBar from '@/components/filters/filter-bar';
import FilterSelect from '@/components/filters/filter-select';
import NoMatches from '@/components/filters/no-matches';
import SearchInput from '@/components/filters/search-input';
import BrandIcon from '@/components/brand-icon';
import NodeDots from '@/components/databases/nodes/node-dots';
import OperationLabel from '@/components/databases/operation-label';
import ReadOnlyRows from '@/components/databases/nodes/read-only-rows';
import ReadOnlyToggle from '@/components/databases/nodes/read-only-toggle';
import StatusBadge from '@/components/status-badge';
import StatusDot from '@/components/status-dot';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useExpandedDatabases } from '@/hooks/use-expanded-databases';
import { useListFilters } from '@/hooks/use-list-filters';
import { statusOptionsOf } from '@/lib/filters';
import { timeAgo } from '@/lib/format';
import type { CursorPaginated, DatabaseSummary, FilterOption } from '@/types';

type Filters = {
    search: string | null;
    status: string | null;
    engine: string | null;
};

export default function DatabasesIndex({
    databases,
    filters: initialFilters,
    filterOptions,
}: {
    databases: CursorPaginated<DatabaseSummary>;
    filters: Filters;
    filterOptions: { statuses: string[]; engines: FilterOption[] };
}) {
    usePoll(5000, { only: ['databases'] });
    const { isOpen, toggle } = useExpandedDatabases();
    const { filters, setFilter, clearFilters, isFiltering } = useListFilters(
        initialFilters,
        ['databases'],
    );

    return (
        <>
            <Head title="Databases" />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Databases"
                        description="Managed databases running on your own servers."
                    />
                    <Button asChild>
                        <Link href={DatabaseController.create()}>
                            <Plus /> Create database
                        </Link>
                    </Button>
                </div>

                {!isFiltering &&
                databases.data.length === 0 &&
                databases.links.prev === null ? (
                    <EmptyState
                        icon={DatabaseZap}
                        title="No databases yet"
                        description="Create a database on servers you've added. Start with a single primary and add standby or read-only nodes whenever you need them."
                    >
                        <Button asChild>
                            <Link href={DatabaseController.create()}>
                                <Plus /> Create your first database
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <>
                        <FilterBar
                            isFiltering={isFiltering}
                            onClear={clearFilters}
                        >
                            <SearchInput
                                value={filters.search ?? ''}
                                onChange={(value) => setFilter('search', value)}
                                placeholder="Search name or engine"
                            />
                            <FilterSelect
                                label="Engine"
                                allLabel="All engines"
                                value={filters.engine}
                                options={filterOptions.engines}
                                onChange={(value) => setFilter('engine', value)}
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

                        {databases.data.length === 0 ? (
                            <NoMatches
                                noun="databases"
                                onClear={clearFilters}
                            />
                        ) : (
                            <>
                                <div className="overflow-hidden rounded-xl border bg-card">
                                    <Table>
                                        <TableHeader>
                                            <TableRow className="hover:bg-transparent">
                                                <TableHead className="pl-4">
                                                    Name
                                                </TableHead>
                                                <TableHead>
                                                    Configuration
                                                </TableHead>
                                                <TableHead>Nodes</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Created</TableHead>
                                                <TableHead className="w-12" />
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {databases.data.map((database) => (
                                                <Fragment key={database.id}>
                                                    <TableRow
                                                        className="group cursor-pointer"
                                                        onClick={(event) => {
                                                            if (
                                                                event.defaultPrevented
                                                            ) {
                                                                return;
                                                            }

                                                            router.visit(
                                                                DatabaseController.show(
                                                                    {
                                                                        database:
                                                                            database.id,
                                                                    },
                                                                ),
                                                            );
                                                        }}
                                                    >
                                                        <TableCell className="py-4 pl-4">
                                                            <Link
                                                                href={DatabaseController.show(
                                                                    {
                                                                        database:
                                                                            database.id,
                                                                    },
                                                                )}
                                                                className="flex items-center gap-3"
                                                            >
                                                                <StatusDot
                                                                    status={
                                                                        database.status
                                                                    }
                                                                />
                                                                <BrandIcon
                                                                    group="database-providers"
                                                                    src={
                                                                        database.engine_icon
                                                                    }
                                                                    size="sm"
                                                                />
                                                                <div className="min-w-0">
                                                                    <div className="font-medium group-hover:underline">
                                                                        {
                                                                            database.name
                                                                        }
                                                                    </div>
                                                                    {database.operation ? (
                                                                        <OperationLabel
                                                                            operation={
                                                                                database.operation
                                                                            }
                                                                        />
                                                                    ) : (
                                                                        <div className="truncate text-xs text-muted-foreground">
                                                                            {specsOf(
                                                                                database,
                                                                            )}
                                                                        </div>
                                                                    )}
                                                                </div>
                                                            </Link>
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground">
                                                            {configurationOf(
                                                                database,
                                                            )}
                                                        </TableCell>
                                                        <TableCell>
                                                            <div className="flex items-center gap-2">
                                                                <NodeDots
                                                                    nodes={membersOf(
                                                                        database,
                                                                    )}
                                                                />
                                                                <ReadOnlyToggle
                                                                    database={
                                                                        database
                                                                    }
                                                                    open={isOpen(
                                                                        database.id,
                                                                    )}
                                                                    onToggle={() =>
                                                                        toggle(
                                                                            database.id,
                                                                        )
                                                                    }
                                                                />
                                                            </div>
                                                        </TableCell>
                                                        <TableCell>
                                                            <StatusBadge
                                                                status={
                                                                    database.status
                                                                }
                                                            />
                                                        </TableCell>
                                                        <TableCell className="text-muted-foreground">
                                                            {timeAgo(
                                                                database.created_at,
                                                            )}
                                                        </TableCell>
                                                        <TableCell
                                                            onClick={(event) =>
                                                                event.stopPropagation()
                                                            }
                                                        >
                                                            <DatabaseActions
                                                                database={
                                                                    database
                                                                }
                                                                busy={
                                                                    database.operation !==
                                                                    null
                                                                }
                                                            />
                                                        </TableCell>
                                                    </TableRow>
                                                    <ReadOnlyRows
                                                        database={database}
                                                        open={isOpen(
                                                            database.id,
                                                        )}
                                                        firstCellClassName="pl-4"
                                                        trailing={(node) => (
                                                            <>
                                                                <TableCell />
                                                                <TableCell className="text-muted-foreground">
                                                                    {timeAgo(
                                                                        node.created_at,
                                                                    )}
                                                                </TableCell>
                                                                <TableCell />
                                                            </>
                                                        )}
                                                    />
                                                    {database.available_version && (
                                                        <TableRow className="bg-primary/5 hover:bg-primary/10">
                                                            <TableCell
                                                                colSpan={6}
                                                                className="pl-4"
                                                            >
                                                                <Link
                                                                    href={DatabaseController.show(
                                                                        {
                                                                            database:
                                                                                database.id,
                                                                        },
                                                                        {
                                                                            query: {
                                                                                tab: 'settings',
                                                                            },
                                                                        },
                                                                    )}
                                                                    className="flex items-center gap-2 text-sm"
                                                                >
                                                                    <ArrowUpCircle className="size-4 text-primary" />
                                                                    {updateNoticeOf(
                                                                        database,
                                                                    )}
                                                                </Link>
                                                            </TableCell>
                                                        </TableRow>
                                                    )}
                                                </Fragment>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>

                                <CursorPagination
                                    links={databases.links}
                                    only={['databases']}
                                />
                            </>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

DatabasesIndex.layout = () => ({
    breadcrumbs: [{ title: 'Databases', href: DatabaseController.index() }],
});
