import { Download, RefreshCw, ScrollText, Search } from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import DatabaseInsightsController from '@/actions/App/Http/Controllers/Databases/DatabaseInsightsController';
import EmptyState from '@/components/empty-state';
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
import { Switch } from '@/components/ui/switch';
import { downloadFile } from '@/lib/download';
import { getJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { Database, DatabaseNode } from '@/types';

const lineCounts = ['100', '200', '500', '1000', '2000'];

function levelOf(line: string): string {
    if (/\b(error|fatal|panic|crit)/i.test(line)) {
        return 'text-red-400';
    }

    if (/\b(warn|warning)/i.test(line)) {
        return 'text-amber-300';
    }

    return 'text-neutral-200';
}

export default function LogsTab({
    database,
    nodes,
}: {
    database: Database;
    nodes: DatabaseNode[];
}) {
    const [nodeId, setNodeId] = useState(String(nodes[0]?.id ?? ''));
    const [service, setService] = useState(database.services[0] ?? '');
    const [lines, setLines] = useState('200');
    const [filter, setFilter] = useState('');
    const [follow, setFollow] = useState(false);
    const [logs, setLogs] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const end = useRef<HTMLDivElement>(null);

    const load = useCallback(() => {
        if (!nodeId || !service) {
            return;
        }

        setLoading(true);
        getJson<{ logs: string }>(
            DatabaseInsightsController.logs.url(
                { database: database.id, node: nodeId },
                { query: { service, lines } },
            ),
        )
            .then((result) => {
                setLogs(result.logs);
                setError(null);
            })
            .catch((reason: Error) => setError(reason.message))
            .finally(() => setLoading(false));
    }, [database.id, nodeId, service, lines]);

    useEffect(() => {
        load();
    }, [load]);

    useEffect(() => {
        if (!follow) {
            return;
        }

        const timer = setInterval(load, 5000);

        return () => clearInterval(timer);
    }, [follow, load]);

    useEffect(() => {
        end.current?.scrollIntoView({ block: 'nearest' });
    }, [logs]);

    const shown = useMemo(() => {
        const all = (logs ?? '').split('\n');
        const needle = filter.trim().toLowerCase();

        return needle
            ? all.filter((line) => line.toLowerCase().includes(needle))
            : all;
    }, [logs, filter]);

    const node = nodes.find((entry) => entry.id === nodeId);

    const download = () =>
        downloadFile(
            `${database.name}-${node?.name ?? 'node'}-${service}.log`,
            logs ?? '',
            'text/plain',
        );

    if (nodes.length === 0) {
        return (
            <EmptyState
                icon={ScrollText}
                title="No nodes yet"
                description="Logs appear once the database has a node running."
            />
        );
    }

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-end gap-3">
                <div className="space-y-1.5">
                    <Label className="text-xs text-muted-foreground">
                        Node
                    </Label>
                    <Select value={nodeId} onValueChange={setNodeId}>
                        <SelectTrigger size="sm" className="w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {nodes.map((entry) => (
                                <SelectItem key={entry.id} value={entry.id}>
                                    {entry.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1.5">
                    <Label className="text-xs text-muted-foreground">
                        Service
                    </Label>
                    <Select value={service} onValueChange={setService}>
                        <SelectTrigger size="sm" className="w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {database.services.map((entry) => (
                                <SelectItem key={entry} value={entry}>
                                    {entry}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1.5">
                    <Label className="text-xs text-muted-foreground">
                        Lines
                    </Label>
                    <Select value={lines} onValueChange={setLines}>
                        <SelectTrigger size="sm" className="w-24">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {lineCounts.map((count) => (
                                <SelectItem key={count} value={count}>
                                    {count}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="relative min-w-48 flex-1">
                    <Search className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                        value={filter}
                        onChange={(event) => setFilter(event.target.value)}
                        placeholder="Filter lines"
                        className="h-8 pl-8"
                    />
                </div>
                <label className="flex h-8 items-center gap-2 text-sm">
                    <Switch checked={follow} onCheckedChange={setFollow} />
                    Live
                </label>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={load}
                    disabled={loading}
                >
                    <RefreshCw className={cn(loading && 'animate-spin')} />
                    Refresh
                </Button>
                <Button
                    variant="ghost"
                    size="sm"
                    onClick={download}
                    disabled={!logs}
                >
                    <Download />
                    Download
                </Button>
            </div>

            {error && (
                <p className="rounded-lg border border-destructive/30 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                    {error}
                </p>
            )}

            <div className="overflow-hidden rounded-xl border bg-neutral-950">
                <div className="flex items-center justify-between border-b border-white/10 px-4 py-2 text-xs text-neutral-400">
                    <span className="font-mono">
                        {node?.name} / {service}
                    </span>
                    <span>
                        {logs === null
                            ? ''
                            : filter
                              ? `${shown.length} matching`
                              : `${shown.length} lines`}
                    </span>
                </div>
                <pre className="h-[32rem] overflow-auto p-4 font-mono text-xs leading-relaxed">
                    {logs === null
                        ? 'Loading…'
                        : shown.map((line, index) => (
                              <div key={index} className={levelOf(line)}>
                                  {line || ' '}
                              </div>
                          ))}
                    <div ref={end} />
                </pre>
            </div>
        </div>
    );
}
