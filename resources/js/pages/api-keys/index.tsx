import { Head, usePage } from '@inertiajs/react';
import { KeyRound, Plus } from 'lucide-react';
import { useState } from 'react';
import ApiKeyController from '@/actions/App/Http/Controllers/ApiKeys/ApiKeyController';
import ApiKeyRow from '@/components/api-keys/api-key-row';
import CreateApiKeySheet from '@/components/api-keys/create-api-key-sheet';
import NewApiKeyBanner from '@/components/api-keys/new-api-key-banner';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import type { AccessAreaOption, ApiKey } from '@/types';

export default function ApiKeysIndex({
    apiKeys,
    areas,
    projects,
}: {
    apiKeys: ApiKey[];
    areas: AccessAreaOption[];
    projects: { id: string; name: string }[];
}) {
    const created = usePage().flash.apiKey;
    const [creating, setCreating] = useState(false);
    const [dismissed, setDismissed] = useState<string | null>(null);

    return (
        <>
            <Head title="API keys" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="API keys"
                        description="Keys let scripts and CI call the Databasify API as you."
                    />
                    <Button onClick={() => setCreating(true)}>
                        <Plus />
                        Create API key
                    </Button>
                </div>

                {created && created.key !== dismissed && (
                    <NewApiKeyBanner
                        created={created}
                        onDismiss={() => setDismissed(created.key)}
                    />
                )}

                {apiKeys.length === 0 ? (
                    <EmptyState
                        icon={KeyRound}
                        title="Create your first API key"
                        description="Give a script or CI job its own key, limited to the areas and projects it needs."
                    />
                ) : (
                    <div className="divide-y rounded-xl border">
                        {apiKeys.map((apiKey) => (
                            <ApiKeyRow
                                key={apiKey.id}
                                apiKey={apiKey}
                                areas={areas}
                            />
                        ))}
                    </div>
                )}
            </div>
            <CreateApiKeySheet
                open={creating}
                onOpenChange={setCreating}
                areas={areas}
                projects={projects}
            />
        </>
    );
}

ApiKeysIndex.layout = {
    breadcrumbs: [{ title: 'API keys', href: ApiKeyController.index() }],
};
