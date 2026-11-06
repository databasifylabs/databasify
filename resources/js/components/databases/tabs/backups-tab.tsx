import { Link } from '@inertiajs/react';
import {
    Archive,
    CalendarClock,
    CloudUpload,
    HardDrive,
    History,
    Pencil,
    Plus,
    PowerOff,
    RefreshCw,
    RotateCcw,
    TriangleAlert,
} from 'lucide-react';
import { useState } from 'react';
import DatabaseRecoveryController from '@/actions/App/Http/Controllers/Databases/DatabaseRecoveryController';
import StorageProviderController from '@/actions/App/Http/Controllers/Storage/StorageProviderController';
import ConfirmDialog from '@/components/confirm-dialog';
import Field from '@/components/form/field';
import StatusBadge from '@/components/status-badge';
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
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { hourLabel, hoursOfDay, timeAgo } from '@/lib/format';
import { runAction } from '@/lib/run-action';
import type {
    Database,
    PointInTimeRecovery,
    Server,
    StorageProviderOption,
} from '@/types';

function moment(iso: string | null): string {
    return iso === null ? 'Not yet' : new Date(iso).toLocaleString();
}

function toLocalInput(iso: string): string {
    const date = new Date(iso);
    const offset = date.getTimezoneOffset() * 60000;

    return new Date(date.getTime() - offset).toISOString().slice(0, 19);
}

