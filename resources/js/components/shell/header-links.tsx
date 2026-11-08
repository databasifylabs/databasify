import type { LucideIcon } from 'lucide-react';
import { Star } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useRepositoryStars } from '@/hooks/use-repository-stars';
import { repositoryUrl } from '@/lib/repository';

function HeaderLink({
    href,
    label,
    icon: Icon,
    iconClassName,
}: {
    href: string;
    label: string;
    icon: LucideIcon;
    iconClassName?: string;
}) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <Button variant="ghost" size="icon" asChild>
                    <a
                        href={href}
                        target="_blank"
                        rel="noopener noreferrer"
                        aria-label={label}
                    >
                        <Icon className={iconClassName} />
                    </a>
                </Button>
            </TooltipTrigger>
            <TooltipContent>{label}</TooltipContent>
        </Tooltip>
    );
}

export function HeaderLinks() {
    const stars = useRepositoryStars();

    return (
        <HeaderLink
            href={repositoryUrl}
            label={stars ? `Star on GitHub (${stars})` : 'Star on GitHub'}
            icon={Star}
            iconClassName="fill-amber-400 text-amber-400"
        />
    );
}
