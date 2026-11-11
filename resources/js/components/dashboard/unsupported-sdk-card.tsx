import { Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import DatabaseController from '@/actions/App/Http/Controllers/Databases/DatabaseController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

export type UnsupportedSdkDatabase = {
    id: string;
    name: string;
    sdk: string;
};

export default function UnsupportedSdkCard({
    databases,
}: {
    databases: UnsupportedSdkDatabase[];
}) {
    return (
        <Alert variant="warning">
            <Lock />
            <AlertTitle>
                {databases.length === 1
                    ? '1 database is read-only'
                    : `${databases.length} databases are read-only`}
            </AlertTitle>
            <AlertDescription>
                <p>
                    Their engine images are built on an SDK this Databasify no
                    longer supports. They keep running, but changes wait for a
                    new image.
                </p>
                <ul className="list-inside list-disc">
                    {databases.map((database) => (
                        <li key={database.id}>
                            <Link
                                href={DatabaseController.show({
                                    database: database.id,
                                })}
                                className="underline underline-offset-4"
                            >
                                {database.name}
                            </Link>{' '}
                            runs SDK {database.sdk}
                        </li>
                    ))}
                </ul>
            </AlertDescription>
        </Alert>
    );
}
