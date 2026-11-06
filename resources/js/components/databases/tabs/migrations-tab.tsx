import {
    ArrowRight,
    CheckCircle2,
    CircleAlert,
    CircleCheck,
    CircleX,
    Copy as CopyIcon,
    DatabaseZap,
    Flag,
    Loader2,
    Radar,
    RefreshCcw,
    Square,
    Wrench,
    Zap,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useState } from 'react';
import DatabaseMigrationController from '@/actions/App/Http/Controllers/Databases/DatabaseMigrationController';
import ConfirmDialog from '@/components/confirm-dialog';
import CopyButton from '@/components/copy-button';
import StatusBadge from '@/components/status-badge';
import { runAction } from '@/lib/run-action';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { Switch } from '@/components/ui/switch';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { HttpError, postJson } from '@/lib/http';
import { timeAgo } from '@/lib/format';
import { cn } from '@/lib/utils';
import type {
    Database,
    DatabaseMigration,
    MigrationSourceForm,
    SourceReport,
} from '@/types';

type Mode = DatabaseMigration['mode'];

type Source = {
    host: string;
    port: string;
    username: string;
    password: string;
    tls: boolean;
    databases: string;
};

const activeStatuses = ['copying', 'syncing'];

function payloadOf(source: Source, form: MigrationSourceForm) {
    return {
        host: source.host,
        port: Number(source.port),
        username: source.username || null,
        password: source.password || null,
        tls: source.tls,
        ...(form.selects_databases
            ? {
                  databases: source.databases
                      .split(/[\s,]+/)
                      .filter((name) => name !== ''),
              }
            : {}),
    };
}

function Steps({ migration }: { migration: DatabaseMigration }) {
    const steps =
        migration.mode === 'continuous'
            ? ['Copy data', 'Keep in sync', 'Cut over']
            : ['Copy data', 'Done'];
    const reached = migration.status === 'copying' ? 0 : 1;

    return (
        <ol className="flex flex-wrap items-center gap-2">
            {steps.map((step, index) => (
                <li key={step} className="flex items-center gap-2">
                    <span
                        className={cn(
                            'flex items-center gap-2 rounded-full border px-3 py-1 text-sm',
                            index < reached &&
                                'border-green-500/40 bg-green-500/10 text-green-700 dark:text-green-400',
                            index === reached &&
                                'border-primary bg-primary/10 font-medium text-primary',
                            index > reached && 'text-muted-foreground',
                        )}
                    >
                        {index < reached ? (
                            <CheckCircle2 className="size-3.5" />
                        ) : index === reached ? (
                            <Loader2 className="size-3.5 animate-spin" />
                        ) : (
                            <span className="size-3.5 text-center text-xs">
                                {index + 1}
                            </span>
                        )}
                        {step}
                    </span>
                    {index < steps.length - 1 && (
                        <ArrowRight className="size-4 text-muted-foreground" />
                    )}
                </li>
            ))}
        </ol>
    );
}