function ScheduleFields({
    retention,
    onRetention,
    hour,
    onHour,
}: {
    retention: string;
    onRetention: (value: string) => void;
    hour: string;
    onHour: (value: string) => void;
}) {
    return (
        <>
            <Field
                id="retention_days"
                label="Keep restore points for (days)"
                hint="Older base backups and logs are removed after each base backup."
            >
                <Input
                    id="retention_days"
                    type="number"
                    min={1}
                    max={35}
                    value={retention}
                    onChange={(event) => onRetention(event.target.value)}
                />
            </Field>
            <Field
                id="backup_hour"
                label="Daily base backup"
                hint="Taken on the current primary."
            >
                <Select value={hour} onValueChange={onHour}>
                    <SelectTrigger id="backup_hour" className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {hoursOfDay.map((entry) => (
                            <SelectItem key={entry} value={String(entry)}>
                                {hourLabel(entry)} UTC
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
        </>
    );
}

function EnableForm({
    database,
    storageProviders,
    busy,
}: {
    database: Database;
    storageProviders: StorageProviderOption[];
    busy: boolean;
}) {
    const [storage, setStorage] = useState(
        String(storageProviders[0]?.id ?? ''),
    );
    const [retention, setRetention] = useState('7');
    const [hour, setHour] = useState(String(database.maintenance.hour));
    const [saving, setSaving] = useState(false);

    if (storageProviders.length === 0) {
        return (
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                <span>
                    Add an S3-compatible bucket once, then pick it here.
                </span>
                <Button asChild size="sm">
                    <Link href={StorageProviderController.create()}>
                        <Plus /> Add storage
                    </Link>
                </Button>
            </div>
        );
    }

    const enable = () => {
        setSaving(true);
        runAction(DatabaseRecoveryController.store(database.id), {
            data: {
                storage_provider_id: storage,
                retention_days: Number(retention),
                backup_hour: Number(hour),
            },
            success: 'Turning on point-in-time recovery',
            onFinish: () => setSaving(false),
        });
    };

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <Field
                    id="storage_provider_id"
                    label="Storage"
                    hint="Manage buckets under Storage."
                >
                    <Select value={storage} onValueChange={setStorage}>
                        <SelectTrigger
                            id="storage_provider_id"
                            className="w-full"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {storageProviders.map((provider) => (
                                <SelectItem
                                    key={provider.id}
                                    value={provider.id}
                                >
                                    {provider.name}
                                    <span className="text-muted-foreground">
                                        {provider.bucket}
                                    </span>
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <ScheduleFields
                    retention={retention}
                    onRetention={setRetention}
                    hour={hour}
                    onHour={setHour}
                />
            </div>
            <p className="text-sm text-muted-foreground">
                Every node is redeployed one at a time to start archiving.
                Single-node databases restart once.
            </p>
            <div className="flex justify-end">
                <Button onClick={enable} disabled={busy || saving}>
                    <CloudUpload /> Turn on point-in-time recovery
                </Button>
            </div>
        </div>
    );
}

function ScheduleDialog({
    database,
    recovery,
    busy,
}: {
    database: Database;
    recovery: PointInTimeRecovery;
    busy: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [retention, setRetention] = useState(String(recovery.retention_days));
    const [hour, setHour] = useState(String(recovery.backup_hour));
    const [saving, setSaving] = useState(false);

    const save = () => {
        setSaving(true);
        runAction(DatabaseRecoveryController.update(database.id), {
            data: {
                retention_days: Number(retention),
                backup_hour: Number(hour),
            },
            success: 'Saving the schedule',
            onSuccess: () => setOpen(false),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" disabled={busy}>
                    <Pencil /> Schedule
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Backup schedule</DialogTitle>
                    <DialogDescription>
                        Applies without a restart.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <ScheduleFields
                        retention={retention}
                        onRetention={setRetention}
                        hour={hour}
                        onHour={setHour}
                    />
                </div>
                <DialogFooter>
                    <Button onClick={save} disabled={saving}>
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Fact({
    icon: Icon,
    label,
    value,
    detail,
}: {
    icon: typeof Archive;
    label: string;
    value: string;
    detail?: string;
}) {
    return (
        <div className="flex items-start gap-3 rounded-lg border bg-muted/20 p-3">
            <Icon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <div className="min-w-0">
                <div className="text-xs text-muted-foreground">{label}</div>
                <div className="truncate text-sm font-medium">{value}</div>
                {detail && (
                    <div className="truncate text-xs text-muted-foreground">
                        {detail}
                    </div>
                )}
            </div>
        </div>
    );
}

function TurnOffDialog({
    database,
    recovery,
    busy,
}: {
    database: Database;
    recovery: PointInTimeRecovery;
    busy: boolean;
}) {
    const [deleteBackups, setDeleteBackups] = useState(false);

    return (
        <ConfirmDialog
            trigger={
                <Button variant="ghost" size="sm" disabled={busy}>
                    <PowerOff /> Turn off
                </Button>
            }
            title="Turn off point-in-time recovery?"
            description={
                deleteBackups
                    ? `Every node stops archiving and is redeployed one at a time. Then every base backup and log of ${database.name} is deleted from ${recovery.storage.bucket}.`
                    : `Every node stops archiving and is redeployed one at a time. Base backups and logs already in ${recovery.storage.bucket} stay there.`
            }
            confirmLabel="Turn off"
            destructive
            onConfirm={() =>
                runAction(DatabaseRecoveryController.destroy(database.id), {
                    data: { delete_backups: deleteBackups },
                    success: 'Turning off point-in-time recovery',
                })
            }
        >
            <label className="flex items-start gap-3 rounded-lg border p-3 text-sm">
                <Switch
                    checked={deleteBackups}
                    onCheckedChange={setDeleteBackups}
                />
                <span>
                    <span className="font-medium">
                        Also delete backups from the bucket
                    </span>
                    <span className="block text-muted-foreground">
                        Removes this database's archive from{' '}
                        {recovery.storage.bucket} once archiving has stopped.
                        This cannot be undone.
                    </span>
                </span>
            </label>
        </ConfirmDialog>
    );
}

function ArchiveStatus({
    database,
    recovery,
    busy,
}: {
    database: Database;
    recovery: PointInTimeRecovery;
    busy: boolean;
}) {
    const window =
        recovery.earliest_restorable_at && recovery.latest_restorable_at
            ? `${moment(recovery.earliest_restorable_at)} → ${moment(recovery.latest_restorable_at)}`
            : 'Not restorable yet';

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
                <div className="space-y-1.5">
                    <CardTitle className="flex items-center gap-2">
                        Point-in-time recovery
                        <StatusBadge status={recovery.health} />
                    </CardTitle>
                    <CardDescription>
                        {recovery.health_label}
                        {recovery.checked_at &&
                            ` · checked ${timeAgo(recovery.checked_at)}`}
                    </CardDescription>
                </div>
                <div className="flex flex-wrap items-center gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            runAction(
                                DatabaseRecoveryController.check(database.id),
                                { success: 'Checking the archive' },
                            )
                        }
                    >
                        <RefreshCw /> Check now
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={() =>
                            runAction(
                                DatabaseRecoveryController.backup(database.id),
                                { success: 'Starting a base backup' },
                            )
                        }
                    >
                        <Archive /> Back up now
                    </Button>
                    <ScheduleDialog
                        database={database}
                        recovery={recovery}
                        busy={busy}
                    />
                    <TurnOffDialog
                        database={database}
                        recovery={recovery}
                        busy={busy}
                    />
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                {recovery.problem && (
                    <div className="flex gap-2.5 rounded-lg border border-red-600/20 bg-red-50 p-3 text-sm text-red-700 dark:border-red-400/20 dark:bg-red-400/10 dark:text-red-400">
                        <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                        <p>{recovery.problem}</p>
                    </div>
                )}
                <div className="grid grid-cols-1 gap-3 md:grid-cols-2">
                    <Fact
                        icon={History}
                        label="Restorable window"
                        value={window}
                    />
                    <Fact
                        icon={Archive}
                        label="Last base backup"
                        value={moment(recovery.last_backup_at)}
                        detail={`${recovery.backups_count} kept`}
                    />
                    <Fact
                        icon={HardDrive}
                        label="Storage"
                        value={recovery.storage.name}
                        detail={`${recovery.storage.bucket}/${recovery.path}`}
                    />
                    <Fact
                        icon={CalendarClock}
                        label="Schedule"
                        value={`Daily at ${hourLabel(recovery.backup_hour)} UTC`}
                        detail={`Keeps ${recovery.retention_days} days`}
                    />
                </div>
            </CardContent>
        </Card>
    );
}

function RestoreForm({
    database,
    recovery,
    servers,
}: {
    database: Database;
    recovery: PointInTimeRecovery;
    servers: Server[];
}) {
    const earliest = recovery.earliest_restorable_at;
    const latest = recovery.latest_restorable_at;
    const [target, setTarget] = useState(latest ? toLocalInput(latest) : '');
    const [name, setName] = useState(`${database.name}-restored`);
    const [server, setServer] = useState(String(servers[0]?.id ?? ''));
    const [busy, setBusy] = useState(false);

    if (!earliest || !latest) {
        return (
            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                The first base backup has not finished yet. Restores open up
                once it is in the archive.
            </p>
        );
    }

    const restore = () => {
        setBusy(true);
        runAction(DatabaseRecoveryController.restore(database.id), {
            data: {
                name,
                server_id: server,
                target: new Date(target).toISOString(),
            },
            success: `Restoring into ${name}`,
            onFinish: () => setBusy(false),
        });
    };

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-3">
                <Field
                    id="restore-target"
                    label="Restore to"
                    hint={`Your local time, between ${moment(earliest)} and ${moment(latest)}.`}
                >
                    <Input
                        id="restore-target"
                        type="datetime-local"
                        step={1}
                        min={toLocalInput(earliest)}
                        max={toLocalInput(latest)}
                        value={target}
                        onChange={(event) => setTarget(event.target.value)}
                    />
                </Field>
                <Field id="restore-name" label="New database name">
                    <Input
                        id="restore-name"
                        value={name}
                        onChange={(event) => setName(event.target.value)}
                    />
                </Field>
                <Field
                    id="restore-server"
                    label="Server"
                    hint="Restores onto one node. Add standbys afterwards."
                >
                    {servers.length === 0 ? (
                        <p className="rounded-md border border-dashed px-3 py-2 text-sm text-muted-foreground">
                            No free servers
                        </p>
                    ) : (
                        <Select value={server} onValueChange={setServer}>
                            <SelectTrigger
                                id="restore-server"
                                className="w-full"
                            >
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {servers.map((entry) => (
                                    <SelectItem key={entry.id} value={entry.id}>
                                        {entry.name}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </Field>
            </div>
            <div className="flex justify-end">
                <Button
                    onClick={restore}
                    disabled={busy || !name || !target || servers.length === 0}
                >
                    <RotateCcw /> Restore into a new database
                </Button>
            </div>
        </div>
    );
}

export default function BackupsTab({
    database,
    storageProviders,
    servers,
    busy,
}: {
    database: Database;
    storageProviders: StorageProviderOption[];
    servers: Server[];
    busy: boolean;
}) {
    const recovery = database.point_in_time_recovery;

    if (database.point_in_time_recovery_unavailable !== null) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Point-in-time recovery</CardTitle>
                    <CardDescription>
                        {database.point_in_time_recovery_unavailable}
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    return (
        <div className="space-y-6">
            {database.restored_from && (
                <div className="flex gap-2.5 rounded-lg border bg-muted/30 p-3 text-sm">
                    <RotateCcw className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                    <p>
                        Restored from{' '}
                        <span className="font-medium">
                            {database.restored_from.database}
                        </span>{' '}
                        as it was at {moment(database.restored_from.target)}.
                    </p>
                </div>
            )}

            {recovery === null ? (
                <Card>
                    <CardHeader>
                        <CardTitle>Point-in-time recovery</CardTitle>
                        <CardDescription>
                            Takes a daily base backup and archives every change
                            from the current primary, so you can restore a new
                            database to any second in the retention window.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <EnableForm
                            database={database}
                            storageProviders={storageProviders}
                            busy={busy}
                        />
                    </CardContent>
                </Card>
            ) : (
                <>
                    <ArchiveStatus
                        database={database}
                        recovery={recovery}
                        busy={busy}
                    />
                    <Card>
                        <CardHeader>
                            <CardTitle>Restore</CardTitle>
                            <CardDescription>
                                Creates a new database as {database.name} was at
                                the chosen moment. {database.name} keeps running
                                untouched.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <RestoreForm
                                database={database}
                                recovery={recovery}
                                servers={servers}
                            />
                        </CardContent>
                    </Card>
                </>
            )}
        </div>
    );
}
