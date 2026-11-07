import { Form, Head, Link, usePoll } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Cloud,
    Cpu,
    DatabaseZap,
    Fingerprint,
    Globe,
    HardDrive,
    History,
    KeyRound,
    LayoutDashboard,
    MemoryStick,
    MonitorCog,
    Network,
    Pencil,
    Plus,
    Server as ServerIcon,
    SquareTerminal,
    UserRound,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import ServerController from '@/actions/App/Http/Controllers/Servers/ServerController';
import ServerNetworkController from '@/actions/App/Http/Controllers/Servers/ServerNetworkController';
import ServerUserController from '@/actions/App/Http/Controllers/Servers/ServerUserController';
import ConfirmDialog from '@/components/confirm-dialog';
import DeleteButton from '@/components/delete-button';
import CopyButton from '@/components/copy-button';
import EmptyState from '@/components/empty-state';
import BrandIcon from '@/components/brand-icon';
import StatusDot from '@/components/status-dot';
import StatusBadge from '@/components/status-badge';
import TabNav from '@/components/tab-nav';
import type { TabSpec } from '@/components/tab-nav';
import ServerLogList, {
    liveLogProps,
} from '@/components/server-logs/server-log-list';
import { runAction } from '@/lib/run-action';
import InputError from '@/components/input-error';
import PrivateNetworkFields from '@/components/servers/private-network-fields';
import ServerActions from '@/components/servers/server-actions';
import ServerDatabase from '@/components/servers/server-database';
import ServerSource from '@/components/servers/server-source';
import ServerTerminal from '@/components/servers/server-terminal';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent } from '@/components/ui/tabs';
import { useQueryTab } from '@/hooks/use-query-tab';
import { Textarea } from '@/components/ui/textarea';
import { formatMemory, timeAgo } from '@/lib/format';
import type {
    CursorPaginated,
    NetworkSummary,
    Server,
    ServerUser,
    ServerLog,
    ServerLogWithOutput,
} from '@/types';

type Props = {
    server: { data: Server };
    networks: NetworkSummary[];
    users: ServerUser[];
    logs: CursorPaginated<ServerLog>;
    log: ServerLogWithOutput | null;
    latestLogId: string | null;
};

const tabs: TabSpec[] = [
    { value: 'overview', label: 'Overview', icon: LayoutDashboard },
    { value: 'console', label: 'Console', icon: SquareTerminal },
    { value: 'users', label: 'Users', icon: Users },
    { value: 'activity', label: 'Activity', icon: History },
];

function Detail({
    icon: Icon,
    label,
    value = '',
    copy,
    children,
}: {
    icon: LucideIcon;
    label: string;
    value?: string;
    copy?: boolean;
    children?: React.ReactNode;
}) {
    return (
        <div className="flex items-center gap-3 py-3">
            <Icon className="size-4 shrink-0 text-muted-foreground" />
            <span className="w-28 shrink-0 text-sm text-muted-foreground">
                {label}
            </span>
            {children ?? (
                <span className="min-w-0 flex-1 truncate font-mono text-sm">
                    {value}
                </span>
            )}
            {copy && value !== '—' && <CopyButton value={value} />}
        </div>
    );
}

function Resource({
    icon: Icon,
    label,
    value,
    className,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    className: string;
}) {
    return (
        <div className="flex items-center gap-3 rounded-xl border bg-card p-4">
            <span
                className={`flex size-10 items-center justify-center rounded-lg ${className}`}
            >
                <Icon className="size-5" />
            </span>
            <div>
                <div className="text-xl font-semibold tabular-nums">
                    {value}
                </div>
                <div className="text-xs text-muted-foreground">{label}</div>
            </div>
        </div>
    );
}

