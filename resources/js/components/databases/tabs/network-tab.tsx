import { Form } from '@inertiajs/react';
import {
    Globe,
    MapPin,
    Network,
    Plus,
    ShieldAlert,
    ShieldCheck,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import TrustedSourceController from '@/actions/App/Http/Controllers/Databases/TrustedSourceController';
import ConfirmDialog from '@/components/confirm-dialog';
import DeleteButton from '@/components/delete-button';
import { runAction } from '@/lib/run-action';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { Database } from '@/types';

const privateAddress =
    /^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[01])\.|169\.254\.)/;

function AddSourceForm({
    database,
    clientIp,
    busy,
}: {
    database: Database;
    clientIp: string | null;
    busy: boolean;
}) {
    const [cidr, setCidr] = useState('');
    const [label, setLabel] = useState('');
    const myCidr =
        clientIp && !privateAddress.test(clientIp) ? `${clientIp}/32` : null;
    const alreadyMine = database.trusted_sources.some(
        (source) => source.cidr === myCidr,
    );

    return (
        <Form
            {...TrustedSourceController.store.form({ database: database.id })}
            options={{ preserveScroll: true }}
            onSuccess={() => {
                toast.success(`Allowing ${cidr}`);
                setCidr('');
                setLabel('');
            }}
            className="grid grid-cols-1 gap-3 md:grid-cols-[1fr_1fr_auto] md:items-end"
        >
            {({ processing, errors }) => (
                <>
                    <div className="space-y-1.5">
                        <Label htmlFor="cidr">IP address or range</Label>
                        <Input
                            id="cidr"
                            name="cidr"
                            value={cidr}
                            onChange={(event) => setCidr(event.target.value)}
                            placeholder="203.0.113.10 or 10.0.0.0/16"
                            autoComplete="off"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="label">Label</Label>
                        <Input
                            id="label"
                            name="label"
                            value={label}
                            onChange={(event) => setLabel(event.target.value)}
                            placeholder="App servers"
                            autoComplete="off"
                        />
                    </div>
                    <Button disabled={processing || busy || !cidr}>
                        <Plus />
                        Add source
                    </Button>
                    <div className="md:col-span-3">
                        <InputError
                            message={
                                errors.cidr ?? errors.label ?? errors.database
                            }
                        />
                        {myCidr && !alreadyMine && (
                            <button
                                type="button"
                                className="inline-flex items-center gap-1.5 text-sm text-primary hover:underline"
                                onClick={() => {
                                    setCidr(myCidr);
                                    setLabel((value) => value || 'My IP');
                                }}
                            >
                                <MapPin className="size-3.5" />
                                Use my current IP ({clientIp})
                            </button>
                        )}
                    </div>
                </>
            )}
        </Form>
    );
}

export default function NetworkTab({
    database,
    clientIp,
    busy,
}: {
    database: Database;
    clientIp: string | null;
    busy: boolean;
}) {
    const sources = database.trusted_sources;
    const restricted = sources.length > 0;

    return (
        <div className="space-y-6">
            {restricted ? (
                <Alert variant="success">
                    <ShieldCheck />
                    <AlertTitle>Access is restricted</AlertTitle>
                    <AlertDescription>
                        Only the sources below, the database's own nodes and
                        their private network can connect to the database.
                    </AlertDescription>
                </Alert>
            ) : (
                <div className="flex flex-col items-center gap-4 rounded-xl border border-dashed px-6 py-10 text-center">
                    <span className="flex size-14 items-center justify-center rounded-2xl bg-muted text-muted-foreground">
                        <ShieldAlert className="size-6" />
                    </span>
                    <div className="space-y-1">
                        <h3 className="font-semibold">
                            Secure this database by restricting access
                        </h3>
                        <p className="mx-auto max-w-lg text-sm text-muted-foreground">
                            Right now any IP address can try to connect. A
                            password and TLS are still required, but adding
                            trusted sources closes the port to everyone else.
                        </p>
                    </div>
                </div>
            )}

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2">
                        <Globe className="size-4 text-muted-foreground" />
                        Trusted sources
                    </CardTitle>
                    <CardDescription>
                        IPv4 addresses or CIDR ranges allowed to connect.
                        Overlapping ranges are merged automatically.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-6">
                    {restricted && (
                        <ul className="divide-y rounded-lg border">
                            {sources.map((source) => (
                                <li
                                    key={source.id}
                                    className="flex items-center gap-3 px-4 py-3"
                                >
                                    <span className="flex size-8 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                        <Network className="size-4" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="font-mono text-sm">
                                            {source.cidr}
                                        </div>
                                        {source.label && (
                                            <div className="text-xs text-muted-foreground">
                                                {source.label}
                                            </div>
                                        )}
                                    </div>
                                    <ConfirmDialog
                                        trigger={
                                            <DeleteButton
                                                disabled={busy}
                                                aria-label={`Remove ${source.cidr}`}
                                            />
                                        }
                                        title={`Remove ${source.cidr}?`}
                                        description={
                                            sources.length === 1
                                                ? 'This is the last trusted source. Removing it opens the database to every IP address again.'
                                                : 'Connections from this range are refused from now on.'
                                        }
                                        confirmLabel="Remove source"
                                        destructive
                                        onConfirm={() =>
                                            runAction(
                                                TrustedSourceController.destroy(
                                                    {
                                                        database: database.id,
                                                        trustedSource:
                                                            source.id,
                                                    },
                                                ),
                                                {
                                                    success: `Removing ${source.cidr}`,
                                                },
                                            )
                                        }
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                    <AddSourceForm
                        database={database}
                        clientIp={clientIp}
                        busy={busy}
                    />
                </CardContent>
            </Card>
        </div>
    );
}
