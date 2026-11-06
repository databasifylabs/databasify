import { Form, Head } from '@inertiajs/react';
import { HardDrive } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import SubmitPanel from '@/components/form/submit-panel';
import Heading from '@/components/heading';
import StorageProviderFields from '@/components/storage/storage-provider-fields';
import type { StorageProvider } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

function missingOf(
    name: string,
    bucket: string,
    creating: boolean,
): string | null {
    if (creating && bucket === '') {
        return 'Fill in the bucket';
    }

    return name === '' ? 'Name the storage' : null;
}

export default function StorageProviderForm({
    title,
    form,
    provider,
    action,
    icon,
}: {
    title: string;
    form: RouteFormDefinition<'post'>;
    provider?: StorageProvider;
    action: string;
    icon: LucideIcon;
}) {
    const creating = provider === undefined;
    const [name, setName] = useState(provider?.name ?? '');
    const [bucket, setBucket] = useState(provider?.bucket ?? '');

    return (
        <>
            <Head title={title} />
            <Form
                {...form}
                className="mx-auto w-full max-w-5xl space-y-5 p-4 pb-10 md:p-6"
            >
                {({ processing, errors }) => (
                    <>
                        <Heading title={title} />
                        <StorageProviderFields
                            provider={provider}
                            errors={errors}
                            onBucket={setBucket}
                        />
                        <SubmitPanel
                            name={name}
                            onName={setName}
                            nameLabel="Storage name"
                            placeholder="R2 archives"
                            helper="How this storage appears in Databasify."
                            lowercase={false}
                            error={errors.name}
                            processing={processing}
                            missing={missingOf(name, bucket, creating)}
                            action={action}
                            icon={icon}
                            summary={
                                <div className="flex items-center gap-3 md:flex-row-reverse">
                                    <div className="flex size-10 items-center justify-center rounded-lg border bg-background">
                                        <HardDrive className="size-5 text-muted-foreground" />
                                    </div>
                                    <div>
                                        <div className="font-semibold">
                                            {creating
                                                ? bucket || 'No bucket yet'
                                                : bucket}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {name || 'Unnamed storage'}
                                        </div>
                                    </div>
                                </div>
                            }
                        />
                    </>
                )}
            </Form>
        </>
    );
}
