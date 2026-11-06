import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { CursorLinks } from '@/types';

function PageLink({
    href,
    only,
    children,
}: {
    href: string | null;
    only: string[];
    children: ReactNode;
}) {
    if (href === null) {
        return (
            <Button variant="outline" size="sm" disabled>
                {children}
            </Button>
        );
    }

    return (
        <Button variant="outline" size="sm" asChild>
            <Link href={href} only={only} preserveScroll preserveState>
                {children}
            </Link>
        </Button>
    );
}

export default function CursorPagination({
    links,
    only,
    className,
}: {
    links: CursorLinks;
    only: string[];
    className?: string;
}) {
    if (links.prev === null && links.next === null) {
        return null;
    }

    return (
        <div className={cn('flex items-center justify-end gap-2', className)}>
            <PageLink href={links.prev} only={only}>
                <ChevronLeft /> Previous
            </PageLink>
            <PageLink href={links.next} only={only}>
                Next <ChevronRight />
            </PageLink>
        </div>
    );
}