function ActiveMigration({
    database,
    migration,
}: {
    database: Database;
    migration: DatabaseMigration;
}) {
    const [force, setForce] = useState(false);
    const route = { database: database.id, migration: migration.id };
    const syncing = migration.status === 'syncing';

    return (
        <Card className="border-primary/40">
            <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-4">
                <div className="space-y-1.5">
                    <CardTitle className="flex items-center gap-2">
                        <RefreshCcw className="size-4 text-primary" />
                        Migrating from {migration.source}
                    </CardTitle>
                    <CardDescription>
                        {migration.mode === 'continuous'
                            ? 'Continuous migration. Writes to the source keep flowing here until you cut over.'
                            : 'One-time copy of the current data.'}
                    </CardDescription>
                </div>
                <div className="flex gap-2">
                    {migration.mode === 'continuous' && (
                        <ConfirmDialog
                            trigger={
                                <Button disabled={!syncing}>
                                    <Flag />
                                    Finish migration
                                </Button>
                            }
                            title="Cut over to this database?"
                            description={
                                <>
                                    <p>
                                        Replication from {migration.source}{' '}
                                        stops and {database.name} becomes the
                                        source of truth.
                                    </p>
                                    <p>
                                        Stop writes on the old database first,
                                        and wait for the lag to reach 0 seconds.
                                    </p>
                                </>
                            }
                            confirmLabel="Finish migration"
                            onConfirm={() =>
                                runAction(
                                    DatabaseMigrationController.finish(route),
                                    {
                                        success: 'Finishing migration',
                                    },
                                )
                            }
                        />
                    )}
                    <ConfirmDialog
                        trigger={
                            <Button
                                variant="outline"
                                className="text-destructive hover:text-destructive"
                            >
                                <Square />
                                Stop
                            </Button>
                        }
                        title="Stop this migration?"
                        description="Copying or syncing stops. Data already copied stays in the database."
                        confirmLabel="Stop migration"
                        destructive
                        onConfirm={() =>
                            runAction(
                                DatabaseMigrationController.destroy(route),
                                {
                                    data: { force },
                                    success: 'Stopping migration',
                                },
                            )
                        }
                    >
                        <label className="flex items-center gap-2 text-sm">
                            <Switch
                                checked={force}
                                onCheckedChange={setForce}
                            />
                            Force stop even if the source is unreachable
                        </label>
                    </ConfirmDialog>
                </div>
            </CardHeader>
            <CardContent className="space-y-5">
                <Steps migration={migration} />
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                    <div className="rounded-lg border p-4">
                        <div className="text-xs text-muted-foreground">
                            Status
                        </div>
                        <div className="mt-1">
                            <StatusBadge status={migration.status} />
                        </div>
                    </div>
                    <div className="rounded-lg border p-4">
                        <div className="text-xs text-muted-foreground">
                            Replication lag
                        </div>
                        <div
                            className={cn(
                                'mt-1 text-2xl font-semibold tabular-nums',
                                migration.lag_seconds === 0 && 'text-green-600',
                            )}
                        >
                            {migration.lag_seconds === null
                                ? '—'
                                : `${migration.lag_seconds}s`}
                        </div>
                    </div>
                    <div className="rounded-lg border p-4">
                        <div className="text-xs text-muted-foreground">
                            Started
                        </div>
                        <div className="mt-1 text-sm font-medium">
                            {timeAgo(migration.started_at)}
                        </div>
                    </div>
                </div>
                {migration.detail && (
                    <p className="rounded-lg bg-muted/50 px-4 py-3 font-mono text-xs">
                        {migration.detail}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

function CleanUpAgain({
    database,
    migration,
}: {
    database: Database;
    migration: DatabaseMigration;
}) {
    return (
        <ConfirmDialog
            trigger={
                <Button size="sm" variant="outline">
                    <Wrench />
                    Clean up again
                </Button>
            }
            title="Clean up this migration again?"
            description={`The last cleanup on the server failed, so the copy or replication from ${migration.source} may still be running. Stopping it again is safe.`}
            confirmLabel="Clean up again"
            onConfirm={() =>
                runAction(
                    DatabaseMigrationController.destroy({
                        database: database.id,
                        migration: migration.id,
                    }),
                    {
                        data: { force: true },
                        success: 'Cleaning up migration',
                    },
                )
            }
        />
    );
}

function Report({ report }: { report: SourceReport }) {
    const facts = Object.entries(report.facts);
    const notes = [...report.problems, ...report.blockers, ...report.warnings];

    return (
        <div className="space-y-4 rounded-xl border p-4">
            <div className="flex items-center gap-2 font-medium">
                {report.reachable &&
                report.problems.length === 0 &&
                report.blockers.length === 0 ? (
                    <CircleCheck className="size-5 text-green-500" />
                ) : report.reachable && report.problems.length === 0 ? (
                    <CircleAlert className="size-5 text-amber-500" />
                ) : (
                    <CircleX className="size-5 text-red-500" />
                )}
                {!report.reachable
                    ? 'Could not connect to the source'
                    : report.problems.length > 0
                      ? 'Source needs changes first'
                      : report.blockers.length > 0
                        ? 'Source can be copied once, not kept in sync'
                        : 'Source is ready to migrate'}
            </div>
            {report.error && (
                <p className="rounded-lg bg-red-500/5 px-3 py-2 font-mono text-xs text-red-600 dark:text-red-400">
                    {report.error}
                </p>
            )}
            {facts.length > 0 && (
                <dl className="grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    {facts.map(([key, value]) => (
                        <div
                            key={key}
                            className="flex justify-between gap-4 border-b py-1.5"
                        >
                            <dt className="text-muted-foreground capitalize">
                                {key.replaceAll('_', ' ')}
                            </dt>
                            <dd className="truncate font-mono text-xs">
                                {value}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
            {notes.length > 0 && (
                <ul className="space-y-2">
                    {notes.map((note) => (
                        <li
                            key={note}
                            className="flex items-start gap-2 text-sm text-amber-700 dark:text-amber-400"
                        >
                            <CircleAlert className="mt-0.5 size-4 shrink-0" />
                            {note}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function ModeOption({
    value,
    title,
    description,
    icon,
    disabled,
}: {
    value: Mode;
    title: string;
    description: string;
    icon: ReactNode;
    disabled?: boolean;
}) {
    return (
        <label
            className={cn(
                'flex cursor-pointer items-start gap-3 rounded-xl border p-4 transition has-[[data-state=checked]]:border-primary has-[[data-state=checked]]:bg-primary/5',
                disabled && 'cursor-not-allowed opacity-50',
            )}
        >
            <RadioGroupItem
                value={value}
                disabled={disabled}
                className="mt-1"
            />
            <div className="space-y-1">
                <div className="flex items-center gap-2 font-medium">
                    {icon}
                    {title}
                </div>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
        </label>
    );
}

function SourceForm({ database, busy }: { database: Database; busy: boolean }) {
    const form = database.migration_source;
    const optional = form.credentials_required ? '' : ' (optional)';
    const [source, setSource] = useState<Source>({
        host: '',
        port: String(form.default_port),
        username: '',
        password: '',
        tls: true,
        databases: '',
    });
    const [report, setReport] = useState<SourceReport | null>(null);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [checking, setChecking] = useState(false);
    const [mode, setMode] = useState<Mode>('continuous');
    const [starting, setStarting] = useState(false);

    const update = (patch: Partial<Source>) => {
        setSource((current) => ({ ...current, ...patch }));
        setReport(null);
    };

    const check = () => {
        setChecking(true);
        setErrors({});
        postJson<SourceReport>(
            DatabaseMigrationController.check.url(database.id),
            payloadOf(source, form),
        )
            .then((result) => {
                setReport(result);
                setMode(result.continuous ? 'continuous' : 'one_time');
            })
            .catch((reason: Error) =>
                setErrors(
                    reason instanceof HttpError
                        ? reason.errors
                        : { host: [reason.message] },
                ),
            )
            .finally(() => setChecking(false));
    };

    const start = () => {
        setStarting(true);
        runAction(DatabaseMigrationController.store(database.id), {
            data: { ...payloadOf(source, form), mode },
            success: 'Migration started',
            onFinish: () => setStarting(false),
        });
    };

    const ready = report?.reachable === true && report.problems.length === 0;

    return (
        <div className="space-y-6">
            <div className="grid grid-cols-1 gap-4 md:grid-cols-[1fr_8rem]">
                <div className="space-y-1.5">
                    <Label htmlFor="source-host">Host</Label>
                    <Input
                        id="source-host"
                        value={source.host}
                        onChange={(event) =>
                            update({ host: event.target.value })
                        }
                        placeholder="db.example.com"
                    />
                    <InputError message={errors.host?.[0]} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="source-port">Port</Label>
                    <Input
                        id="source-port"
                        type="number"
                        value={source.port}
                        onChange={(event) =>
                            update({ port: event.target.value })
                        }
                    />
                    <InputError message={errors.port?.[0]} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="source-username">Username{optional}</Label>
                    <Input
                        id="source-username"
                        value={source.username}
                        onChange={(event) =>
                            update({ username: event.target.value })
                        }
                        autoComplete="off"
                    />
                    <InputError message={errors.username?.[0]} />
                </div>
                <div className="space-y-1.5 md:col-span-2 md:col-start-1">
                    <Label htmlFor="source-password">Password{optional}</Label>
                    <Input
                        id="source-password"
                        type="password"
                        value={source.password}
                        onChange={(event) =>
                            update({ password: event.target.value })
                        }
                        autoComplete="new-password"
                    />
                    <InputError message={errors.password?.[0]} />
                </div>
                {form.selects_databases && (
                    <div className="space-y-1.5 md:col-span-2">
                        <Label htmlFor="source-databases">
                            Databases (optional)
                        </Label>
                        <Input
                            id="source-databases"
                            value={source.databases}
                            onChange={(event) =>
                                update({ databases: event.target.value })
                            }
                            placeholder="Leave empty to migrate every database"
                        />
                        <InputError
                            message={
                                errors.databases?.[0] ??
                                Object.entries(errors).find(([key]) =>
                                    key.startsWith('databases.'),
                                )?.[1][0]
                            }
                        />
                    </div>
                )}
                <label className="flex items-center gap-2 text-sm md:col-span-2">
                    <Switch
                        checked={source.tls}
                        onCheckedChange={(tls) => update({ tls })}
                    />
                    Connect to the source over TLS
                </label>
            </div>

            <div className="flex justify-end">
                <Button
                    variant={report ? 'outline' : 'default'}
                    onClick={check}
                    disabled={checking || !source.host || !source.port}
                >
                    {checking ? (
                        <Loader2 className="animate-spin" />
                    ) : (
                        <Radar />
                    )}
                    {report ? 'Check again' : 'Check source'}
                </Button>
            </div>

            {report && <Report report={report} />}

            {ready && (
                <div className="space-y-4">
                    <RadioGroup
                        value={mode}
                        onValueChange={(value) => setMode(value as Mode)}
                        className="grid grid-cols-1 gap-3 md:grid-cols-2"
                    >
                        <ModeOption
                            value="continuous"
                            title="Continuous"
                            icon={
                                <RefreshCcw className="size-4 text-primary" />
                            }
                            description="Copy the data, then keep replicating changes until you cut over. Near-zero downtime."
                            disabled={!report.continuous}
                        />
                        <ModeOption
                            value="one_time"
                            title="One-time copy"
                            icon={<CopyIcon className="size-4 text-primary" />}
                            description="Copy the data as it is now. Writes made to the source afterwards are not carried over."
                        />
                    </RadioGroup>
                    <div className="flex justify-end">
                        <Button onClick={start} disabled={starting || busy}>
                            <Zap />
                            Start migration
                        </Button>
                    </div>
                </div>
            )}
        </div>
    );
}

export default function MigrationsTab({
    database,
    busy,
}: {
    database: Database;
    busy: boolean;
}) {
    const active = database.migrations.find((migration) =>
        activeStatuses.includes(migration.status),
    );
    const past = database.migrations.filter(
        (migration) => migration !== active,
    );

    return (
        <div className="space-y-6">
            {active ? (
                <ActiveMigration database={database} migration={active} />
            ) : (
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2">
                            <DatabaseZap className="size-4 text-muted-foreground" />
                            Migrate an existing database
                        </CardTitle>
                        <CardDescription>
                            Move data from any reachable {database.engine_label}{' '}
                            server into {database.name}. The source is checked
                            before anything is copied.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <SourceForm database={database} busy={busy} />
                    </CardContent>
                </Card>
            )}

            {database.outbound_ips.length > 0 && (
                <div className="flex flex-wrap items-center gap-3 rounded-xl border bg-muted/30 px-4 py-3 text-sm">
                    <span className="text-muted-foreground">
                        Allow these IPs on the source's firewall:
                    </span>
                    {database.outbound_ips.map((ip) => (
                        <Badge
                            key={ip}
                            variant="outline"
                            className="gap-1 font-mono"
                        >
                            {ip}
                        </Badge>
                    ))}
                    <span className="ml-auto">
                        <CopyButton
                            value={database.outbound_ips.join('\n')}
                            label="Copy IPs"
                        />
                    </span>
                </div>
            )}

            {past.length > 0 && (
                <Card className="gap-4 pb-2">
                    <CardHeader>
                        <CardTitle>History</CardTitle>
                    </CardHeader>
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-6">Source</TableHead>
                                <TableHead>Mode</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Finished</TableHead>
                                <TableHead className="pr-6" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {past.map((migration) => (
                                <TableRow key={migration.id}>
                                    <TableCell className="pl-6 font-mono text-xs">
                                        {migration.source}
                                    </TableCell>
                                    <TableCell>
                                        {migration.mode === 'continuous'
                                            ? 'Continuous'
                                            : 'One-time'}
                                    </TableCell>
                                    <TableCell
                                        title={migration.detail ?? undefined}
                                    >
                                        <StatusBadge
                                            status={migration.status}
                                        />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {timeAgo(migration.finished_at)}
                                    </TableCell>
                                    <TableCell className="pr-6 text-right">
                                        {migration.cleanup_failed && (
                                            <CleanUpAgain
                                                database={database}
                                                migration={migration}
                                            />
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </div>
    );
}
