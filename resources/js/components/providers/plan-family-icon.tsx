import type { LucideIcon } from 'lucide-react';
import {
    Box,
    Cpu,
    Gauge,
    HardDrive,
    Layers,
    MemoryStick,
    Sparkles,
} from 'lucide-react';
import { siAmd, siIntel } from 'simple-icons';
import BrandMark, { fromSimpleIcon } from '@/components/brand-mark';
import type { MarkSize } from '@/components/brand-mark';

const intel = fromSimpleIcon(siIntel);
const amd = fromSimpleIcon(siAmd);

const icons: [string, LucideIcon][] = [
    ['intensive', Gauge],
    ['frequency', Gauge],
    ['performance', Gauge],
    ['memory', MemoryStick],
    ['storage', HardDrive],
    ['dedicated', Cpu],
    ['optimized', Cpu],
    ['general', Layers],
    ['v5', Sparkles],
];

function iconOf(family: string): LucideIcon {
    const name = family.toLowerCase();

    return icons.find(([keyword]) => name.includes(keyword))?.[1] ?? Box;
}

export default function PlanFamilyIcon({
    family,
    size = 'sm',
}: {
    family: string;
    size?: MarkSize;
}) {
    const name = family.toLowerCase();
    const logo = name.includes('intel')
        ? intel
        : name.includes('amd')
          ? amd
          : null;

    return <BrandMark logo={logo} icon={iconOf(family)} size={size} />;
}
