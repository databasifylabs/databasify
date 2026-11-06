import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export default function EmptyState({
    icon: Icon,
    title,
    description,
    children,
}: {
    icon: LucideIcon;
    title: string;
    description: string;
    children?: ReactNode;
}) {
    return (
        <div className="rounded-xl border border-dashed border-sidebar-border/70 p-8 text-center dark:border-sidebar-border">
            <div className="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-muted">
                <Icon className="size-7 text-muted-foreground" />
            </div>
            <p className="font-medium">{title}</p>
            <p className="mx-auto mt-1 max-w-md text-sm text-muted-foreground">
                {description}
            </p>
            {children && <div className="mt-4">{children}</div>}
        </div>
    );
}
