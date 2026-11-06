import {
    AU,
    BR,
    CA,
    CH,
    CL,
    DE,
    ES,
    FI,
    FR,
    GB,
    ID,
    IE,
    IL,
    IN,
    IT,
    JP,
    KR,
    MX,
    NL,
    NO,
    PL,
    SE,
    SG,
    US,
    ZA,
} from 'country-flag-icons/react/3x2';
import { Globe } from 'lucide-react';
import { cn } from '@/lib/utils';

const flags: Record<string, typeof US> = {
    AU,
    BR,
    CA,
    CH,
    CL,
    DE,
    ES,
    FI,
    FR,
    GB,
    ID,
    IE,
    IL,
    IN,
    IT,
    JP,
    KR,
    MX,
    NL,
    NO,
    PL,
    SE,
    SG,
    US,
    ZA,
};

export default function RegionFlag({
    country,
    className,
}: {
    country: string | null;
    className?: string;
}) {
    const Flag = country ? flags[country] : undefined;

    if (!Flag) {
        return (
            <Globe
                className={cn(
                    'h-4 w-6 shrink-0 text-muted-foreground',
                    className,
                )}
            />
        );
    }

    return (
        <Flag
            className={cn(
                'h-4 w-6 shrink-0 rounded-[3px] ring-1 ring-border',
                className,
            )}
            aria-hidden="true"
        />
    );
}
