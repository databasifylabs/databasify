import type { LucideIcon } from 'lucide-react';
import type { ReactNode } from 'react';

export default function SettingRow({
    icon: Icon,
    title,
    description,
    action,
    children,
}: {
    icon: LucideIcon;
    title: string;
    description?: string;
    action?: ReactNode;
    children?: ReactNode;
}) {
    return (
        <div className="grid grid-cols-1 gap-4 py-6 md:grid-cols-[minmax(0,14rem)_1fr_auto] md:items-start">
            <div className="flex items-start gap-3">
                <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                    <Icon className="size-4" />
                </span>
                <div>
                    <div className="font-medium">{title}</div>
                    {description && (
                        <p className="text-sm text-muted-foreground">
                            {description}
                        </p>
                    )}
                </div>
            </div>
            <div className="min-w-0 text-sm">{children}</div>
            {action && <div className="md:justify-self-end">{action}</div>}
        </div>
    );
}