function NetworkCard({
    server,
    networks,
}: {
    server: Server;
    networks: NetworkSummary[];
}) {
    const [editing, setEditing] = useState(false);
    const locked = Boolean(server.database);

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div className="space-y-1.5">
                    <CardTitle className="flex items-center gap-2">
                        <Network className="size-4 text-muted-foreground" />
                        Private network
                    </CardTitle>
                    <CardDescription>
                        {locked
                            ? 'Fixed while the server is in a database.'
                            : 'Standby and read-only nodes must share it.'}
                    </CardDescription>
                </div>
                {!editing && !locked && (
                    <Button
                        variant="outline"
                        size="sm"
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        Edit
                    </Button>
                )}
            </CardHeader>
            <CardContent>
                {editing ? (
                    <Form
                        {...ServerNetworkController.update.form({
                            server: server.id,
                        })}
                        options={{ preserveScroll: true }}
                        onSuccess={() => {
                            toast.success('Private network saved');
                            setEditing(false);
                        }}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <PrivateNetworkFields
                                    networks={networks}
                                    network={server.network}
                                    privateIp={server.private_ip}
                                    errors={errors}
                                />
                                <div className="flex justify-end gap-2">
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        onClick={() => setEditing(false)}
                                    >
                                        Cancel
                                    </Button>
                                    <Button disabled={processing}>Save</Button>
                                </div>
                            </>
                        )}
                    </Form>
                ) : server.network ? (
                    <div className="space-y-1 text-sm">
                        <div className="flex items-center gap-2 font-medium">
                            {server.network.name}
                            {server.network_status && (
                                <StatusBadge status={server.network_status} />
                            )}
                        </div>
                        <div className="font-mono text-muted-foreground">
                            {server.private_ip} in {server.network.cidr}
                        </div>
                        {server.network_issue && (
                            <div className="text-muted-foreground">
                                {server.network_issue}
                            </div>
                        )}
                        {server.network_checked_at && (
                            <div className="text-xs text-muted-foreground">
                                Checked {timeAgo(server.network_checked_at)}
                            </div>
                        )}
                    </div>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        None. This server can only run single-node databases.
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function OverviewTab({
    server,
    networks,
}: {
    server: Server;
    networks: NetworkSummary[];
}) {
    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <Resource
                    icon={Cpu}
                    label="CPU"
                    value={server.cpus ? `${server.cpus} vCPU` : '—'}
                    className="bg-muted text-muted-foreground"
                />
                <Resource
                    icon={MemoryStick}
                    label="Memory"
                    value={formatMemory(server.memory_mb)}
                    className="bg-muted text-muted-foreground"
                />
                <Resource
                    icon={HardDrive}
                    label="Disk"
                    value={server.disk_gb ? `${server.disk_gb} GB` : '—'}
                    className="bg-muted text-muted-foreground"
                />
            </div>

            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_22rem]">
                <Card className="gap-2">
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <ServerIcon className="size-4 text-muted-foreground" />
                            Details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="divide-y">
                        <Detail icon={Cloud} label="Provider">
                            <span className="flex min-w-0 flex-1 items-center justify-between gap-3">
                                <ServerSource server={server} />
                                {server.plan && (
                                    <span className="truncate font-mono text-xs text-muted-foreground">
                                        {server.plan}
                                    </span>
                                )}
                            </span>
                        </Detail>
                        <Detail
                            icon={Globe}
                            label="Public IP"
                            value={server.public_ip ?? 'Not known yet'}
                            copy
                        />
                        <Detail
                            icon={Network}
                            label="Private IP"
                            value={server.private_ip ?? '—'}
                            copy
                        />
                        <Detail
                            icon={SquareTerminal}
                            label="SSH"
                            value={
                                server.public_ip
                                    ? `ssh -p ${server.ssh_port} ${server.ssh_user}@${server.public_ip}`
                                    : '—'
                            }
                            copy
                        />
                        <Detail
                            icon={MonitorCog}
                            label="System"
                            value={server.os ?? '—'}
                        />
                        <Detail
                            icon={Fingerprint}
                            label="Host key"
                            value={server.host_key_fingerprint ?? '—'}
                            copy
                        />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <DatabaseZap className="size-4 text-muted-foreground" />
                            Database
                        </CardTitle>
                        <CardDescription>
                            {server.database
                                ? 'This server runs a node of'
                                : 'Not part of any database.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {server.database ? (
                            <ServerDatabase server={server} />
                        ) : server.status === 'ready' ? (
                            <Button
                                asChild
                                variant="outline"
                                className="w-full"
                            >
                                <Link href={DatabaseController.create()}>
                                    <Plus />
                                    Create a database here
                                </Link>
                            </Button>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Available once setup finishes.
                            </p>
                        )}
                    </CardContent>
                </Card>
            </div>

            <NetworkCard server={server} networks={networks} />
        </div>
    );
}

function ConsoleTab({ server }: { server: Server }) {
    if (server.status !== 'ready') {
        return (
            <EmptyState
                icon={SquareTerminal}
                title="Terminal"
                description="A root shell opens here once the server is online."
            />
        );
    }

    return (
        <div className="space-y-2">
            <ServerTerminal server={server} />
            <p className="text-xs text-muted-foreground">
                A live root shell over SSH. It closes after 15 minutes without
                typing or when you leave the page.
            </p>
        </div>
    );
}

