import {
    ChevronDown,
    EllipsisVertical,
    Package,
    Power,
    RefreshCw,
    RotateCcw,
    Trash2,
} from 'lucide-react';
import { useState } from 'react';
import ServerController from '@/actions/App/Http/Controllers/Servers/ServerController';
import ServerOperationController from '@/actions/App/Http/Controllers/Servers/ServerOperationController';
import ConfirmDialog from '@/components/confirm-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { runAction } from '@/lib/run-action';
import type { Server } from '@/types';

type Variant = 'button' | 'icon';

export default function ServerActions({
    server,
    busy,
    variant = 'button',
}: {
    server: Server;
    busy: boolean;
    variant?: Variant;
}) {
    const [rebooting, setRebooting] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const [destroyAtProvider, setDestroyAtProvider] = useState(true);
    const ready = server.status === 'ready';
    const failed = server.status === 'failed';
    const retrySetup = () =>
        runAction(ServerOperationController.provision(server.id), {
            success: 'Retrying setup',
        });
    const operation = (name: 'update-packages' | 'reboot', label: string) =>
        runAction(
            ServerOperationController.store({
                server: server.id,
                operation: name,
            }),
            { success: label },
        );

    return (
        <div className="flex flex-wrap gap-2">
            {failed && variant === 'button' && (
                <Button disabled={busy} onClick={retrySetup}>
                    <RotateCcw />
                    Retry setup
                </Button>
            )}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    {variant === 'button' ? (
                        <Button variant="outline">
                            Actions
                            <ChevronDown />
                        </Button>
                    ) : (
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Actions"
                        >
                            <EllipsisVertical />
                        </Button>
                    )}
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-52">
                    {failed && variant === 'icon' && (
                        <DropdownMenuItem disabled={busy} onSelect={retrySetup}>
                            <RotateCcw />
                            Retry setup
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem
                        disabled={!ready || busy}
                        onSelect={() =>
                            runAction(
                                ServerOperationController.refresh(server.id),
                                { success: 'Refreshing details' },
                            )
                        }
                    >
                        <RefreshCw />
                        Refresh details
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!ready || busy}
                        onSelect={() =>
                            operation('update-packages', 'Updating packages')
                        }
                    >
                        <Package />
                        Update packages
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        disabled={!ready || busy}
                        onSelect={() => setRebooting(true)}
                    >
                        <Power />
                        Reboot
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        disabled={busy || Boolean(server.database)}
                        onSelect={() => setDeleting(true)}
                    >
                        <Trash2 />
                        Remove server
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <ConfirmDialog
                open={rebooting}
                onOpenChange={setRebooting}
                title={`Reboot ${server.name}?`}
                description={
                    server.database
                        ? `${server.database.name} loses this node until it comes back. Standbys are safe to reboot; fail over first if this is the primary.`
                        : 'The server is unreachable for a minute or two.'
                }
                confirmLabel="Reboot"
                onConfirm={() => operation('reboot', 'Rebooting')}
            />
            <ConfirmDialog
                open={deleting}
                onOpenChange={setDeleting}
                title={`Remove ${server.name}?`}
                description={
                    server.provider
                        ? 'Databasify forgets this server and its history.'
                        : 'Databasify forgets this server and its history. Nothing on the server itself is changed.'
                }
                confirmLabel="Remove server"
                destructive
                typeToConfirm={server.name}
                onConfirm={() =>
                    runAction(ServerController.destroy(server.id), {
                        data: {
                            destroy_at_provider:
                                Boolean(server.provider) && destroyAtProvider,
                        },
                        success: `Removed ${server.name}`,
                    })
                }
            >
                {server.provider && (
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={destroyAtProvider}
                            onCheckedChange={(checked) =>
                                setDestroyAtProvider(checked === true)
                            }
                        />
                        Also destroy the {server.provider.type.server_label} at{' '}
                        {server.provider.type.label}
                    </label>
                )}
            </ConfirmDialog>
        </div>
    );
}
