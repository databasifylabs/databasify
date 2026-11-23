import {
    ArrowUpCircle,
    Hourglass,
    Plus,
    Puzzle,
    Search,
    TriangleAlert,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import DatabaseExtensionController from '@/actions/App/Http/Controllers/Databases/DatabaseExtensionController';
import ConfirmDialog from '@/components/confirm-dialog';
import DeleteButton from '@/components/delete-button';
import EmptyState from '@/components/empty-state';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { HttpError, getJson } from '@/lib/http';
import { runAction } from '@/lib/run-action';
import type {
    AvailableExtension,
    Database,
    ExtensionChange,
    InstalledExtension,
    Schema,
} from '@/types';

function failureOf(error: unknown): string {
    return error instanceof HttpError
        ? error.message
        : 'The database did not answer.';
}

function EnableDialog({
    database,
    schema,
    installed,
    busy,
}: {
    database: Database;
    schema: Schema;
    installed: InstalledExtension[];
    busy: boolean;
}) {
    const [open, setOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [available, setAvailable] = useState<AvailableExtension[] | null>(
        null,
    );
    const [failure, setFailure] = useState<string | null>(null);

    useEffect(() => {
        if (!open || available !== null) {
            return;
        }

        void getJson<{ extensions: AvailableExtension[] }>(
            DatabaseExtensionController.available.url({
                database: database.id,
            }),
        )
            .then((reply) => setAvailable(reply.extensions))
            .catch((error: unknown) => setFailure(failureOf(error)));
    }, [open, available, database.id]);

    const groups = useMemo(() => {
        const taken = new Set(installed.map((extension) => extension.name));
        const term = search.trim().toLowerCase();
        const matches = (available ?? []).filter(
            (extension) =>
                !taken.has(extension.name) &&
                (term === '' ||
                    `${extension.name} ${extension.label} ${extension.description}`
                        .toLowerCase()
                        .includes(term)),
        );

        return Object.entries(
            Object.groupBy(matches, (extension) => extension.group_label),
        );
    }, [available, installed, search]);

    const enable = (extension: AvailableExtension) =>
        runAction(
            DatabaseExtensionController.store({
                database: database.id,
                schema: schema.id,
            }),
            {
                data: { name: extension.name },
                success: `Enabling ${extension.name}`,
                onSuccess: () => setOpen(false),
            },
        );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button disabled={busy}>
                    <Plus />
                    Enable extension
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[80vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>
                        Enable an extension in {schema.name}
                    </DialogTitle>
                    <DialogDescription>
                        Every extension here ships with this database's image.
                    </DialogDescription>
                </DialogHeader>
                <div className="relative">
                    <Search className="absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                    <Input
                        className="pl-8"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Search extensions"
                        aria-label="Search extensions"
                    />
                </div>
                {failure !== null ? (
                    <p className="text-sm text-destructive">{failure}</p>
                ) : available === null ? (
                    <div className="space-y-2">
                        {[0, 1, 2, 3].map((row) => (
                            <Skeleton key={row} className="h-12 w-full" />
                        ))}
                    </div>
                ) : (
                    <div className="space-y-5">
                        {groups.map(([group, extensions]) => (
                            <div key={group} className="space-y-2">
                                <h3 className="text-sm font-medium text-muted-foreground">
                                    {group}
                                </h3>
                                {(extensions ?? []).map((extension) => (
                                    <div
                                        key={extension.name}
                                        className="flex items-start justify-between gap-4 rounded-md border p-3"
                                    >
                                        <div className="min-w-0 space-y-1">
                                            <p className="font-medium">
                                                {extension.label}{' '}
                                                <span className="text-xs text-muted-foreground">
                                                    {extension.default_version}
                                                </span>
                                            </p>
                                            <p className="text-sm text-muted-foreground">
                                                {extension.description}
                                            </p>
                                            {extension.needs_restart && (
                                                <p className="text-xs text-amber-600 dark:text-amber-400">
                                                    Enabling it restarts the
                                                    database node by node.
                                                </p>
                                            )}
                                        </div>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            disabled={busy}
                                            onClick={() => enable(extension)}
                                        >
                                            Enable
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        ))}
                        {groups.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                No extension matches “{search}”.
                            </p>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export default function ExtensionsTab({
    database,
    busy,
}: {
    database: Database;
    busy: boolean;
}) {
    const schemas = useMemo(
        () =>
            [...database.schemas].sort(
                (left, right) =>
                    Number(right.is_default) - Number(left.is_default),
            ),
        [database.schemas],
    );
    const [schemaId, setSchemaId] = useState(schemas[0]?.id ?? '');
    const schema = schemas.find((entry) => entry.id === schemaId);
    const [installed, setInstalled] = useState<InstalledExtension[] | null>(
        null,
    );
    const [change, setChange] = useState<ExtensionChange | null>(null);
    const [failure, setFailure] = useState<string | null>(null);
    const ready = database.status === 'ready';
    const creating =
        database.status === 'pending' || database.status === 'creating';

    const load = useCallback(() => {
        if (schemaId === '') {
            return;
        }

        setFailure(null);
        void getJson<{
            extensions: InstalledExtension[];
            change: ExtensionChange | null;
        }>(
            DatabaseExtensionController.index.url({
                database: database.id,
                schema: schemaId,
            }),
        )
            .then((reply) => {
                setInstalled(reply.extensions);
                setChange(reply.change);
            })
            .catch((error: unknown) => setFailure(failureOf(error)));
    }, [database.id, schemaId]);

    useEffect(() => {
        if (!creating) {
            load();
        }
    }, [load, creating, ready]);

    if (schema === undefined) {
        return (
            <EmptyState
                icon={Puzzle}
                title="No databases yet"
                description="Create a database first, then enable extensions in it."
            />
        );
    }

    const route = { database: database.id, schema: schema.id };
    const pending =
        change !== null && change.schema === schema.name ? change : null;
    const rows: InstalledExtension[] =
        installed === null ||
        pending?.action !== 'adding' ||
        installed.some((extension) => extension.name === pending.name)
            ? (installed ?? [])
            : [
                  {
                      name: pending.name,
                      version: '',
                      default_version: '',
                      has_update: false,
                  },
                  ...installed,
              ];
    const pendingLabel =
        pending === null
            ? ''
            : {
                  adding: 'Adding…',
                  updating: `Updating to ${pending.target_version ?? 'the newest version'}…`,
                  removing: 'Removing…',
              }[pending.action];

    return (
        <div className="space-y-6">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Select
                    value={schemaId}
                    onValueChange={(value) => {
                        setInstalled(null);
                        setSchemaId(value);
                    }}
                >
                    <SelectTrigger className="w-64" aria-label="Database">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {schemas.map((entry) => (
                            <SelectItem key={entry.id} value={entry.id}>
                                {entry.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <EnableDialog
                    database={database}
                    schema={schema}
                    installed={installed ?? []}
                    busy={busy || creating || installed === null}
                />
            </div>
            {installed === null && creating ? (
                <EmptyState
                    icon={Hourglass}
                    title={`Extensions show here once ${database.name} is ready`}
                    description="The database is still being created, so its extensions can't be read yet."
                />
            ) : installed === null && failure !== null ? (
                <EmptyState
                    icon={TriangleAlert}
                    title="Couldn't read the extensions"
                    description={failure}
                >
                    <Button variant="outline" onClick={load}>
                        Try again
                    </Button>
                </EmptyState>
            ) : installed === null ? (
                <div className="space-y-2">
                    {[0, 1, 2].map((row) => (
                        <Skeleton key={row} className="h-10 w-full" />
                    ))}
                </div>
            ) : rows.length === 0 ? (
                <EmptyState
                    icon={Puzzle}
                    title={`No extensions in ${schema.name} yet`}
                    description="Enable extensions from the list here. Users with write access can also enable offered extensions themselves."
                />
            ) : (
                <Card className="gap-0 py-0">
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead className="pl-6">
                                    Extension
                                </TableHead>
                                <TableHead>Version</TableHead>
                                <TableHead className="w-40 pr-6" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.map((extension) => {
                                const changing =
                                    pending?.name === extension.name;

                                return (
                                    <TableRow
                                        key={extension.name}
                                        className={
                                            changing &&
                                            pending?.action === 'removing'
                                                ? 'opacity-60'
                                                : undefined
                                        }
                                    >
                                        <TableCell className="pl-6 font-medium">
                                            {extension.name}
                                        </TableCell>
                                        <TableCell>
                                            {extension.version || '—'}
                                            {changing && (
                                                <Badge
                                                    variant="outline"
                                                    className="ml-2 gap-1"
                                                >
                                                    <Spinner className="size-3" />
                                                    {pendingLabel}
                                                </Badge>
                                            )}
                                            {!changing &&
                                                extension.has_update && (
                                                    <Badge
                                                        variant="secondary"
                                                        className="ml-2"
                                                    >
                                                        {
                                                            extension.default_version
                                                        }{' '}
                                                        available
                                                    </Badge>
                                                )}
                                        </TableCell>
                                        <TableCell className="pr-6">
                                            {!changing && (
                                                <div className="flex justify-end gap-1">
                                                    {extension.has_update && (
                                                        <ConfirmDialog
                                                            trigger={
                                                                <Button
                                                                    size="sm"
                                                                    variant="ghost"
                                                                    disabled={
                                                                        busy
                                                                    }
                                                                >
                                                                    <ArrowUpCircle />
                                                                    Update
                                                                </Button>
                                                            }
                                                            title={`Update ${extension.name} to ${extension.default_version}?`}
                                                            description="Updates can't be undone."
                                                            confirmLabel="Update"
                                                            onConfirm={() =>
                                                                runAction(
                                                                    DatabaseExtensionController.update(
                                                                        {
                                                                            ...route,
                                                                            extension:
                                                                                extension.name,
                                                                        },
                                                                    ),
                                                                    {
                                                                        success: `Updating ${extension.name}`,
                                                                    },
                                                                )
                                                            }
                                                        />
                                                    )}
                                                    <ConfirmDialog
                                                        trigger={
                                                            <DeleteButton
                                                                disabled={busy}
                                                                aria-label={`Disable ${extension.name}`}
                                                            />
                                                        }
                                                        title={`Disable ${extension.name}?`}
                                                        description="This is refused while anything in the schema still uses the extension, or while the extension still holds your data."
                                                        confirmLabel="Disable"
                                                        destructive
                                                        onConfirm={() =>
                                                            runAction(
                                                                DatabaseExtensionController.destroy(
                                                                    {
                                                                        ...route,
                                                                        extension:
                                                                            extension.name,
                                                                    },
                                                                ),
                                                                {
                                                                    success: `Disabling ${extension.name}`,
                                                                },
                                                            )
                                                        }
                                                    />
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </Card>
            )}
        </div>
    );
}
