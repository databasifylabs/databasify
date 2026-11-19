import { Form, Head, Link, usePoll } from '@inertiajs/react';
import { useState } from 'react';
import FileBackupController from '@/actions/App/Http/Controllers/Storage/FileBackupController';
import StorageProviderController from '@/actions/App/Http/Controllers/Storage/StorageProviderController';
import { FormSection } from '@/components/form/choice';
import Field from '@/components/form/field';
import Heading from '@/components/heading';
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

type Destination = {
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
type DatabaseOption = { id: string; name: string; engine: string };

function DestinationForm({
    databases,
    destination,
    onClose,
}: {
    databases: DatabaseOption[];
    destination?: Destination;
    onClose?: () => void;
}) {
    const [protocol, setProtocol] = useState(destination?.protocol ?? 'sftp');
    const [port, setPort] = useState(String(destination?.port ?? 22));
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
                                                {database.name} Â·{' '}
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
                                <Select
                                    name="protocol"
                                    value={protocol}
                                    onValueChange={(value) => {
                                        setProtocol(value);
                                        setPort(value === 'sftp' ? '22' : '21');
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
                                hint="Paste the verified key type and key, for example ssh-ed25519 AAAAâ€¦; omit the hostname and comment."
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
                                    ? 'Testing connectionâ€¦'
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

export default function FileBackups({
    destinations,
    databases,
}: {
    destinations: Destination[];
    databases: DatabaseOption[];
}) {
    const [editing, setEditing] = useState<string | null>(null);
    usePoll(10000, { only: ['destinations'] });

    return (
        <>
            <Head title="File backups" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="File backups"
                        description="Direct FTP, FTPS and SFTP backups, with encryption and upload verification."
                    />
                    <Button variant="outline" asChild>
                        <Link href={StorageProviderController.index()}>
                            S3 storage
                        </Link>
                    </Button>
                </div>
                {databases.length === 0 && (
                    <p className="rounded-lg border p-4 text-sm text-muted-foreground">
                        Create a PostgreSQL, MySQL or MariaDB database first,
                        then add its backup destination here.
                    </p>
                )}
                {destinations.map((destination) => {
                    const active = destination.runs.some(
                        (run) =>
                            run.status === 'queued' || run.status === 'running',
                    );
                    return (
                        <section
                            key={destination.id}
                            className="space-y-4 rounded-xl border p-5"
                        >
                            <div className="flex flex-wrap justify-between gap-3">
                                <div>
                                    <h2 className="font-semibold">
                                        {destination.name}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {destination.database_name} Â·{' '}
                                        {destination.protocol.toUpperCase()} Â·{' '}
                                        {destination.host}:{destination.port}
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {destination.enabled
                                            ? `Daily at ${String(destination.backup_hour).padStart(2, '0')}:00 UTC`
                                            : 'Schedule paused'}{' '}
                                        Â·{' '}
                                        {destination.verified_at
                                            ? 'Connection tested'
                                            : 'Connection not tested'}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        size="sm"
                                        disabled={active}
                                        onClick={() =>
                                            runAction(
                                                FileBackupController.backup(
                                                    destination.id,
                                                ),
                                                { success: 'Backup queued' },
                                            )
                                        }
                                    >
                                        {active
                                            ? 'Backup in progress'
                                            : 'Back up now'}
                                    </Button>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={active}
                                        onClick={() =>
                                            setEditing(
                                                editing === destination.id
                                                    ? null
                                                    : destination.id,
                                            )
                                        }
                                    >
                                        Edit
                                    </Button>
                                    <ConfirmDialog
                                        trigger={
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={active}
                                            >
                                                Remove
                                            </Button>
                                        }
                                        title={`Remove ${destination.name}?`}
                                        description="The destination and local run history will be removed. Remote backups stay on your file server."
                                        confirmLabel="Remove destination"
                                        destructive
                                        onConfirm={() =>
                                            runAction(
                                                FileBackupController.destroy(
                                                    destination.id,
                                                ),
                                                {
                                                    success:
                                                        'Destination removed',
                                                },
                                            )
                                        }
                                    />
                                </div>
                            </div>
                            {editing === destination.id && (
                                <DestinationForm
                                    key={destination.id}
                                    destination={destination}
                                    databases={databases}
                                    onClose={() => setEditing(null)}
                                />
                            )}
                            {destination.runs.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No backups yet. Run the first backup to
                                    verify the export and upload.
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    {destination.runs.map((run) => (
                                        <div
                                            key={run.id}
                                            className="space-y-1 border-t pt-3 text-sm"
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
                                                    Encrypted file uploaded;
                                                    read-back checksum verified.
                                                    Restore not yet tested.
                                                </p>
                                            )}
                                        </div>
                                    ))}
                                </div>
                            )}
                        </section>
                    );
                })}
                <DestinationForm databases={databases} />
            </div>
        </>
    );
}

FileBackups.layout = () => ({
    breadcrumbs: [
        { title: 'Storage', href: StorageProviderController.index() },
        { title: 'File backups', href: FileBackupController.index() },
    ],
});
