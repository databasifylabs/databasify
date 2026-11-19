import { Form } from '@inertiajs/react';
import { useState } from 'react';
import FileBackupController from '@/actions/App/Http/Controllers/Storage/FileBackupController';
import { FormSection } from '@/components/form/choice';
import Field from '@/components/form/field';
import InputError from '@/components/input-error';
import ConfirmDialog from '@/components/confirm-dialog';
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
import { runAction } from '@/lib/run-action';

export type Destination = {
    id: string;
    name: string;
    database_id: string;
    database_name: string;
    protocol: string;
    host: string;
    port: number;
    username: string;
    path: string;
    host_key: string | null;
    enabled: boolean;
    backup_hour: number;
    verified_at: string | null;
    next_backup_at: string | null;
    runs: {
        id: string;
        status: string;
        remote_file: string | null;
        problem: string | null;
        started_at: string | null;
        finished_at: string | null;
    }[];
};
export type DatabaseOption = { id: string; name: string; engine: string };

export function FileBackupCard({
    destination,
    databases,
}: {
    destination: Destination;
    databases: DatabaseOption[];
}) {
    const [editing, setEditing] = useState(false);
    const active = destination.runs.some(
        (run) => run.status === 'queued' || run.status === 'running',
    );
    return (
        <section className="space-y-4 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="space-y-1">
                    <div className="flex items-center gap-2">
                        <h2 className="font-medium">{destination.name}</h2>
                        <span className="rounded-md border px-2 py-0.5 text-xs font-medium">
                            {destination.protocol.toUpperCase()}
                        </span>
                    </div>
                    <p className="font-mono text-xs break-all text-muted-foreground">
                        {destination.host}:{destination.port}
                        {destination.path}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {destination.database_name} |{' '}
                        {destination.enabled
                            ? `Daily at ${String(destination.backup_hour).padStart(2, '0')}:00 UTC`
                            : 'Schedule paused'}{' '}
                        |{' '}
                        {destination.verified_at
                            ? 'Connection tested'
                            : 'Not tested'}
                    </p>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={active}
                        onClick={() =>
                            runAction(
                                FileBackupController.backup({
                                    destination: destination.id,
                                }),
                                { success: 'Backup queued' },
                            )
                        }
                    >
                        {active ? 'Backup in progress' : 'Back up now'}
                    </Button>
                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={active}
                        onClick={() => setEditing(!editing)}
                    >
                        Edit
                    </Button>
                    <ConfirmDialog
                        trigger={
                            <Button size="sm" variant="ghost" disabled={active}>
                                Remove
                            </Button>
                        }
                        title={`Remove ${destination.name}?`}
                        description="Local history and saved credentials are removed. Files already on your server remain."
                        confirmLabel="Remove storage"
                        destructive
                        onConfirm={() =>
                            runAction(
                                FileBackupController.destroy({
                                    destination: destination.id,
                                }),
                                { success: 'Storage removed' },
                            )
                        }
                    />
                </div>
            </div>
            {editing && (
                <DestinationForm
                    destination={destination}
                    databases={databases}
                    onClose={() => setEditing(false)}
                />
            )}
            <details className="border-t pt-3 text-sm">
                <summary className="cursor-pointer text-muted-foreground">
                    Backup history ({destination.runs.length} recent runs)
                </summary>
                {destination.runs.length === 0 ? (
                    <p className="pt-3 text-muted-foreground">
                        No backups yet. Use Back up now to create the first
                        export.
                    </p>
                ) : (
                    <div className="space-y-3 pt-3">
                        {destination.runs.map((run) => (
                            <div
                                key={run.id}
                                className="space-y-1 rounded-lg bg-muted/40 p-3"
                            >
                                <div className="flex flex-wrap justify-between gap-2">
                                    <strong className="capitalize">
                                        {run.status}
                                    </strong>
                                    <time>
                                        {run.finished_at ??
                                            run.started_at ??
                                            'Waiting for a worker'}
                                    </time>
                                </div>
                                {run.remote_file && (
                                    <p className="font-mono text-xs break-all">
                                        {run.remote_file}
                                    </p>
                                )}
                                {run.problem && (
                                    <p className="text-destructive">
                                        {run.problem}
                                    </p>
                                )}
                                {run.status === 'succeeded' && (
                                    <p className="text-muted-foreground">
                                        Encrypted upload verified. Restore not
                                        yet tested.
                                    </p>
                                )}
                            </div>
                        ))}
                    </div>
                )}
            </details>
        </section>
    );
}

