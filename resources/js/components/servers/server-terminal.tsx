import { echo } from '@laravel/echo-react';
import { FitAddon } from '@xterm/addon-fit';
import { Terminal } from '@xterm/xterm';
import '@xterm/xterm/css/xterm.css';
import { RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ServerTerminalController from '@/actions/App/Http/Controllers/Servers/ServerTerminalController';
import { Button } from '@/components/ui/button';
import { deleteJson, postJson } from '@/lib/http';
import { cn } from '@/lib/utils';
import type { Server } from '@/types';

type TerminalEvent = { data: string; closed: boolean };

type Status = 'connecting' | 'open' | 'closed';

const statusLabels: Record<Status, string> = {
    connecting: 'Connecting',
    open: 'Connected',
    closed: 'Disconnected',
};

const statusColors: Record<Status, string> = {
    connecting: 'bg-blue-500 animate-pulse',
    open: 'bg-green-500',
    closed: 'bg-neutral-400',
};

function bytesOf(base64: string): Uint8Array {
    return Uint8Array.from(atob(base64), (char) => char.charCodeAt(0));
}

function base64Of(text: string): string {
    return btoa(
        Array.from(new TextEncoder().encode(text), (byte) =>
            String.fromCharCode(byte),
        ).join(''),
    );
}

export default function ServerTerminal({ server }: { server: Server }) {
    const container = useRef<HTMLDivElement>(null);
    const [status, setStatus] = useState<Status>('connecting');
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        const element = container.current;

        if (!element) {
            return;
        }

        const terminal = new Terminal({
            cursorBlink: true,
            fontFamily:
                'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
            fontSize: 13,
            scrollback: 5000,
            theme: { background: '#0a0a0a' },
        });
        const fit = new FitAddon();
        terminal.loadAddon(fit);
        terminal.open(element);
        fit.fit();
        terminal.focus();

        let id: string | null = null;
        let pending = '';
        let sending = false;
        let disposed = false;

        const close = (terminalId: string) =>
            deleteJson(
                ServerTerminalController.destroy.url({
                    server: server.id,
                    terminal: terminalId,
                }),
            );

        const flush = async (): Promise<void> => {
            if (sending || id === null || pending === '') {
                return;
            }

            const data = pending;
            pending = '';
            sending = true;

            try {
                await postJson(
                    ServerTerminalController.input.url({
                        server: server.id,
                        terminal: id,
                    }),
                    { data: base64Of(data) },
                );
            } catch {
                setStatus('closed');
            }

            sending = false;
            void flush();
        };

        const typing = terminal.onData((data) => {
            pending += data;
            void flush();
        });

        postJson<{ id: string }>(
            ServerTerminalController.store.url(server.id),
            {
                columns: terminal.cols,
                rows: terminal.rows,
            },
        )
            .then(({ id: opened }) => {
                if (disposed) {
                    close(opened);

                    return;
                }

                id = opened;
                echo()
                    .private(`terminals.${opened}`)
                    .listen(
                        'TerminalOutputReceived',
                        (event: TerminalEvent) => {
                            if (event.data) {
                                terminal.write(bytesOf(event.data));
                            }

                            if (event.closed) {
                                terminal.write(
                                    '\r\n\x1b[90mSession closed.\x1b[0m\r\n',
                                );
                            }

                            setStatus(event.closed ? 'closed' : 'open');
                        },
                    );
                void flush();
            })
            .catch((error: Error) => {
                terminal.write(`\x1b[31m${error.message}\x1b[0m\r\n`);
                setStatus('closed');
            });

        const leave = () => {
            if (id !== null) {
                close(id);
            }
        };
        window.addEventListener('pagehide', leave);

        return () => {
            disposed = true;
            typing.dispose();
            window.removeEventListener('pagehide', leave);

            if (id !== null) {
                echo().leave(`terminals.${id}`);
                close(id);
            }

            terminal.dispose();
        };
    }, [server.id, attempt]);

    return (
        <div className="overflow-hidden rounded-lg border">
            <div className="flex items-center gap-3 border-b px-4 py-2">
                <span
                    className={cn(
                        'inline-block size-2 shrink-0 rounded-full',
                        statusColors[status],
                    )}
                />
                <p className="min-w-0 flex-1 truncate font-mono text-sm">
                    root@{server.name}
                </p>
                <span className="text-sm text-muted-foreground">
                    {statusLabels[status]}
                </span>
                {status === 'closed' && (
                    <Button
                        size="sm"
                        variant="outline"
                        onClick={() => {
                            setStatus('connecting');
                            setAttempt((current) => current + 1);
                        }}
                    >
                        <RotateCcw />
                        Reconnect
                    </Button>
                )}
            </div>
            <div className="bg-neutral-950 p-3">
                <div ref={container} className="h-[32rem]" />
            </div>
        </div>
    );
}
