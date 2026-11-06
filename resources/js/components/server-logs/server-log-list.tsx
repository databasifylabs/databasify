import { router, usePage } from '@inertiajs/react';
import { ArrowUp, History, Layers } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import CursorPagination from '@/components/cursor-pagination';
import EmptyState from '@/components/empty-state';
import LiveDuration from '@/components/server-logs/live-duration';
import ServerLogOutput from '@/components/server-logs/server-log-output';
import StatusDot from '@/components/status-dot';
import { Button } from '@/components/ui/button';
import { dayLabel, pluralize, timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    CursorPaginated,
    ServerLog,
    ServerLogStatus,
    ServerLogWithOutput,
} from '@/types';

type Entry =
    | { kind: 'log'; log: ServerLog }
    | { kind: 'group'; id: string; name: string; logs: ServerLog[] };

type Day = { label: string; entries: Entry[] };

function entriesOf(logs: ServerLog[]): Entry[] {
    return logs.reduce<Entry[]>((entries, log) => {
        const last = entries.at(-1);

        if (log.group === null) {
            return [...entries, { kind: 'log', log }];
        }

        if (last?.kind === 'group' && last.id === log.group.id) {
            last.logs.push(log);

            return entries;
        }

        return [
            ...entries,
            {
                kind: 'group',
                id: log.group.id,
                name: log.group.name ?? 'Parallel step',
                logs: [log],
            },
        ];
    }, []);
}

function daysOf(logs: ServerLog[]): Day[] {
    const days = logs.reduce<{ label: string; logs: ServerLog[] }[]>(
        (days, log) => {
            const label = dayLabel(log.created_at);
            const last = days.at(-1);

            if (last?.label === label) {
                last.logs.push(log);

                return days;
            }

            return [...days, { label, logs: [log] }];
        },
        [],
    );

    return days.map((day) => ({
        label: day.label,
        entries: entriesOf(day.logs),
    }));
}

function statusOfGroup(logs: ServerLog[]): ServerLogStatus {
    if (logs.some((log) => log.status === 'failed')) {
        return 'failed';
    }

    return logs.some((log) => log.status === 'running')
        ? 'running'
        : 'succeeded';
}

export function liveLogProps(
    { data: logs, links }: CursorPaginated<ServerLog>,
    log: ServerLogWithOutput | null,
): string[] {
    const live =
        log === null ||
        [log, ...logs].some((entry) => entry.status === 'running');

    return [
        ...(links.prev === null ? ['logs'] : []),
        'latestLogId',
        ...(live ? ['log'] : []),
    ];
}

function useFollowLatestLog(
    log: ServerLogWithOutput | null,
    latestLogId: string | null,
    pendingId: string | null,
): () => void {
    const { url } = usePage();
    const following = !new URL(url, window.location.origin).searchParams.has(
        'log',
    );
    const shownId = log?.id ?? null;
    const cancelFollow = useRef(() => {});

    useEffect(() => {
        if (!following || pendingId !== null || latestLogId === shownId) {
            return;
        }

        router.reload({
            only: ['log'],
            onCancelToken: ({ cancel }) => {
                cancelFollow.current = cancel;
            },
        });
    }, [following, pendingId, latestLogId, shownId]);

    return () => cancelFollow.current();
}

function useHasNewerLogs(onFirstPage: boolean, latestLogId: string | null) {
    const [seenLatestId, setSeenLatestId] = useState(latestLogId);

    if (onFirstPage && seenLatestId !== latestLogId) {
        setSeenLatestId(latestLogId);
    }

    return !onFirstPage && seenLatestId !== latestLogId;
}

function NewerLogsNotice() {
    const showLatest = () =>
        router.reload({ only: ['logs'], data: { cursor: undefined } });

    return (
        <Button
            variant="secondary"
            size="sm"
            className="w-full"
            onClick={showLatest}
        >
            <ArrowUp /> Show new activity
        </Button>
    );
}

