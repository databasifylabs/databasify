import { ExternalLink, KeyRound } from 'lucide-react';
import type { ReactNode } from 'react';

const MARKUP = /(\[[^\]]+\]\(https?:\/\/[^)\s]+\)|`[^`]+`)/;

function render(step: string): ReactNode[] {
    return step.split(MARKUP).map((part, index) => {
        const link = part.match(/^\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)$/);

        if (link) {
            return (
                <a
                    key={index}
                    href={link[2]}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 font-medium underline underline-offset-4"
                >
                    {link[1]}
                    <ExternalLink className="size-3" />
                </a>
            );
        }

        if (part.startsWith('`') && part.endsWith('`') && part.length > 1) {
            return (
                <code
                    key={index}
                    className="mx-0.5 inline-block rounded-md bg-background px-1.5 py-0.5 text-xs ring-1 ring-border"
                >
                    {part.slice(1, -1)}
                </code>
            );
        }

        return part;
    });
}

export default function Instructions({
    title,
    steps,
}: {
    title: string;
    steps: string[];
}) {
    return (
        <div className="space-y-4 rounded-xl border bg-muted/30 p-4">
            <div className="flex items-center gap-2 font-medium">
                <KeyRound className="size-4" />
                {title}
            </div>
            <ol className="space-y-3 text-sm">
                {steps.map((step, index) => (
                    <li key={index} className="flex gap-3">
                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-background text-xs font-semibold ring-1 ring-border">
                            {index + 1}
                        </span>
                        <div className="min-w-0 flex-1 pt-0.5 leading-relaxed">
                            {render(step)}
                        </div>
                    </li>
                ))}
            </ol>
        </div>
    );
}