function AddUserDialog({ server }: { server: Server }) {
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" disabled={server.status !== 'ready'}>
                    <Plus />
                    Add user
                </Button>
            </DialogTrigger>
            <DialogContent>
                <Form
                    {...ServerUserController.store.form({ server: server.id })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        toast.success('Creating user');
                        setOpen(false);
                    }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>Add a Linux user</DialogTitle>
                                <DialogDescription>
                                    An isolated user with its own home directory
                                    and no sudo access.
                                </DialogDescription>
                            </DialogHeader>
                            <div className="space-y-1.5">
                                <Label htmlFor="username">Username</Label>
                                <Input
                                    id="username"
                                    name="username"
                                    placeholder="deploy"
                                    autoComplete="off"
                                />
                                <InputError message={errors.username} />
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor="public_key">
                                    SSH public key (optional)
                                </Label>
                                <Textarea
                                    id="public_key"
                                    name="public_key"
                                    rows={3}
                                    placeholder="ssh-ed25519 AAAA… you@laptop"
                                    className="font-mono text-xs"
                                />
                                <InputError
                                    message={errors.public_key ?? errors.server}
                                />
                            </div>
                            <DialogFooter>
                                <Button disabled={processing}>
                                    Create user
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

function UsersTab({ server, users }: { server: Server; users: ServerUser[] }) {
    return (
        <Card className="gap-4 pb-2">
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div className="space-y-1.5">
                    <CardTitle className="flex items-center gap-2">
                        <UserRound className="size-4 text-muted-foreground" />
                        Linux users
                    </CardTitle>
                    <CardDescription>
                        Give people or deploy tools their own login on this
                        server.
                    </CardDescription>
                </div>
                <AddUserDialog server={server} />
            </CardHeader>
            {users.length === 0 ? (
                <p className="px-6 pb-4 text-sm text-muted-foreground">
                    No extra users yet.
                </p>
            ) : (
                <Table>
                    <TableHeader>
                        <TableRow className="hover:bg-transparent">
                            <TableHead className="pl-6">Username</TableHead>
                            <TableHead>Home</TableHead>
                            <TableHead className="pr-6" />
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {users.map((user) => (
                            <TableRow key={user.id}>
                                <TableCell className="pl-6">
                                    <div className="flex items-center gap-3">
                                        <span className="flex size-8 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary uppercase">
                                            {user.username.slice(0, 2)}
                                        </span>
                                        <span className="font-mono">
                                            {user.username}
                                        </span>
                                    </div>
                                </TableCell>
                                <TableCell className="font-mono text-xs text-muted-foreground">
                                    /home/{user.username}
                                </TableCell>
                                <TableCell className="pr-6 text-right">
                                    <ConfirmDialog
                                        trigger={
                                            <DeleteButton
                                                aria-label={`Delete ${user.username}`}
                                            />
                                        }
                                        title={`Delete ${user.username}?`}
                                        description="The user and its home directory are removed from the server."
                                        confirmLabel="Delete user"
                                        destructive
                                        onConfirm={() =>
                                            runAction(
                                                ServerUserController.destroy({
                                                    server: server.id,
                                                    user: user.id,
                                                }),
                                                {
                                                    success: `Deleting ${user.username}`,
                                                },
                                            )
                                        }
                                    />
                                </TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            )}
        </Card>
    );
}

export default function ShowServer({
    server: { data: server },
    networks,
    users,
    logs,
    log,
    latestLogId,
}: Props) {
    const [tab, navigate] = useQueryTab(
        tabs.map((entry) => entry.value),
        'overview',
    );
    const busy =
        server.status === 'provisioning' ||
        logs.data.some((log) => log.status === 'running');

    usePoll(3000, () => ({
        only: ['server', 'users', ...liveLogProps(logs, log)],
    }));

    return (
        <>
            <Head title={server.name} />
            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <div className="relative">
                            <BrandIcon
                                group="os"
                                src={server.distribution_icon}
                                size="lg"
                            />
                            <StatusDot
                                status={server.status}
                                className="absolute -right-1 -bottom-1 rounded-full ring-2 ring-background"
                            />
                        </div>
                        <div className="space-y-1">
                            <div className="flex flex-wrap items-center gap-3">
                                <h1 className="text-2xl font-semibold tracking-tight">
                                    {server.name}
                                </h1>
                                <StatusBadge status={server.status} />
                            </div>
                            <p className="font-mono text-sm text-muted-foreground">
                                {server.public_ip ??
                                    (server.provider &&
                                        `${server.provider.type.label} · ${server.region}`)}
                                {server.private_ip && ` · ${server.private_ip}`}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {[
                                    server.os,
                                    server.cpus && `${server.cpus} vCPU`,
                                    server.memory_mb &&
                                        `${formatMemory(server.memory_mb)} RAM`,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') ||
                                    'Details appear after setup'}
                            </p>
                        </div>
                    </div>
                    <ServerActions server={server} busy={busy} />
                </div>

                {server.status === 'failed' && (
                    <div className="flex items-start gap-3 rounded-xl border border-red-500/30 bg-red-500/5 px-4 py-3 text-sm">
                        <KeyRound className="mt-0.5 size-4 shrink-0 text-red-500" />
                        <div>
                            <div className="font-medium">Setup failed</div>
                            <div className="text-muted-foreground">
                                Check the log in Activity, fix the cause (often
                                SSH access), then retry setup.
                            </div>
                        </div>
                    </div>
                )}

                <Tabs value={tab} onValueChange={navigate} className="gap-6">
                    <TabNav tabs={tabs} />
                    <TabsContent value="overview">
                        <OverviewTab server={server} networks={networks} />
                    </TabsContent>
                    <TabsContent value="console">
                        <ConsoleTab server={server} />
                    </TabsContent>
                    <TabsContent value="users">
                        <UsersTab server={server} users={users} />
                    </TabsContent>
                    <TabsContent value="activity">
                        <ServerLogList
                            logs={logs}
                            log={log}
                            latestLogId={latestLogId}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

ShowServer.layout = (props: Props) => ({
    breadcrumbs: [
        { title: 'Servers', href: ServerController.index() },
        {
            title: props.server.data.name,
            href: ServerController.show({ server: props.server.data.id }),
        },
    ],
});
