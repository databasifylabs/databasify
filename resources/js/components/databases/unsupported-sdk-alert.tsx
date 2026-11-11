import { Lock } from 'lucide-react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export type UnsupportedSdk = {
    sdk: string;
    range: string;
    author: string;
};

export default function UnsupportedSdkAlert({
    name,
    unsupported,
}: {
    name: string;
    unsupported: UnsupportedSdk;
}) {
    return (
        <Alert variant="warning">
            <Lock />
            <AlertTitle>Read-only until a new engine image is out</AlertTitle>
            <AlertDescription>
                <p>
                    {name}'s engine image is built on SDK {unsupported.sdk}, and
                    this Databasify supports {unsupported.range}. It keeps
                    running, and backups, failover and delete still work, but
                    changes are blocked until {unsupported.author} publishes a
                    new image. The next maintenance window moves {name} to it.
                </p>
            </AlertDescription>
        </Alert>
    );
}
