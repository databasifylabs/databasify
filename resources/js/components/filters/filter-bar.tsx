import { X } from 'lucide-react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';

export default function FilterBar({
    isFiltering,
    onClear,
    children,
}: {
    isFiltering: boolean;
    onClear: () => void;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
            {children}
            {isFiltering && (
                <Button variant="ghost" size="sm" onClick={onClear}>
                    <X /> Clear filters
                </Button>
            )}
        </div>
    );
}
