import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import CopyButton from '@/components/copy-button';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

const MASK = '••••••••••••';

export default function SecretValue({
    value,
    label = 'password',
    className,
}: {
    value: string;
    label?: string;
    className?: string;
}) {
    const [revealed, setRevealed] = useState(false);

    return (
        <div className={cn('flex min-w-0 items-center gap-1', className)}>
            <code className="rounded bg-muted px-2 py-1 font-mono text-xs whitespace-nowrap">
                {revealed ? value : MASK}
            </code>
            <Button
                variant="ghost"
                size="icon"
                className="size-7 shrink-0"
                aria-label={revealed ? `Hide ${label}` : `Show ${label}`}
                onClick={() => setRevealed((current) => !current)}
            >
                {revealed ? <EyeOff /> : <Eye />}
            </Button>
            <span className="shrink-0">
                <CopyButton value={value} label={`Copy ${label}`} />
            </span>
        </div>
    );
}
