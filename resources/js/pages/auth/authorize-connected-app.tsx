import { Form, Head, setLayoutProps } from '@inertiajs/react';
import AuthorizeConnectedAppController from '@/actions/App/Http/Controllers/ConnectedApps/AuthorizeConnectedAppController';
import AccessFields from '@/components/api-keys/access-fields';
import BrandIcon from '@/components/brand-icon';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import type { AccessAreaOption } from '@/types';

export default function AuthorizeConnectedApp({
    client,
    areas,
    projects,
    authToken,
}: {
    client: {
        id: string;
        name: string;
        icon: string | null;
        redirect_host: string;
    };
    areas: AccessAreaOption[];
    projects: { id: string; name: string }[];
    authToken: string;
}) {
    setLayoutProps({
        title: `Connect ${client.name}`,
        description: `${client.name} wants to use Databasify. Pick what it may do, then approve.`,
    });

    return (
        <>
            <Head title={`Connect ${client.name}`} />

            <div className="flex items-center gap-3 rounded-lg border bg-muted/40 p-3">
                <BrandIcon group="ai-agents" src={client.icon} size="md" />
                <div className="min-w-0">
                    <div className="truncate text-sm font-medium">
                        {client.name}
                    </div>
                    <div className="text-xs text-muted-foreground">
                        Returns to{' '}
                        <span className="font-medium text-foreground">
                            {client.redirect_host}
                        </span>{' '}
                        after you approve
                    </div>
                </div>
            </div>

            <Form
                {...AuthorizeConnectedAppController.approve.form()}
                className="space-y-6"
            >
                {({ processing, errors }) => (
                    <>
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <AccessFields
                            areas={areas}
                            projects={projects}
                            errors={errors}
                        />
                        <Button
                            className="w-full"
                            disabled={processing}
                            data-test="approve-connected-app"
                        >
                            {processing && <Spinner />}
                            Approve
                        </Button>
                    </>
                )}
            </Form>

            <Form {...AuthorizeConnectedAppController.deny.form()}>
                {({ processing }) => (
                    <>
                        <input
                            type="hidden"
                            name="auth_token"
                            value={authToken}
                        />
                        <Button
                            variant="ghost"
                            className="-mt-4 w-full"
                            disabled={processing}
                        >
                            Deny
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}
