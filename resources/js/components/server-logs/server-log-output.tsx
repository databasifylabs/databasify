import { useEcho } from '@laravel/echo-react';
import type { UIEvent } from 'react';
import { useEffect, useMemo, useRef, useState } from 'react';
import CopyButton from '@/components/copy-button';
import LiveDuration from '@/components/server-logs/live-duration';
import StatusBadge from '@/components/status-badge';
import type { OutputPiece } from '@/lib/stitch-output';
import { pieceOf, stitchOutput, unsavedPieces } from '@/lib/stitch-output';
import { cn } from '@/lib/utils';
import type { ServerLogStatus, ServerLogWithOutput } from '@/types';

type OutputEvent = { chunk: string; offset: number; status: ServerLogStatus };

const followDistance = 24;

function placeholderOf(log: ServerLogWithOutput): string {
    return log.status === 'running' ? 'Waiting for output...' : 'No output';
}

export default function ServerLogOutput({
    log,
    loading = false,
}: {
    log: ServerLogWithOutput;
    loading?: boolean;
}) {
    const [pieces, setPieces] = useState<OutputPiece[]>([]);
    const saved = useRef(log.output);
    const terminal = useRef<HTMLPreElement>(null);
    const following = useRef(true);

    useEffect(() => {
        saved.current = log.output;
    }, [log.output]);

    useEcho<OutputEvent>(
        `server-logs.${log.id}`,
        'ServerLogOutputReceived',
        (event) =>
            setPieces((current) => [
                ...unsavedPieces(saved.current, current),
                pieceOf(event.chunk, event.offset),
            ]),
        [log.id],
    );

    const output = useMemo(
        () => stitchOutput(log.output, pieces),
        [log.output, pieces],
    );

    useEffect(() => {
        const element = terminal.current;

        if (element && following.current) {
            element.scrollTop = element.scrollHeight;
        }
    }, [output]);

    const follow = ({ currentTarget: element }: UIEvent<HTMLPreElement>) => {
        following.current =
            element.scrollHeight - element.scrollTop - element.clientHeight <
            followDistance;
    };

    return (
        <div
            className={cn(
                'flex h-[28rem] min-w-0 flex-col overflow-hidden rounded-lg border border-neutral-800 bg-neutral-950 transition-opacity lg:h-[36rem]',
                loading && 'opacity-60',
            )}
        >
            <div className="flex items-center gap-3 border-b border-neutral-800 px-4 py-2.5">
                <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-medium text-neutral-100">
                        {log.name}
                    </p>
                    <p className="truncate font-mono text-xs text-neutral-400">
                        {log.server.name}
                        <LiveDuration log={log} prefix=" · " />
                        {log.status === 'failed' &&
                            log.exit_code !== null &&
                            ` · exit ${log.exit_code}`}
                    </p>
                </div>
                <StatusBadge status={log.status} className="shrink-0" />
                <span className="shrink-0 text-neutral-400">
                    <CopyButton value={output} label="Copy output" />
                </span>
            </div>
            <pre
                ref={terminal}
                onScroll={follow}
                className="min-h-0 flex-1 overflow-auto p-4 font-mono text-xs leading-relaxed break-all whitespace-pre-wrap text-neutral-200"
            >
                {output || (
                    <span className="text-neutral-500">
                        {placeholderOf(log)}
                    </span>
                )}
            </pre>
        </div>
    );
}
