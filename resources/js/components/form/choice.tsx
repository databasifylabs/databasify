import { Check } from 'lucide-react';
import type { KeyboardEvent, ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function FormSection({
    title,
    description,
    aside,
    children,
}: {
    title: string;
    description?: string;
    aside?: ReactNode;
    children: ReactNode;
}) {
    return (
        <section className="space-y-4 rounded-2xl border bg-card p-5 shadow-xs sm:p-6">
            <div className="flex items-start justify-between gap-4">
                <div className="space-y-1">
                    <h2 className="text-lg font-semibold tracking-tight">
                        {title}
                    </h2>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
                {aside}
            </div>
            {children}
        </section>
    );
}

export function Radio({ selected }: { selected: boolean }) {
    return (
        <span
            className={cn(
                'flex size-5 shrink-0 items-center justify-center rounded-full border-2 transition',
                selected ? 'border-primary' : 'border-muted-foreground/30',
            )}
        >
            {selected && <span className="size-2.5 rounded-full bg-primary" />}
        </span>
    );
}

export function CheckMark({ selected }: { selected: boolean }) {
    return (
        <span
            className={cn(
                'flex size-5 shrink-0 items-center justify-center rounded-md border-2 transition',
                selected
                    ? 'border-primary bg-primary text-primary-foreground'
                    : 'border-muted-foreground/30',
            )}
        >
            {selected && <Check className="size-3" strokeWidth={3} />}
        </span>
    );
}

export function choiceRowClass(selected: boolean): string {
    return cn(
        'flex w-full cursor-pointer items-center gap-3.5 rounded-xl border bg-background px-4 py-3 text-left transition',
        selected
            ? 'border-primary shadow-sm ring-1 ring-primary'
            : 'hover:border-foreground/25 hover:bg-muted/30',
    );
}

export function ChoiceRow({
    selected,
    onSelect,
    children,
    className,
}: {
    selected: boolean;
    onSelect: () => void;
    children: ReactNode;
    className?: string;
}) {
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (event.key === ' ' || event.key === 'Enter') {
            event.preventDefault();
            onSelect();
        }
    };

    return (
        <div
            role="radio"
            aria-checked={selected}
            tabIndex={0}
            onClick={onSelect}
            onKeyDown={onKeyDown}
            className={cn(choiceRowClass(selected), className)}
        >
            <Radio selected={selected} />
            {children}
        </div>
    );
}
