import { Globe } from 'lucide-react';
import { useState } from 'react';
import BrandIcon from '@/components/brand-icon';
import { guides, kinds } from '@/components/connected-apps/agent-guides';
import type {
    AuthMode,
    GuideStep,
} from '@/components/connected-apps/agent-guides';
import CopyButton from '@/components/copy-button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import type { AiAgentOption } from '@/types';

const other: AiAgentOption = {
    value: 'other',
    label: 'Other',
    icon: null,
    api_key: true,
    public_url: false,
};

function Step({ step, index }: { step: GuideStep; index: number }) {
    return (
        <li className="flex gap-3">
            <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium">
                {index + 1}
            </span>
            <div className="min-w-0 flex-1 space-y-2 pt-0.5 text-sm">
                <p>{step.text}</p>
                {step.code && (
                    <div className="rounded-lg border bg-muted/50">
                        {step.file && (
                            <div className="border-b px-3 py-1.5 font-mono text-xs text-muted-foreground">
                                {step.file}
                            </div>
                        )}
                        <div className="flex items-start gap-1">
                            <pre className="min-w-0 flex-1 overflow-x-auto py-2.5 pl-3 font-mono text-xs">
                                {step.code}
                            </pre>
                            <div className="shrink-0 p-1">
                                <CopyButton value={step.code} />
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </li>
    );
}

function AgentCard({
    agent,
    selected,
    onSelect,
}: {
    agent: AiAgentOption;
    selected: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            role="radio"
            aria-checked={selected}
            onClick={onSelect}
            className={cn(
                'flex items-center gap-2.5 rounded-xl border p-2.5 text-left transition-colors hover:bg-muted/50 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none sm:gap-3 sm:p-3',
                selected && 'border-primary bg-muted/50 ring-1 ring-primary',
            )}
        >
            <BrandIcon group="ai-agents" src={agent.icon} size="md" />
            <span className="min-w-0">
                <span className="block text-sm leading-tight font-medium">
                    {agent.label}
                </span>
                <span className="mt-0.5 block text-xs leading-tight text-muted-foreground">
                    {kinds[agent.value]}
                </span>
            </span>
        </button>
    );
}

export default function AgentGuide({
    agents,
    mcpUrl,
}: {
    agents: AiAgentOption[];
    mcpUrl: string;
}) {
    const [agent, setAgent] = useState(agents[0]?.value ?? other.value);
    const [mode, setMode] = useState<AuthMode>('sign-in');
    const all = [...agents, other];
    const chosen = all.find((option) => option.value === agent) ?? other;
    const shownMode = chosen.api_key ? mode : 'sign-in';

    return (
        <div className="space-y-6">
            <div
                role="radiogroup"
                aria-label="Agent"
                className="grid grid-cols-2 gap-2 sm:grid-cols-3"
            >
                {all.map((option) => (
                    <AgentCard
                        key={option.value}
                        agent={option}
                        selected={option.value === chosen.value}
                        onSelect={() => setAgent(option.value)}
                    />
                ))}
            </div>

            <div className="space-y-4 border-t pt-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <BrandIcon
                            group="ai-agents"
                            src={chosen.icon}
                            size="sm"
                        />
                        <div>
                            <h3 className="text-sm font-medium">
                                Set up {chosen.label}
                            </h3>
                            <p className="text-xs text-muted-foreground">
                                {shownMode === 'sign-in'
                                    ? 'Signs in with your Databasify account.'
                                    : 'Sends an API key you create on the API keys page.'}
                            </p>
                        </div>
                    </div>
                    {chosen.api_key && (
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            size="sm"
                            value={shownMode}
                            onValueChange={(value) =>
                                value && setMode(value as AuthMode)
                            }
                        >
                            <ToggleGroupItem
                                value="sign-in"
                                className="px-3 text-xs"
                            >
                                Sign in
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="api-key"
                                className="px-3 text-xs"
                            >
                                API key
                            </ToggleGroupItem>
                        </ToggleGroup>
                    )}
                </div>

                {chosen.public_url && (
                    <Alert>
                        <Globe />
                        <AlertDescription>
                            {chosen.label} connects from its own servers, so
                            this Databasify must be reachable over public HTTPS.
                        </AlertDescription>
                    </Alert>
                )}

                <ol className="space-y-4">
                    {guides[chosen.value](mcpUrl, shownMode).map(
                        (step, index) => (
                            <Step key={index} step={step} index={index} />
                        ),
                    )}
                </ol>
            </div>
        </div>
    );
}
