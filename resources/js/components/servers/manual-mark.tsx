import { Server } from 'lucide-react';
import BrandMark from '@/components/brand-mark';

export default function ManualMark({ size = 'sm' }: { size?: 'xs' | 'sm' }) {
    return <BrandMark logo={null} icon={Server} size={size} />;
}
