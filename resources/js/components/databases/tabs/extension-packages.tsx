import { Info, Package, Plus, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import ExtensionPackageController from '@/actions/App/Http/Controllers/Databases/ExtensionPackageController';
import ConfirmDialog from '@/components/confirm-dialog';
import PackageDropzone, {
    uploadProblemOf,
} from '@/components/databases/tabs/package-dropzone';
import { CheckNewerVersionDialog } from '@/components/databases/tabs/extension-package-versions';
import DeleteButton from '@/components/delete-button';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
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
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { getJson } from '@/lib/http';
import { runAction } from '@/lib/run-action';
import type {
    Database,
    ExtensionChange,
    ExtensionPackage,
    PackagePlatform,
} from '@/types';

type Source = ExtensionPackage['source'];

function PlatformNotice({ platform }: { platform: PackagePlatform | null }) {
    if (platform === null) {
        return null;
    }

    const cpus =
        platform.architectures.length > 0
            ? platform.architectures.join(' and ')
            : "your nodes' CPU";

    return (
        <Alert variant="info">
            <Info />
            <AlertTitle className="line-clamp-none">
                Your nodes run {platform.os} with {platform.engine} on {cpus}
            </AlertTitle>
            <AlertDescription>
                Add .{platform.package_format} packages built for this system.
                Packages in another format, or built for other releases, engine
                versions or CPU types, won't install.
            </AlertDescription>
        </Alert>
    );
}

function AddPackageDialog({
    database,
    busy,
    platform,
}: {
    database: Database;
    busy: boolean;
    platform: PackagePlatform | null;
}) {
    const [open, setOpen] = useState(false);
    const [source, setSource] = useState<Source>('official');
    const [pkg, setPkg] = useState('');
    const [repository, setRepository] = useState('');
    const [repositoryKey, setRepositoryKey] = useState('');
    const [files, setFiles] = useState<File[]>([]);
    const [saving, setSaving] = useState(false);

    const ready =
        source === 'upload'
            ? files.length > 0 && uploadProblemOf(files) === null
            : source === 'official'
              ? pkg !== ''
              : pkg !== '' && repository !== '' && repositoryKey !== '';

    const submit = () => {
        setSaving(true);
        runAction(ExtensionPackageController.store({ database: database.id }), {
            data:
                source === 'upload'
                    ? { source, files }
                    : source === 'official'
                      ? { source, package: pkg }
                      : {
                            source,
                            package: pkg,
                            repository,
                            repository_key: repositoryKey,
                        },
            success: 'Adding the package',
            onSuccess: () => setOpen(false),
            onFinish: () => setSaving(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" disabled={busy}>
                    <Plus />
                    Add package
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Add an extension package</DialogTitle>
                    <DialogDescription>
                        Installed on every node of {database.name}. Its
                        extensions then show up under Custom. A newer build of a
                        package you already added replaces it, and its
                        extensions stay enabled.
                    </DialogDescription>
                </DialogHeader>
                <PlatformNotice platform={platform} />
                <Alert variant="warning">
                    <TriangleAlert />
                    <AlertDescription>
                        Package code runs with full database rights. A crashing
                        library stops the database on every node.
                    </AlertDescription>
                </Alert>
                <Tabs
                    value={source}
                    onValueChange={(value) => setSource(value as Source)}
                >
                    <TabsList className="w-full">
                        <TabsTrigger value="official">
                            {platform?.repository ?? 'Official'}
                        </TabsTrigger>
                        <TabsTrigger value="repository">
                            {platform?.repository_label ?? 'Repository'}
                        </TabsTrigger>
                        <TabsTrigger value="upload">Upload</TabsTrigger>
                    </TabsList>
                    <TabsContent value="official" className="space-y-1.5 pt-2">
                        <Label htmlFor="official-package">Package</Label>
                        <Input
                            id="official-package"
                            value={pkg}
                            onChange={(event) => setPkg(event.target.value)}
                            placeholder={platform?.example_package}
                        />
                        {platform !== null && (
                            <p className="text-xs text-muted-foreground">
                                A package from {platform.repository_host} for{' '}
                                {platform.engine} on {platform.os}.
                            </p>
                        )}
                    </TabsContent>
                    <TabsContent value="repository" className="space-y-3 pt-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="package-repository">
                                Repository
                            </Label>
                            <Input
                                id="package-repository"
                                value={repository}
                                onChange={(event) =>
                                    setRepository(event.target.value)
                                }
                                placeholder={platform?.repository_example}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="apt-key">Signing key</Label>
                            <Textarea
                                id="apt-key"
                                value={repositoryKey}
                                onChange={(event) =>
                                    setRepositoryKey(event.target.value)
                                }
                                placeholder="https://example.com/key.asc, or paste the ASCII-armored key"
                                rows={3}
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="apt-package">Package</Label>
                            <Input
                                id="apt-package"
                                value={pkg}
                                onChange={(event) => setPkg(event.target.value)}
                                placeholder="my-extension"
                            />
                        </div>
                    </TabsContent>
                    <TabsContent value="upload" className="pt-2">
                        <PackageDropzone
                            format={platform?.package_format ?? 'deb'}
                            files={files}
                            onChange={setFiles}
                        />
                    </TabsContent>
                </Tabs>
                <div className="flex justify-end">
                    <Button
                        onClick={submit}
                        disabled={busy || saving || !ready}
                    >
                        {saving && <Spinner />}
                        Add package
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export default function ExtensionPackages({
    database,
    busy,
}: {
    database: Database;
    busy: boolean;
}) {
    const [packages, setPackages] = useState<ExtensionPackage[] | null>(null);
    const [change, setChange] = useState<ExtensionChange | null>(null);
    const [platform, setPlatform] = useState<PackagePlatform | null>(null);
    const creating =
        database.status === 'pending' || database.status === 'creating';
    const ready = database.status === 'ready';

    const load = useCallback(() => {
        void getJson<{
            packages: ExtensionPackage[];
            change: ExtensionChange | null;
            platform: PackagePlatform;
        }>(ExtensionPackageController.index.url({ database: database.id }))
            .then((reply) => {
                setPackages(reply.packages);
                setChange(reply.change);
                setPlatform(reply.platform);
            })
            .catch(() => setPackages([]));
    }, [database.id]);

    useEffect(() => {
        if (!creating) {
            load();
        }
    }, [load, creating, ready]);

    const pending = change !== null && change.schema === null ? change : null;
    const changing = pending !== null;

    useEffect(() => {
        if (!changing) {
            return;
        }

        const timer = setInterval(load, 3000);

        return () => clearInterval(timer);
    }, [changing, load]);
    const rows = packages ?? [];
    const adding =
        pending?.action === 'adding' &&
        !rows.some((entry) => entry.request === pending.name);

    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-4">
                <div className="space-y-1.5">
                    <CardTitle>Custom packages</CardTitle>
                    <CardDescription>
                        Extensions the image doesn't ship, from{' '}
                        {platform?.repository ?? 'the official repository'},
                        another package repository or your own package files.
                    </CardDescription>
                </div>
                <AddPackageDialog
                    database={database}
                    busy={busy || creating}
                    platform={platform}
                />
            </CardHeader>
            <CardContent className="space-y-4">
                <PlatformNotice platform={platform} />
                {rows.length === 0 && !adding ? (
                    <div className="flex items-center gap-2 text-sm text-muted-foreground">
                        <Package className="size-4" />
                        No custom packages yet.
                    </div>
                ) : (
                    <Table>
                        <TableHeader>
                            <TableRow className="hover:bg-transparent">
                                <TableHead>Package</TableHead>
                                <TableHead>Source</TableHead>
                                <TableHead>Extensions</TableHead>
                                <TableHead className="w-28" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {adding && pending !== null && (
                                <TableRow>
                                    <TableCell className="font-medium">
                                        {pending.name}
                                    </TableCell>
                                    <TableCell colSpan={3}>
                                        <Badge
                                            variant="outline"
                                            className="gap-1"
                                        >
                                            <Spinner className="size-3" />
                                            Adding…
                                        </Badge>
                                    </TableCell>
                                </TableRow>
                            )}
                            {rows.map((entry) => {
                                const removing =
                                    pending?.action === 'removing' &&
                                    pending.name === entry.request;
                                const updating =
                                    pending?.action === 'updating' &&
                                    pending.name === entry.request;

                                return (
                                    <TableRow
                                        key={entry.id}
                                        className={
                                            removing ? 'opacity-60' : undefined
                                        }
                                    >
                                        <TableCell className="font-medium">
                                            {entry.request}
                                            <p className="text-xs font-normal text-muted-foreground">
                                                {entry.packages.join(', ')} ·{' '}
                                                {entry.architectures.join(', ')}
                                            </p>
                                        </TableCell>
                                        <TableCell>
                                            {entry.source === 'official' &&
                                            platform !== null
                                                ? platform.repository
                                                : entry.source_label}
                                        </TableCell>
                                        <TableCell>
                                            {removing || updating ? (
                                                <Badge
                                                    variant="outline"
                                                    className="gap-1"
                                                >
                                                    <Spinner className="size-3" />
                                                    {removing
                                                        ? 'Removing…'
                                                        : 'Updating…'}
                                                </Badge>
                                            ) : (
                                                entry.extensions.join(', ')
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap">
                                            {!removing &&
                                                !updating &&
                                                entry.source !== 'upload' && (
                                                    <CheckNewerVersionDialog
                                                        database={database}
                                                        entry={entry}
                                                        busy={busy}
                                                    />
                                                )}
                                            {!removing && !updating && (
                                                <ConfirmDialog
                                                    trigger={
                                                        <DeleteButton
                                                            disabled={busy}
                                                            aria-label={`Remove ${entry.request}`}
                                                        />
                                                    }
                                                    title={`Remove ${entry.request}?`}
                                                    description="It is purged from every node. Disable its extensions first."
                                                    confirmLabel="Remove"
                                                    destructive
                                                    onConfirm={() =>
                                                        runAction(
                                                            ExtensionPackageController.destroy(
                                                                {
                                                                    database:
                                                                        database.id,
                                                                    package:
                                                                        entry.id,
                                                                },
                                                            ),
                                                            {
                                                                success: `Removing ${entry.request}`,
                                                            },
                                                        )
                                                    }
                                                />
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                )}
            </CardContent>
        </Card>
    );
}
