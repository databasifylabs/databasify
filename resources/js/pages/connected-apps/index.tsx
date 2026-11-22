import { Head } from '@inertiajs/react';
import { Bot, Plus } from 'lucide-react';
import { useState } from 'react';
import ConnectedAppController from '@/actions/App/Http/Controllers/ConnectedApps/ConnectedAppController';
import ConnectAgentSheet from '@/components/connected-apps/connect-agent-sheet';
import ConnectedAppRow from '@/components/connected-apps/connected-app-row';
import EmptyState from '@/components/empty-state';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import type { AiAgentOption, AccessAreaOption, ConnectedApp } from '@/types';

export default function ConnectedAppsIndex({
    connectedApps,
    agents,
    areas,
    mcpUrl,
}: {
    connectedApps: ConnectedApp[];
    agents: AiAgentOption[];
    areas: AccessAreaOption[];
    mcpUrl: string;
}) {
    const [connecting, setConnecting] = useState(false);

    return (
        <>
            <Head title="Connected apps" />
            <div className="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="Connected apps"
                        description="AI agents that manage Databasify over MCP, with only the access you approved."
                    />
                    <Button onClick={() => setConnecting(true)}>
                        <Plus />
                        Connect an agent
                    </Button>
                </div>

                {connectedApps.length === 0 ? (
                    <EmptyState
                        icon={Bot}
                        title="Connect your first agent"
                        description="Let Claude, ChatGPT, Cursor or another MCP client work with your projects. You pick what it may do, and you can remove it at any time."
                    />
                ) : (
                    <div className="divide-y rounded-xl border">
                        {connectedApps.map((app) => (
                            <ConnectedAppRow
                                key={app.id}
                                app={app}
                                areas={areas}
                            />
                        ))}
                    </div>
                )}
            </div>
            <ConnectAgentSheet
                open={connecting}
                onOpenChange={setConnecting}
                agents={agents}
                mcpUrl={mcpUrl}
            />
        </>
    );
}

ConnectedAppsIndex.layout = {
    breadcrumbs: [
        { title: 'Connected apps', href: ConnectedAppController.index() },
    ],
};
