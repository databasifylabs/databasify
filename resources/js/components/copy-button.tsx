import { Check, Copy } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useClipboard } from '@/hooks/use-clipboard';

export default function CopyButton({
    value,
    label = 'Copy',
}: {
    value: string;
    label?: string;
}) {
    const [copiedText, copy] = useClipboard(1500);
    const copied = copiedText !== null;

    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    className="size-7"
                    onClick={() => copy(value)}
                    aria-label={label}
                >
                    {copied ? <Check className="text-green-600" /> : <Copy />}
                </Button>
            </TooltipTrigger>
            <TooltipContent>{copied ? 'Copied' : label}</TooltipContent>
        </Tooltip>
    );
}
