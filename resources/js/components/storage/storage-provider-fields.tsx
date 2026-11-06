import { LockKeyhole } from 'lucide-react';
import { useState } from 'react';
import { FormSection } from '@/components/form/choice';
import Field from '@/components/form/field';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import type { StorageProvider } from '@/types';

export default function StorageProviderFields({
    provider,
    errors,
    onBucket,
}: {
    provider?: StorageProvider;
    errors: Partial<Record<string, string>>;
    onBucket?: (bucket: string) => void;
}) {
    const [pathStyle, setPathStyle] = useState(provider?.path_style ?? false);
    const inUse = (provider?.databases ?? []).length > 0;

    return (
        <>
            <input
                type="hidden"
                name="path_style"
                value={pathStyle ? '1' : '0'}
            />
            <FormSection
                title="Bucket"
                description="Any S3-compatible storage: AWS S3, Cloudflare R2, DigitalOcean Spaces, Backblaze B2, Wasabi, MinIO."
            >
                {inUse && (
                    <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                        A database archives here, so the endpoint, bucket and
                        path stay as they are. You can still rename it or rotate
                        its keys.
                    </p>
                )}
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field
                        id="endpoint"
                        label="Endpoint"
                        error={errors.endpoint}
                        hint="The HTTPS address of the S3 API, without the bucket."
                    >
                        <Input
                            id="endpoint"
                            name="endpoint"
                            defaultValue={provider?.endpoint}
                            readOnly={inUse}
                            placeholder="https://s3.us-east-1.amazonaws.com"
                        />
                    </Field>
                    <Field
                        id="region"
                        label="Region"
                        error={errors.region}
                        hint="Use auto for Cloudflare R2, us-east-1 for MinIO."
                    >
                        <Input
                            id="region"
                            name="region"
                            defaultValue={provider?.region}
                            readOnly={inUse}
                            placeholder="us-east-1"
                        />
                    </Field>
                    <Field id="bucket" label="Bucket" error={errors.bucket}>
                        <Input
                            id="bucket"
                            name="bucket"
                            defaultValue={provider?.bucket}
                            readOnly={inUse}
                            onChange={(event) => onBucket?.(event.target.value)}
                        />
                    </Field>
                    <Field
                        id="path_prefix"
                        label="Path prefix"
                        error={errors.path_prefix}
                        hint="Optional folder inside the bucket, such as databasify."
                    >
                        <Input
                            id="path_prefix"
                            name="path_prefix"
                            defaultValue={provider?.path_prefix}
                            readOnly={inUse}
                            placeholder="databasify"
                        />
                    </Field>
                </div>
                <label className="flex items-start justify-between gap-4 rounded-xl border p-4">
                    <span className="space-y-1">
                        <span className="block text-sm font-medium">
                            Path-style addressing
                        </span>
                        <span className="block text-xs text-muted-foreground">
                            Sends requests to endpoint/bucket instead of
                            bucket.endpoint. Turn it on for MinIO and services
                            without per-bucket hostnames.
                        </span>
                    </span>
                    <Switch
                        checked={pathStyle}
                        onCheckedChange={setPathStyle}
                        disabled={inUse}
                    />
                </label>
                <InputError message={errors.path_style} />
            </FormSection>

            <FormSection
                title="Access"
                description="The key needs permission to read, write, list and delete objects in the bucket."
            >
                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                    <Field
                        id="access_key_id"
                        label="Access key"
                        error={errors.access_key_id}
                    >
                        <Input
                            id="access_key_id"
                            name="access_key_id"
                            defaultValue={provider?.access_key_id}
                            autoComplete="off"
                            className="font-mono"
                        />
                    </Field>
                    <Field
                        id="secret_access_key"
                        label="Secret key"
                        error={errors.secret_access_key}
                        hint={
                            provider
                                ? 'Leave empty to keep the current secret.'
                                : undefined
                        }
                    >
                        <Input
                            id="secret_access_key"
                            name="secret_access_key"
                            type="password"
                            autoComplete="new-password"
                            className="font-mono"
                        />
                    </Field>
                </div>
                <InputError message={errors.storage} />
                <div className="flex gap-2.5 rounded-xl border p-3 text-xs text-muted-foreground">
                    <LockKeyhole className="mt-0.5 size-4 shrink-0 text-green-600" />
                    <p>
                        Checked by writing, reading and deleting a small test
                        file before saving, then stored encrypted. Keys only go
                        to the nodes of databases that archive here.
                    </p>
                </div>
            </FormSection>
        </>
    );
}
