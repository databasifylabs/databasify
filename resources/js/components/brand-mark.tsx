import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

export type Logo = {
    path: string;
    viewBox: string;
    color: string;
    fillRule?: 'evenodd';
};

export type MarkSize = 'xs' | 'sm' | 'md' | 'lg';

export const fromSimpleIcon = (icon: { path: string; hex: string }): Logo => ({
    path: icon.path,
    viewBox: '0 0 24 24',
    color: `#${icon.hex}`,
});

export default function BrandMark({
    logo,
    src,
    icon: Fallback,
    size = 'md',
    className,
}: {
    logo?: Logo | null;
    src?: string | null;
    icon?: LucideIcon;
    size?: MarkSize;
    className?: string;
}) {
    const [failed, setFailed] = useState<string | null>(null);
    const image = src && src !== failed ? src : null;

    return (
        <span
            className={cn(
                'inline-flex shrink-0 items-center justify-center rounded-xl ring-1 ring-border',
                logo || image ? 'bg-white' : 'bg-muted text-muted-foreground',
                size === 'xs' &&
                    'size-6 rounded-md [&_img]:size-3.5 [&_svg]:size-3.5',
                size === 'sm' && 'size-8 [&_img]:size-5 [&_svg]:size-5',
                size === 'md' && 'size-10 [&_img]:size-6 [&_svg]:size-6',
                size === 'lg' && 'size-14 [&_img]:size-8 [&_svg]:size-8',
                className,
            )}
        >
            {logo ? (
                <svg
                    viewBox={logo.viewBox}
                    fill={logo.color}
                    aria-hidden="true"
                >
                    <path d={logo.path} fillRule={logo.fillRule} />
                </svg>
            ) : image ? (
                <img src={image} alt="" onError={() => setFailed(image)} />
            ) : (
                Fallback && <Fallback />
            )}
        </span>
    );
}
