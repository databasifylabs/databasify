import { Cloud, Database, Globe, Puzzle, Server } from 'lucide-react';
import BrandMark from '@/components/brand-mark';
import type { MarkSize } from '@/components/brand-mark';

const fallbacks = {
    'server-providers': Cloud,
    'dns-providers': Globe,
    'database-providers': Database,
    os: Server,
    plugins: Puzzle,
};

export type IconGroup = keyof typeof fallbacks;

export default function BrandIcon({
    group,
    src,
    size = 'md',
    className,
}: {
    group: IconGroup;
    src: string | null | undefined;
    size?: MarkSize;
    className?: string;
}) {
    return (
        <BrandMark
            src={src}
            icon={fallbacks[group]}
            size={size}
            className={className}
        />
    );
}
