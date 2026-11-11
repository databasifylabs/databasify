import { Badge } from '@/components/ui/badge';

export default function CommunityBadge({ author }: { author: string | null }) {
    return (
        <Badge
            variant="outline"
            title={author ? `Built and supported by ${author}` : undefined}
        >
            Community
        </Badge>
    );
}