export function DestinationForm({
    databases,
    destination,
    onClose,
    initialProtocol = 'sftp',
    fixedProtocol = false,
}: {
    databases: DatabaseOption[];
    destination?: Destination;
    onClose?: () => void;
    initialProtocol?: string;
    fixedProtocol?: boolean;
}) {
    const [protocol, setProtocol] = useState(
        destination?.protocol ?? initialProtocol,
    );
    const [port, setPort] = useState(
        String(destination?.port ?? (initialProtocol === 'sftp' ? 22 : 21)),
    );
    const form = destination
        ? FileBackupController.update.form({ destination: destination.id })
        : FileBackupController.store.form();

    return (
        <Form {...form} onSuccess={onClose} className="space-y-5">
            {({ processing, errors }) => (
                <>
                    <FormSection
                        title={
                            destination
                                ? `Edit ${destination.name}`
                                : 'Add a file backup'
                        }
                        description="Encrypted SQL exports sent directly to your own file server. No S3 account is needed."
                    >
                        <div className="grid gap-4 md:grid-cols-2">
                            <Field
                                id="name"
                                label="Backup name"
                                error={errors.name}
                            >
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={destination?.name}
                                    required
                                    maxLength={60}
                                />
                            </Field>
                            <Field
                                id="database_id"
                                label="Database"
                                error={errors.database_id}
                            >
                                <Select
                                    name="database_id"
                                    defaultValue={destination?.database_id}
                                >
                                    <SelectTrigger
                                        id="database_id"
                                        className="w-full"
                                    >
                                        <SelectValue placeholder="Select a database" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {databases.map((database) => (
                                            <SelectItem
                                                value={database.id}
                                                key={database.id}
                                            >
                                                {database.name} |{' '}
                                                {database.engine}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field
                                id="protocol"
                                label="Protocol"
                                error={errors.protocol}
                            >
                                {fixedProtocol ? (
                                    <>
                                        <input
                                            type="hidden"
                                            name="protocol"
                                            value={protocol}
                                        />
                                        <Input
                                            id="protocol"
                                            value={protocol.toUpperCase()}
                                            readOnly
                                        />
                                    </>
                                ) : (
                                    <Select
                                        name="protocol"
                                        value={protocol}
                                        onValueChange={(value) => {
                                            setProtocol(value);
                                            setPort(
                                                value === 'sftp' ? '22' : '21',
                                            );
                                        }}
                                    >
                                        <SelectTrigger
                                            id="protocol"
                                            className="w-full"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="sftp">
                                                SFTP (SSH)
                                            </SelectItem>
                                            <SelectItem value="ftps">
                                                FTPS (explicit TLS)
                                            </SelectItem>
                                            <SelectItem value="ftp">
                                                FTP (unencrypted transport)
                                            </SelectItem>
                                        </SelectContent>
                                    </Select>
                                )}
                            </Field>
                            <Field
                                id="host"
                                label="Host or IP address"
                                error={errors.host}
                            >
                                <Input
                                    id="host"
                                    name="host"
                                    defaultValue={destination?.host}
                                    placeholder="backup.example.com"
                                    required
                                />
                            </Field>
                            <Field id="port" label="Port" error={errors.port}>
                                <Input
                                    id="port"
                                    name="port"
                                    type="number"
                                    min={1}
                                    max={65535}
                                    value={port}
                                    onChange={(event) =>
                                        setPort(event.target.value)
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                id="username"
                                label="Username"
                                error={errors.username}
                            >
                                <Input
                                    id="username"
                                    name="username"
                                    autoComplete="off"
                                    defaultValue={destination?.username}
                                    required
                                />
                            </Field>
                            <Field
                                id="password"
                                label="Server password"
                                error={errors.password}
                                hint={
                                    destination
                                        ? 'Leave blank to keep the saved password.'
                                        : undefined
                                }
                            >
                                <Input
                                    id="password"
                                    name="password"
                                    type="password"
                                    autoComplete="new-password"
                                    required={!destination}
                                />
                            </Field>
                            <Field
                                id="path"
                                label="Remote directory"
                                error={errors.path}
                                hint="Databasify keeps its files in a separate subdirectory here."
                            >
                                <Input
                                    id="path"
                                    name="path"
                                    defaultValue={
                                        destination?.path ?? '/backups'
                                    }
                                    required
                                />
                            </Field>
                            <Field
                                id="encryption_password"
                                label="Backup encryption passphrase"
                                error={errors.encryption_password}
                                hint={
                                    destination
                                        ? 'Leave blank to keep it. Keep old passphrases to restore older backups.'
                                        : 'At least 16 characters. Keep a separate copy: without it, backups cannot be restored.'
                                }
                            >
                                <Input
                                    id="encryption_password"
                                    name="encryption_password"
                                    type="password"
                                    autoComplete="new-password"
                                    minLength={16}
                                    required={!destination}
                                />
                            </Field>
                            <Field
                                id="backup_hour"
                                label="Daily backup hour (UTC)"
                                error={errors.backup_hour}
                            >
                                <Input
                                    id="backup_hour"
                                    name="backup_hour"
                                    type="number"
                                    min={0}
                                    max={23}
                                    defaultValue={destination?.backup_hour ?? 3}
                                    required
                                />
                            </Field>
                        </div>
                        {protocol === 'sftp' && (
                            <Field
                                id="host_key"
                                label="Verified SSH server public key"
                                error={errors.host_key}
                                hint="Paste the verified key type and key, for example ssh-ed25519 AAAA...; omit the hostname and comment."
                            >
                                <Input
                                    id="host_key"
                                    name="host_key"
                                    defaultValue={destination?.host_key ?? ''}
                                    className="font-mono"
                                    required
                                />
                            </Field>
                        )}
                        {protocol === 'ftp' && (
                            <Label className="flex items-start gap-3 rounded-lg border p-3">
                                <input
                                    type="checkbox"
                                    name="confirm_plain_ftp"
                                    value="1"
                                    required
                                />
                                <span>
                                    FTP exposes the server password and
                                    connection traffic. I understand and choose
                                    FTP. The exported backup file is encrypted.
                                </span>
                            </Label>
                        )}
                        <InputError message={errors.confirm_plain_ftp} />
                        <input type="hidden" name="enabled" value="0" />
                        <Label className="flex items-center gap-3">
                            <input
                                type="checkbox"
                                name="enabled"
                                value="1"
                                defaultChecked={destination?.enabled ?? true}
                            />
                            Enable daily backups
                        </Label>
                        <p className="text-sm text-muted-foreground">
                            Requires rclone and GnuPG on the database server.
                            PostgreSQL, MySQL and MariaDB are supported. SQL
                            exports are separate from point-in-time recovery;
                            pause schema changes during exports and use
                            transactional MySQL/MariaDB tables.
                        </p>
                        <InputError message={errors.storage} />
                        <InputError message={errors.backup} />
                        <div className="flex gap-2">
                            <Button
                                type="submit"
                                disabled={processing || databases.length === 0}
                            >
                                {processing
                                    ? 'Testing connection...'
                                    : 'Test connection and save'}
                            </Button>
                            {onClose && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={onClose}
                                >
                                    Cancel
                                </Button>
                            )}
                        </div>
                    </FormSection>
                </>
            )}
        </Form>
    );
}
