import { Head, Link } from '@inertiajs/react';
import { Cloud, HardDrive, LockKeyhole, Plus, Server } from 'lucide-react';
import { useState } from 'react';
import StorageProviderController from '@/actions/App/Http/Controllers/Storage/StorageProviderController';
import Heading from '@/components/heading';
import { DestinationForm } from '@/components/storage/file-backup';
import type { DatabaseOption } from '@/components/storage/file-backup';
import StorageProviderForm from '@/components/storage/storage-provider-form';
import { Button } from '@/components/ui/button';
import { storagePresets } from '@/lib/storage-presets';
import type { StoragePreset } from '@/lib/storage-presets';

type Service = StoragePreset | 'ftp' | 'ftps' | 'sftp';
const services: {
    id: Service;
    name: string;
    hint: string;
    icon: typeof Cloud;
}[] = [
    { id: 'aws', name: 'Amazon S3', hint: 'AWS bucket', icon: Cloud },
    { id: 'r2', name: 'Cloudflare R2', hint: 'R2 bucket', icon: Cloud },
    {
        id: 'spaces',
        name: 'DigitalOcean Spaces',
        hint: 'Spaces bucket',
        icon: Cloud,
    },
    { id: 'b2', name: 'Backblaze B2', hint: 'B2 S3 API', icon: Cloud },
    { id: 'wasabi', name: 'Wasabi', hint: 'Cloud bucket', icon: Cloud },
    { id: 'minio', name: 'MinIO', hint: 'Your own S3 server', icon: Server },
    {
        id: 'custom',
        name: 'Custom S3',
        hint: 'Any S3-compatible service',
        icon: HardDrive,
    },
    { id: 'sftp', name: 'SFTP', hint: 'SSH file server', icon: LockKeyhole },
    { id: 'ftps', name: 'FTPS', hint: 'FTP with TLS', icon: LockKeyhole },
    { id: 'ftp', name: 'FTP', hint: 'Plain FTP server', icon: Server },
];

export default function CreateStorageProvider({
    databases,
}: {
    databases: DatabaseOption[];
}) {
    const [service, setService] = useState<Service | null>(null);
    const selected = services.find((item) => item.id === service);
    const fileService =
        service === 'ftp' || service === 'ftps' || service === 'sftp';
    return (
        <>
            <Head title="Add storage" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 pb-10 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <Heading
                        title="Add storage"
                        description="Choose your service, then enter its connection details below."
                    />
                    <Button asChild variant="outline">
                        <Link href={StorageProviderController.index()}>
                            Back to storage
                        </Link>
                    </Button>
                </div>
                <fieldset className="space-y-3">
                    <legend className="mb-3 text-sm font-medium">
                        Storage service
                    </legend>
                    <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
                        {services.map((item) => (
                            <label
                                key={item.id}
                                className="relative cursor-pointer"
                            >
                                <input
                                    className="peer sr-only"
                                    type="radio"
                                    name="storage-service"
                                    value={item.id}
                                    checked={service === item.id}
                                    onChange={() => setService(item.id)}
                                />
                                <span className="flex h-full min-h-28 flex-col gap-2 rounded-xl border bg-card p-4 transition-colors peer-checked:border-primary peer-checked:ring-1 peer-checked:ring-primary peer-focus-visible:outline-2 peer-focus-visible:outline-offset-2 peer-focus-visible:outline-ring hover:bg-muted/40">
                                    <item.icon className="size-5 text-muted-foreground" />
                                    <span className="text-sm font-medium">
                                        {item.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {item.hint}
                                    </span>
                                </span>
                            </label>
                        ))}
                    </div>
                </fieldset>
                {service && selected ? (
                    <section key={service} className="space-y-5 border-t pt-6">
                        <div>
                            <h2 className="text-lg font-semibold">
                                Configure {selected.name}
                            </h2>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {fileService
                                    ? 'Encrypted SQL exports to your file server. No S3 account is needed.'
                                    : storagePresets[service as StoragePreset]
                                          .hint}
                            </p>
                        </div>
                        {fileService ? (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    {databases.length === 0
                                        ? 'Create a PostgreSQL, MySQL or MariaDB database before adding this destination.'
                                        : 'Select the database to back up and choose a daily UTC schedule.'}
                                </p>
                                <DestinationForm
                                    databases={databases}
                                    initialProtocol={service}
                                    fixedProtocol
                                />
                            </>
                        ) : (
                            <StorageProviderForm
                                title={`Add ${selected.name}`}
                                form={StorageProviderController.store.form()}
                                action="Test connection and add storage"
                                icon={Plus}
                                initialPreset={service as StoragePreset}
                                embedded
                            />
                        )}
                    </section>
                ) : (
                    <p className="rounded-xl border border-dashed p-5 text-sm text-muted-foreground">
                        Select a storage service to see its setup form.
                    </p>
                )}
            </div>
        </>
    );
}

CreateStorageProvider.layout = () => ({
    breadcrumbs: [
        { title: 'Storage', href: StorageProviderController.index() },
        { title: 'Add storage', href: StorageProviderController.create() },
    ],
});
