import type { LucideIcon } from 'lucide-react';
import {
    Activity,
    ChartNoAxesCombined,
    Cpu,
    Gauge,
    HardDrive,
    MemoryStick,
    RefreshCw,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';
import DatabaseInsightsController from '@/actions/App/Http/Controllers/Databases/DatabaseInsightsController';
import EmptyState from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { Progress } from '@/components/ui/progress';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { getJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import type {
    Database,
    DatabaseNode,
    MetricHistory,
    MetricName,
    MetricPeriod,
} from '@/types';

type MetricSpec = {
    name: MetricName;
    title: string;
    icon: LucideIcon;
    unit: string;
    percent: boolean;
};

const metrics: MetricSpec[] = [
    {
        name: 'cpu_percent',
        title: 'CPU usage',
        icon: Cpu,
        unit: '%',
        percent: true,
    },
    {
        name: 'memory_percent',
        title: 'Memory usage',
        icon: MemoryStick,
        unit: '%',
        percent: true,
    },
    {
        name: 'disk_percent',
        title: 'Disk usage',
        icon: HardDrive,
        unit: '%',
        percent: true,
    },
    {
        name: 'load_1m',
        title: 'Load average (1m)',
        icon: Gauge,
        unit: '',
        percent: false,
    },
    {
        name: 'connections',
        title: 'Connections',
        icon: Users,
        unit: '',
        percent: false,
    },
    {
        name: 'ops_per_second',
        title: 'Operations / sec',
        icon: Activity,
        unit: '',
        percent: false,
    },
];

const seriesColors = [
    { light: '#2a78d6', dark: '#3987e5' },
    { light: '#eb6834', dark: '#d95926' },
    { light: '#1baf7a', dark: '#199e70' },
    { light: '#eda100', dark: '#c98500' },
    { light: '#e87ba4', dark: '#d55181' },
    { light: '#008300', dark: '#008300' },
    { light: '#6250d6', dark: '#9085e9' },
    { light: '#e34948', dark: '#e66767' },
];

const periods: { value: MetricPeriod; label: string }[] = [
    { value: '1h', label: '1 hour' },
    { value: '6h', label: '6 hours' },
    { value: '24h', label: '24 hours' },
    { value: '7d', label: '7 days' },
];

type Row = Record<string, number | null | string>;

const keyOf = (nodeId: string) => `n${nodeId}`;

function formatValue(
    value: number | null | undefined,
    spec: MetricSpec,
): string {
    if (value === null || value === undefined) {
        return '—';
    }

    const digits = spec.percent || spec.name === 'load_1m' ? 1 : 0;

    return `${value.toLocaleString(undefined, { maximumFractionDigits: digits })}${spec.unit}`;
}

function tickOf(period: MetricPeriod) {
    return (iso: string) =>
        new Date(iso).toLocaleString(
            undefined,
            period === '7d'
                ? { weekday: 'short', hour: '2-digit' }
                : { hour: '2-digit', minute: '2-digit' },
        );
}

function latestOf(history: MetricHistory, name: MetricName): (number | null)[] {
    const point = [...history.points]
        .reverse()
        .find((entry) =>
            Object.values(entry.values).some(
                (values) => values[name] !== null && values[name] !== undefined,
            ),
        );

    return history.nodes.map((node) => point?.values[node.id]?.[name] ?? null);
}

function StatCard({
    spec,
    history,
}: {
    spec: MetricSpec;
    history: MetricHistory;
}) {
    const values = latestOf(history, spec.name).filter(
        (value): value is number => value !== null,
    );
    const shown =
        values.length === 0
            ? null
            : spec.percent
              ? Math.max(...values)
              : values.reduce((sum, value) => sum + value, 0);
    const Icon = spec.icon;

    return (
        <Card className="gap-3 py-4">
            <CardContent className="space-y-3 px-4">
                <div className="flex items-center justify-between text-sm text-muted-foreground">
                    {spec.title}
                    <Icon className="size-4" />
                </div>
                <div className="text-2xl font-semibold tabular-nums">
                    {formatValue(shown, spec)}
                </div>
                {spec.percent ? (
                    <Progress
                        value={shown ?? 0}
                        className={cn(
                            'h-1.5',
                            (shown ?? 0) >= 90
                                ? '[&>*]:bg-red-500'
                                : (shown ?? 0) >= 75
                                  ? '[&>*]:bg-amber-500'
                                  : '[&>*]:bg-emerald-500',
                        )}
                    />
                ) : (
                    <div className="text-xs text-muted-foreground">
                        {values.length > 1 ? 'Total across nodes' : 'Now'}
                    </div>
                )}
                {spec.percent && values.length > 1 && (
                    <div className="text-xs text-muted-foreground">
                        Busiest node
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function MetricChart({
    spec,
    history,
    rows,
    config,
}: {
    spec: MetricSpec;
    history: MetricHistory;
    rows: Row[];
    config: ChartConfig;
}) {
    const Icon = spec.icon;
    const tick = tickOf(history.period);

    return (
        <Card className="gap-2 pb-2">
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-sm font-medium">
                    <Icon className="size-4 text-muted-foreground" />
                    {spec.title}
                </CardTitle>
            </CardHeader>
            <CardContent className="px-2">
                <ChartContainer
                    config={config}
                    className="aspect-auto h-56 w-full"
                >
                    <LineChart
                        data={rows}
                        margin={{ left: 4, right: 12, top: 8 }}
                    >
                        <CartesianGrid vertical={false} />
                        <XAxis
                            dataKey="t"
                            tickLine={false}
                            axisLine={false}
                            minTickGap={40}
                            tickFormatter={tick}
                        />
                        <YAxis
                            width={44}
                            tickLine={false}
                            axisLine={false}
                            domain={spec.percent ? [0, 100] : [0, 'auto']}
                            tickFormatter={(value: number) =>
                                `${value.toLocaleString()}${spec.unit}`
                            }
                        />
                        <ChartTooltip
                            content={
                                <ChartTooltipContent
                                    indicator="line"
                                    labelFormatter={(_, payload) =>
                                        new Date(
                                            String(payload[0]?.payload.t),
                                        ).toLocaleString()
                                    }
                                    formatter={(value, name) => (
                                        <div className="flex w-full items-center justify-between gap-4">
                                            <span className="text-muted-foreground">
                                                {config[String(name)]?.label}
                                            </span>
                                            <span className="font-mono font-medium tabular-nums">
                                                {formatValue(
                                                    Number(value),
                                                    spec,
                                                )}
                                            </span>
                                        </div>
                                    )}
                                />
                            }
                        />
                        {history.nodes.length > 1 && (
                            <ChartLegend content={<ChartLegendContent />} />
                        )}
                        {history.nodes.map((node) => (
                            <Line
                                key={node.id}
                                dataKey={keyOf(node.id)}
                                type="monotone"
                                stroke={`var(--color-${keyOf(node.id)})`}
                                strokeWidth={2}
                                dot={false}
                                activeDot={{ r: 4 }}
                                connectNulls={false}
                                isAnimationActive={false}
                            />
                        ))}
                    </LineChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}

function historyUrl(
    database: Database,
    node: DatabaseNode | undefined,
    period: MetricPeriod,
): string {
    const options = { query: { period } };

    return node
        ? DatabaseInsightsController.nodeHistory.url(
              { database: database.id, node: node.id },
              options,
          )
        : DatabaseInsightsController.history.url(
              { database: database.id },
              options,
          );
}

export default function InsightsTab({
    database,
    node,
}: {
    database: Database;
    node?: DatabaseNode;
}) {
    const [period, setPeriod] = useState<MetricPeriod>('1h');
    const [history, setHistory] = useState<MetricHistory | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(() => {
        setLoading(true);
        getJson<MetricHistory>(historyUrl(database, node, period))
            .then((result) => {
                setHistory(result);
                setError(null);
            })
            .catch((reason: Error) => setError(reason.message))
            .finally(() => setLoading(false));
    }, [database, node, period]);

    useEffect(() => {
        load();
        const timer = setInterval(load, 60000);

        return () => clearInterval(timer);
    }, [load]);

    const config = useMemo<ChartConfig>(
        () =>
            Object.fromEntries(
                (history?.nodes ?? []).map((node, index) => [
                    keyOf(node.id),
                    {
                        label: node.name,
                        theme: seriesColors[index % seriesColors.length],
                    },
                ]),
            ),
        [history],
    );

    const rowsFor = useCallback(
        (name: MetricName): Row[] =>
            (history?.points ?? []).map((point) => ({
                t: point.t,
                ...Object.fromEntries(
                    (history?.nodes ?? []).map((node) => [
                        keyOf(node.id),
                        point.values[node.id]?.[name] ?? null,
                    ]),
                ),
            })),
        [history],
    );

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <ToggleGroup
                    type="single"
                    variant="outline"
                    size="sm"
                    value={period}
                    onValueChange={(value) =>
                        value && setPeriod(value as MetricPeriod)
                    }
                >
                    {periods.map((entry) => (
                        <ToggleGroupItem
                            key={entry.value}
                            value={entry.value}
                            className="px-3"
                        >
                            {entry.label}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={load}
                    disabled={loading}
                >
                    <RefreshCw className={cn(loading && 'animate-spin')} />
                    Refresh
                </Button>
            </div>

            {error && (
                <p className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                    {error}
                </p>
            )}

            {!history && loading && (
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    {metrics.map((spec) => (
                        <Skeleton key={spec.name} className="h-72 rounded-xl" />
                    ))}
                </div>
            )}

            {history && history.points.length === 0 && (
                <EmptyState
                    icon={ChartNoAxesCombined}
                    title="Collecting metrics"
                    description="Every node is sampled once a minute. The first points appear here shortly, and history is kept for 7 days."
                />
            )}

            {history && history.points.length > 0 && (
                <>
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
                        {metrics
                            .filter((spec) =>
                                [
                                    'cpu_percent',
                                    'memory_percent',
                                    'disk_percent',
                                    'connections',
                                ].includes(spec.name),
                            )
                            .map((spec) => (
                                <StatCard
                                    key={spec.name}
                                    spec={spec}
                                    history={history}
                                />
                            ))}
                    </div>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        {metrics.map((spec) => (
                            <MetricChart
                                key={spec.name}
                                spec={spec}
                                history={history}
                                rows={rowsFor(spec.name)}
                                config={config}
                            />
                        ))}
                    </div>
                </>
            )}
        </div>
    );
}
