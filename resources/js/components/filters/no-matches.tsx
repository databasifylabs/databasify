import { SearchX } from 'lucide-react';
import EmptyState from '@/components/empty-state';
import { Button } from '@/components/ui/button';

export default function NoMatches({
    noun,
    onClear,
}: {
    noun: string;
    onClear: () => void;
}) {
    return (
        <EmptyState
            icon={SearchX}
            title={`No ${noun} match`}
            description="Try another search term or clear the filters."
        >
            <Button variant="outline" onClick={onClear}>
                Clear filters
            </Button>
        </EmptyState>
    );
}
