import { Link } from '@inertiajs/react';
import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export default function StatCard({
    icon: Icon,
    label,
    value,
    detail,
    href,
    spin = false,
}: {
    icon: LucideIcon;
    label: string;
    value: number;
    detail: string;
    href?: NonNullable<InertiaLinkProps['href']>;
    spin?: boolean;
}) {
    const card = (
        <Card className="h-full gap-0 py-4 transition hover:border-foreground/20">
            <CardContent className="flex items-start justify-between gap-3 px-4">
                <div className="space-y-1">
                    <p className="text-sm text-muted-foreground">{label}</p>
                    <p className="text-3xl font-semibold tabular-nums">
                        {value}
                    </p>
                    <p className="text-xs text-muted-foreground">{detail}</p>
                </div>
                <div className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-muted">
                    <Icon
                        className={cn(
                            'size-5 text-muted-foreground',
                            spin && 'animate-spin',
                        )}
                    />
                </div>
            </CardContent>
        </Card>
    );

    return href ? <Link href={href}>{card}</Link> : card;
}
