import { X } from 'lucide-react';
import CopyButton from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import type { CreatedApiKeyFlash } from '@/types';

export default function NewApiKeyBanner({
    created,
    onDismiss,
}: {
    created: CreatedApiKeyFlash;
    onDismiss: () => void;
}) {
    return (
        <div className="space-y-2 rounded-xl border border-emerald-500/40 bg-emerald-500/10 p-4">
            <div className="flex items-start justify-between gap-4">
                <div>
                    <p className="text-sm font-medium">
                        {created.name} is ready
                    </p>
                    <p className="text-xs text-muted-foreground">
                        Copy it now. You won't see this key again.
                    </p>
                </div>
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    aria-label="Dismiss"
                    onClick={onDismiss}
                >
                    <X />
                </Button>
            </div>
            <div className="flex min-w-0 items-start gap-1">
                <code className="min-w-0 flex-1 rounded bg-background px-2 py-1.5 font-mono text-xs break-all">
                    {created.key}
                </code>
                <CopyButton value={created.key} label="Copy key" />
            </div>
        </div>
    );
}