function LogRow({
    log,
    selected,
    nested = false,
    onSelect,
}: {
    log: ServerLog;
    selected: boolean;
    nested?: boolean;
    onSelect: (id: string) => void;
}) {
    return (
        <button
            type="button"
            onClick={() => onSelect(log.id)}
            aria-current={selected}
            className={cn(
                'flex w-full items-start gap-3 border-b px-3 py-2.5 text-left last:border-b-0 hover:bg-muted/50',
                nested && 'pl-7',
                selected && 'bg-muted hover:bg-muted',
            )}
        >
            <StatusDot status={log.status} className="mt-1.5" />
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium">{log.name}</p>
                <p className="truncate text-xs text-muted-foreground tabular-nums">
                    {log.server.name} ·{' '}
                    {log.status === 'running' ? (
                        <LiveDuration log={log} />
                    ) : (
                        <>
                            {timeAgo(log.created_at)}
                            <LiveDuration log={log} prefix=" in " />
                        </>
                    )}
                    {log.status === 'failed' && log.exit_code !== null && (
                        <span className="text-red-600 dark:text-red-400">
                            {` · exit ${log.exit_code}`}
                        </span>
                    )}
                </p>
            </div>
        </button>
    );
}

function GroupHeader({ name, logs }: { name: string; logs: ServerLog[] }) {
    return (
        <div className="flex items-center gap-3 border-b bg-muted/30 px-3 py-2">
            <Layers className="size-3.5 shrink-0 text-muted-foreground" />
            <p className="min-w-0 flex-1 truncate text-xs font-medium">
                {name}
            </p>
            <span className="shrink-0 text-xs text-muted-foreground">
                {pluralize(logs.length, 'server')}
            </span>
            <StatusDot status={statusOfGroup(logs)} />
        </div>
    );
}

export default function ServerLogList({
    logs: { data: logs, links },
    log,
    latestLogId,
}: {
    logs: CursorPaginated<ServerLog>;
    log: ServerLogWithOutput | null;
    latestLogId: string | null;
}) {
    const [pendingId, setPendingId] = useState<string | null>(null);
    const selectedId = pendingId ?? log?.id ?? null;
    const onFirstPage = links.prev === null;
    const hasNewerLogs = useHasNewerLogs(onFirstPage, latestLogId);

    const cancelFollow = useFollowLatestLog(log, latestLogId, pendingId);

    const select = (id: string) => {
        if (id === selectedId) {
            return;
        }

        cancelFollow();
        setPendingId(id);
        router.reload({
            only: ['log'],
            data: { log: id },
            replace: true,
            onFinish: () => setPendingId(null),
        });
    };

    if (logs.length === 0 && onFirstPage) {
        return (
            <EmptyState
                icon={History}
                title="No activity yet"
                description="Every change on a server is logged here with its live output"
            />
        );
    }

    return (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
            <div className="min-w-0 space-y-3">
                {hasNewerLogs && <NewerLogsNotice />}
                <nav
                    aria-label="Activity"
                    className="max-h-72 overflow-y-auto rounded-lg border lg:max-h-[36rem]"
                >
                    {daysOf(logs).map((day) => (
                        <section key={day.label}>
                            <h3 className="sticky top-0 z-10 border-b bg-background px-3 py-1.5 text-xs font-medium text-muted-foreground">
                                {day.label}
                            </h3>
                            {day.entries.map((entry) =>
                                entry.kind === 'log' ? (
                                    <LogRow
                                        key={entry.log.id}
                                        log={entry.log}
                                        selected={entry.log.id === selectedId}
                                        onSelect={select}
                                    />
                                ) : (
                                    <div key={entry.id}>
                                        <GroupHeader
                                            name={entry.name}
                                            logs={entry.logs}
                                        />
                                        {entry.logs.map((member) => (
                                            <LogRow
                                                key={member.id}
                                                log={member}
                                                nested
                                                selected={
                                                    member.id === selectedId
                                                }
                                                onSelect={select}
                                            />
                                        ))}
                                    </div>
                                ),
                            )}
                        </section>
                    ))}
                </nav>
                <CursorPagination links={links} only={['logs']} />
            </div>
            {log === null ? (
                <div className="flex h-[28rem] items-center justify-center rounded-lg border border-neutral-800 bg-neutral-950 text-sm text-neutral-500 lg:h-[36rem]">
                    Choose a log to see its output
                </div>
            ) : (
                <ServerLogOutput
                    key={log.id}
                    log={log}
                    loading={pendingId !== null}
                />
            )}
        </div>
    );
}
