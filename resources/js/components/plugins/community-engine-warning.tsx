import { TriangleAlert } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export default function CommunityEngineWarning({
    message,
}: {
    message?: string;
}) {
    if (!message) {
        return null;
    }

    return (
        <Alert variant="warning">
            <TriangleAlert />
            <AlertTitle>Community engine</AlertTitle>
            <AlertDescription>{message}</AlertDescription>
            <input type="hidden" name="accepts_community_engines" value="1" />
        </Alert>
    );
}
