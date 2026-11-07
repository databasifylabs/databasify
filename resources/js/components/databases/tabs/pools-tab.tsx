import { Waypoints, Plus } from 'lucide-react';
import { useState } from 'react';
import ConnectionPoolController from '@/actions/App/Http/Controllers/Databases/ConnectionPoolController';
import ConfirmDialog from '@/components/confirm-dialog';
import CopyButton from '@/components/copy-button';
import DeleteButton from '@/components/delete-button';
import EmptyState from '@/components/empty-state';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { runAction } from '@/lib/run-action';
import type { Database, ConnectionPool } from '@/types';

const modes = [
    {
        value: 'transaction',
        label: 'Transaction',
        hint: 'A server connection per transaction. Best for most web apps.',
    },
    {
        value: 'session',
        label: 'Session',
        hint: 'A server connection for the whole client session.',
    },
    {
        value: 'statement',
        label: 'Statement',
        hint: 'A server connection per statement. No multi-statement transactions.',
    },
];

function poolUri(database: Database, pool: ConnectionPool): string {
    return `postgresql://<user>:<password>@${database.endpoints.public.host}:${database.pool_port}/${pool.name}?sslmode=require`;
}

function AddPoolForm({
    database,
    busy,
}: {
    database: Database;
    busy: boolean;
}) {
    const [name, setName] = useState('');
    const [schema, setSchema] = useState(database.schemas[0]?.name ?? '');
    const [mode, setMode] = useState('transaction');
    const [size, setSize] = useState('20');
    const [saving, setSaving] = useState(false);

    const submit = () => {
        setSaving(true);
        runAction(ConnectionPoolController.store({ database: database.id }), {
            data: {
                name,
                schema,
                mode,
                size: Number(size),
            },
            success: `Adding pool ${name}`,
            onSuccess: () => setName(''),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
            <div className="space-y-1.5">
                <Label htmlFor="pool-name">Pool name</Label>
                <Input
                    id="pool-name"
                    value={name}
                    onChange={(event) =>
                        setName(event.target.value.toLowerCase())
                    }
                    placeholder="app_pool"
                    autoComplete="off"
                />
                <p className="text-xs text-muted-foreground">
                    Clients use it as the database name on port{' '}
                    {database.pool_port}.
                </p>
            </div>
            <div className="space-y-1.5">
                <Label>Database</Label>
                <Select value={schema} onValueChange={setSchema}>
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Pick a database" />
                    </SelectTrigger>
                    <SelectContent>
                        {database.schemas.map((entry) => (
                            <SelectItem key={entry.id} value={entry.name}>
                                {entry.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            <div className="grid grid-cols-[1fr_7rem] gap-3">
                <div className="space-y-1.5">
                    <Label>Mode</Label>
                    <Select value={mode} onValueChange={setMode}>
                        <SelectTrigger className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {modes.map((entry) => (
                                <SelectItem
                                    key={entry.value}
                                    value={entry.value}
                                >
                                    {entry.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="pool-size">Size</Label>
                    <Input
                        id="pool-size"
                        type="number"
                        min={1}
                        value={size}
                        onChange={(event) => setSize(event.target.value)}
                    />
                </div>
                <p className="col-span-2 text-xs text-muted-foreground">
                    {modes.find((entry) => entry.value === mode)?.hint}
                </p>
            </div>
            <div className="flex justify-end md:col-span-2">
                <Button
                    onClick={submit}
                    disabled={busy || saving || !name || !schema}
                >
                    <Plus />
                    Add pool
                </Button>
            </div>
        </div>
    );
}

export default function PoolsTab({
    database,
    busy,
}: {
    database: Database;
    busy: boolean;
}) {
    return (
        <div className="space-y-6">
            {database.pools.length === 0 ? (
                <EmptyState
                    icon={Waypoints}
                    title="No connection pools yet"
                    description="A pool lets many app connections share a few PostgreSQL connections, through PgBouncer on every node."
                />
            ) : (
                <Card className="gap-0 py-0">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-6">Pool</TableHead>
                                <TableHead>Database</TableHead>
                                <TableHead>Mode</TableHead>
                                <TableHead>Size</TableHead>
                                <TableHead className="w-24 pr-6" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {database.pools.map((pool) => (
                                <TableRow key={pool.id}>
                                    <TableCell className="pl-6 font-medium">
                                        {pool.name}
                                    </TableCell>
                                    <TableCell>{pool.schema}</TableCell>
                                    <TableCell className="capitalize">
                                        {pool.mode}
                                    </TableCell>
                                    <TableCell>{pool.size}</TableCell>
                                    <TableCell className="pr-6">
                                        <div className="flex justify-end gap-1">
                                            <CopyButton
                                                value={poolUri(database, pool)}
                                                label="Copy URI"
                                            />
                                            <ConfirmDialog
                                                trigger={
                                                    <DeleteButton
                                                        disabled={busy}
                                                        aria-label={`Remove ${pool.name}`}
                                                    />
                                                }
                                                title={`Remove pool ${pool.name}?`}
                                                description="Apps connected through this pool are disconnected."
                                                confirmLabel="Remove pool"
                                                destructive
                                                onConfirm={() =>
                                                    runAction(
                                                        ConnectionPoolController.destroy(
                                                            {
                                                                database:
                                                                    database.id,
                                                                connectionPool:
                                                                    pool.id,
                                                            },
                                                        ),
                                                        {
                                                            success: `Removing ${pool.name}`,
                                                        },
                                                    )
                                                }
                                            />
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
            <Card>
                <CardHeader>
                    <CardTitle>Add a connection pool</CardTitle>
                    <CardDescription>
                        Clients connect with their own database user and
                        password, with TLS required.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <AddPoolForm database={database} busy={busy} />
                </CardContent>
            </Card>
        </div>
    );
}
