import AgentGuide from '@/components/connected-apps/agent-guide';
import CopyButton from '@/components/copy-button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { AiAgentOption } from '@/types';

export default function ConnectAgentSheet({
    open,
    onOpenChange,
    agents,
    mcpUrl,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    agents: AiAgentOption[];
    mcpUrl: string;
}) {
    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                className="w-full gap-0 sm:max-w-xl"
                onOpenAutoFocus={(event) => event.preventDefault()}
            >
                <SheetHeader>
                    <SheetTitle>Connect an agent</SheetTitle>
                    <SheetDescription>
                        Pick your agent and follow its steps. You choose what it
                        may do when it signs in.
                    </SheetDescription>
                </SheetHeader>

                <div className="min-h-0 flex-1 space-y-6 overflow-y-auto px-4 pb-6">
                    <div className="flex items-center gap-2 rounded-lg border bg-muted/40 py-1 pr-1 pl-3">
                        <span className="shrink-0 text-xs text-muted-foreground">
                            MCP URL
                        </span>
                        <code className="min-w-0 flex-1 truncate font-mono text-xs">
                            {mcpUrl}
                        </code>
                        <CopyButton value={mcpUrl} label="Copy MCP URL" />
                    </div>

                    <AgentGuide agents={agents} mcpUrl={mcpUrl} />
                </div>
            </SheetContent>
        </Sheet>
    );
}
