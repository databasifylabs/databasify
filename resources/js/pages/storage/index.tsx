import { Head, Link } from '@inertiajs/react';
import { HardDrive, Pencil, Plus, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import StorageProviderController from '@/actions/App/Http/Controllers/Storage/StorageProviderController';
import ConfirmDialog from '@/components/confirm-dialog';
import DeleteButton from '@/components/delete-button';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { timeAgo } from '@/lib/format';
import { runAction } from '@/lib/run-action';
import type { StorageProvider } from '@/types';

function locationOf(provider: StorageProvider): string {
    const bucket = [provider.bucket, provider.path_prefix]
        .filter((part) => part !== '')
        .join('/');

    return `${provider.endpoint} · ${bucket} · ${provider.region}`;
}

function Provider({ provider }: { provider: StorageProvider }) {
    const [testing, setTesting] = useState(false);
    const databases = provider.databases ?? [];

    const test = () => {
        setTesting(true);
        runAction(StorageProviderController.test(provider.id), {
            success: `${provider.name} can write, read and delete in ${provider.bucket}`,
            onFinish: () => setTesting(false),
        });
    };

    return (
        <div className="space-y-4 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <div className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-muted/40">
                        <HardDrive className="size-4 text-muted-foreground" />
                    </div>
                    <div className="min-w-0">
                        <div className="font-medium">{provider.name}</div>
                        <div className="truncate font-mono text-xs text-muted-foreground">
                            {locationOf(provider)}
                        </div>
                    </div>
                </div>
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={test}
                        disabled={testing}
                    >
                        <ShieldCheck /> Test connection
                    </Button>
                    <Button variant="ghost" size="sm" asChild>
                        <Link
                            href={StorageProviderController.edit(provider.id)}
                        >
                            <Pencil /> Edit
                        </Link>
                    </Button>
                    <ConfirmDialog
                        trigger={
                            <DeleteButton
                                aria-label={`Remove ${provider.name}`}
                            />
                        }
                        title={`Remove ${provider.name}?`}
                        description={
                            databases.length > 0
                                ? 'A database still archives here. Turn off its point-in-time recovery first.'
                                : 'Databasify forgets this storage and its keys. Archives already in the bucket stay there.'
                        }
                        confirmLabel="Remove storage"
                        destructive
                        onConfirm={() =>
                            runAction(
                                StorageProviderController.destroy(provider.id),
                                { success: `Removed ${provider.name}` },
                            )
                        }
                    />
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                <span>
                    {provider.verified_at
                        ? `Tested ${timeAgo(provider.verified_at)}`
                        : 'Not tested yet'}
                </span>
                {provider.path_style && (
                    <Badge variant="outline">Path-style</Badge>
                )}
                {databases.length === 0 ? (
                    <span>· No databases archive here yet</span>
                ) : (
                    databases.map((database) => (
                        <Badge
                            key={database.id}
                            variant="secondary"
                            className="font-mono"
                        >
                            {database.name}
                        </Badge>
                    ))
                )}
            </div>
        </div>
    );
}

export default function StorageIndex({
    providers,
}: {
    providers: StorageProvider[];
}) {
    return (
        <>
            <Head title="Storage" />
            <div className="mx-auto w-full max-w-6xl space-y-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Storage"
                        description="S3-compatible buckets that databases archive to for point-in-time recovery."
                    />
                    <Button asChild>
                        <Link href={StorageProviderController.create()}>
                            <Plus /> Add storage
                        </Link>
                    </Button>
                </div>

                {providers.length === 0 ? (
                    <EmptyState
                        icon={HardDrive}
                        title="No storage yet"
                        description="Add a bucket once, then pick it on any database to turn on point-in-time recovery."
                    >
                        <Button asChild>
                            <Link href={StorageProviderController.create()}>
                                <Plus /> Add your first storage
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <div className="space-y-3">
                        {providers.map((provider) => (
                            <Provider key={provider.id} provider={provider} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

StorageIndex.layout = {
    breadcrumbs: [
        { title: 'Storage', href: StorageProviderController.index() },
    ],
};
