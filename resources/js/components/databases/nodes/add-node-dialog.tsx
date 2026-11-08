import { Link } from '@inertiajs/react';
import { Check, Plus, Server as ServerIcon } from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import DatabaseNodeController from '@/actions/App/Http/Controllers/Databases/DatabaseNodeController';
import ServerController from '@/actions/App/Http/Controllers/Servers/ServerController';
import { ServerSpecItems } from '@/components/servers/server-specs';
import { runAction } from '@/lib/run-action';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import type { Database, Server } from '@/types';

const copy = {
    member: {
        title: 'Add a standby node',
        description:
            'A standby keeps a full copy of the data and takes over automatically if the primary fails. We suggest at least 1 GB of RAM on every node.',
        action: 'Add standby node',
    },
    read_only: {
        title: 'Add a read-only node',
        description:
            'A read-only node has its own name and host, serves reads from its own copy on the same port, and is never promoted to primary.',
        action: 'Add read-only node',
    },
} as const;

export default function AddNodeDialog({
    database,
    servers: freeServers,
    kind,
    trigger,
}: {
    database: Database;
    servers: Server[];
    kind: 'member' | 'read_only';
    trigger: ReactNode;
}) {
    const [open, setOpen] = useState(false);
    const [serverId, setServerId] = useState<string | null>(null);
    const [name, setName] = useState('');
    const [busy, setBusy] = useState(false);
    const readOnly = kind === 'read_only';
    const servers = freeServers.filter(
        (server) =>
            database.network !== null &&
            server.network_id === database.network.id,
    );

    const submit = () => {
        if (serverId === null) {
            return;
        }

        setBusy(true);
        runAction(DatabaseNodeController.store({ database: database.id }), {
            data: {
                server_id: serverId,
                kind,
                ...(readOnly && name.trim() ? { name: name.trim() } : {}),
            },
            success: `Adding ${servers.find((server) => server.id === serverId)?.name ?? 'the server'} to ${database.name}`,
            onSuccess: () => setOpen(false),
            onFinish: () => setBusy(false),
        });
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                setOpen(value);
                setServerId(null);
                setName('');
            }}
        >
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>{copy[kind].title}</DialogTitle>
                    <DialogDescription>
                        {copy[kind].description}
                    </DialogDescription>
                </DialogHeader>
                {database.network === null ? (
                    <div className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        {database.name} has no private network, so it can't have
                        more nodes.
                    </div>
                ) : servers.length === 0 ? (
                    <div className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                        No free servers on {database.network.name}.{' '}
                        <Link
                            href={ServerController.create()}
                            className="text-primary underline"
                        >
                            Add a server
                        </Link>{' '}
                        and wait for setup to finish.
                    </div>
                ) : (
                    <div className="grid max-h-80 gap-2 overflow-y-auto">
                        {servers.map((server) => (
                            <button
                                key={server.id}
                                type="button"
                                onClick={() => setServerId(server.id)}
                                className={cn(
                                    'flex items-center gap-3 rounded-lg border p-3 text-left transition',
                                    serverId === server.id
                                        ? 'border-primary ring-2 ring-primary/20'
                                        : 'hover:bg-muted/50',
                                )}
                            >
                                <span className="flex size-9 items-center justify-center rounded-lg bg-muted">
                                    <ServerIcon className="size-4" />
                                </span>
                                <span className="flex-1">
                                    <span className="block font-medium">
                                        {server.name}
                                    </span>
                                    <span className="flex gap-3 text-xs text-muted-foreground">
                                        <ServerSpecItems server={server} />
                                        <span>{server.public_ip}</span>
                                    </span>
                                </span>
                                {serverId === server.id && (
                                    <Check className="size-4 text-primary" />
                                )}
                            </button>
                        ))}
                    </div>
                )}
                {readOnly && servers.length > 0 && (
                    <div className="space-y-2">
                        <Label htmlFor="node-name">Name</Label>
                        <Input
                            id="node-name"
                            value={name}
                            onChange={(event) =>
                                setName(event.target.value.toLowerCase())
                            }
                            placeholder="reports"
                            maxLength={60}
                        />
                        <p className="text-xs text-muted-foreground">
                            Lowercase letters, numbers and dashes. Leave empty
                            to name it after {database.name}.
                        </p>
                    </div>
                )}
                <DialogFooter>
                    <Button
                        onClick={submit}
                        disabled={serverId === null || busy}
                    >
                        <Plus />
                        {copy[kind].action}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
