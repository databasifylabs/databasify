import { Head, Link } from '@inertiajs/react';
import { Globe, Plus, RefreshCw } from 'lucide-react';
import DnsProviderController from '@/actions/App/Http/Controllers/Dns/DnsProviderController';
import ConfirmDialog from '@/components/confirm-dialog';
import EditAccountDialog from '@/components/providers/edit-account-dialog';
import DeleteButton from '@/components/delete-button';
import BrandIcon from '@/components/brand-icon';
import EmptyState from '@/components/empty-state';
import MoveToProjectButton from '@/components/projects/move-to-project-button';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { pluralize } from '@/lib/format';
import { runAction } from '@/lib/run-action';
import type { DnsAccount } from '@/types';

function Account({ account }: { account: DnsAccount }) {
    const used = account.zones.some((zone) => zone.databases_count > 0);

    return (
        <div className="space-y-4 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <BrandIcon
                        group="dns-providers"
                        src={account.type.icon}
                        size="sm"
                    />
                    <div>
                        <div className="font-medium">{account.name}</div>
                        <div className="text-xs text-muted-foreground">
                            {account.type.label}
                        </div>
                    </div>
                </div>
                <div className="flex items-center gap-1">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            runAction(
                                DnsProviderController.refresh({
                                    provider: account.id,
                                }),
                                {
                                    success: `Refreshed ${account.name}`,
                                },
                            )
                        }
                    >
                        <RefreshCw /> Refresh domains
                    </Button>
                    <MoveToProjectButton
                        resource={{
                            type: 'dns-provider',
                            id: account.id,
                            name: account.name,
                        }}
                        description="Its domains move with it. Databases that use them keep their hostnames."
                    />
                    <EditAccountDialog
                        name={account.name}
                        form={DnsProviderController.update.form({
                            provider: account.id,
                        })}
                        secrets={account.type.form
                            .filter((field) => field.type !== 'select')
                            .map((field) => ({
                                name: `credentials[${field.name}]`,
                                label: field.label,
                                secret: field.type === 'password',
                            }))}
                        description="New credentials are checked before saving, must still see every domain a database uses, and the domains are imported again."
                    />
                    <ConfirmDialog
                        trigger={
                            <DeleteButton
                                aria-label={`Remove ${account.name}`}
                            />
                        }
                        title={`Remove ${account.name}?`}
                        description={
                            used
                                ? 'A database still uses one of its domains. Pick another domain for it first.'
                                : 'Databasify forgets this account and its token. Nothing changes in your DNS.'
                        }
                        confirmLabel="Remove account"
                        destructive
                        onConfirm={() =>
                            runAction(
                                DnsProviderController.destroy({
                                    provider: account.id,
                                }),
                                {
                                    success: `Removed ${account.name}`,
                                },
                            )
                        }
                    />
                </div>
            </div>
            {account.zones.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    No domains found. Give the token access to a zone, then
                    refresh.
                </p>
            ) : (
                <div className="flex flex-wrap gap-2">
                    {account.zones.map((zone) => (
                        <Badge
                            key={zone.id}
                            variant="secondary"
                            className="gap-1.5 font-mono"
                        >
                            {zone.name}
                            {zone.databases_count > 0 && (
                                <span className="font-sans text-muted-foreground">
                                    ·{' '}
                                    {pluralize(
                                        zone.databases_count,
                                        'database',
                                    )}
                                </span>
                            )}
                        </Badge>
                    ))}
                </div>
            )}
        </div>
    );
}

export default function DnsIndex({ providers }: { providers: DnsAccount[] }) {
    return (
        <>
            <Head title="DNS" />
            <div className="mx-auto w-full max-w-6xl space-y-8 p-4 md:p-6">
                <div className="flex flex-wrap items-end justify-between gap-4">
                    <Heading
                        title="DNS"
                        description="Domains Databasify can create database hostnames in."
                    />
                    <Button asChild>
                        <Link href={DnsProviderController.create()}>
                            <Plus /> Add DNS account
                        </Link>
                    </Button>
                </div>

                {providers.length === 0 ? (
                    <EmptyState
                        icon={Globe}
                        title="No DNS accounts yet"
                        description="Add an account once, then pick one of its domains on any database to get app-db.example.com style hostnames."
                    >
                        <Button asChild>
                            <Link href={DnsProviderController.create()}>
                                <Plus /> Add your first DNS account
                            </Link>
                        </Button>
                    </EmptyState>
                ) : (
                    <div className="space-y-3">
                        {providers.map((account) => (
                            <Account key={account.id} account={account} />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

DnsIndex.layout = () => ({
    breadcrumbs: [{ title: 'DNS', href: DnsProviderController.index() }],
});
