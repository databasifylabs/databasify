import { Form, Link } from '@inertiajs/react';
import { Globe, Pencil, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import DatabaseSettingsController from '@/actions/App/Http/Controllers/Databases/DatabaseSettingsController';
import DnsProviderController from '@/actions/App/Http/Controllers/Dns/DnsProviderController';
import SettingRow from '@/components/databases/settings/setting-row';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import type { Database, DnsZoneOption } from '@/types';

const none = 'none';

function hostnamesUnder(name: string, zone: string): [string, string][] {
    return [
        ['Primary', `${name}.${zone}`],
        ['Primary, private network', `private-${name}.${zone}`],
        ['Standbys, read-only', `replica-${name}.${zone}`],
        ['Standbys, private network', `private-replica-${name}.${zone}`],
    ];
}

function HostnameList({ rows }: { rows: [string, string][] }) {
    return (
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5">
            {rows.map(([label, host]) => (
                <div key={host} className="contents">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd className="truncate font-mono">{host}</dd>
                </div>
            ))}
        </dl>
    );
}

function ZoneForm({
    database,
    zones,
    onDone,
}: {
    database: Database;
    zones: DnsZoneOption[];
    onDone: () => void;
}) {
    const [zoneId, setZoneId] = useState(
        database.dns_zone_id === null ? none : database.dns_zone_id,
    );
    const zone = zones.find((entry) => entry.id === zoneId);

    return (
        <Form
            {...DatabaseSettingsController.domain.form({
                database: database.id,
            })}
            options={{ preserveScroll: true }}
            onSuccess={() => {
                toast.success('Updating the domain');
                onDone();
            }}
            className="space-y-4"
        >
            {({ processing, errors }) => (
                <>
                    <input
                        type="hidden"
                        name="dns_zone_id"
                        value={zoneId === none ? '' : zoneId}
                    />
                    <div className="space-y-1.5">
                        <Label htmlFor="dns_zone">Domain</Label>
                        <Select value={zoneId} onValueChange={setZoneId}>
                            <SelectTrigger id="dns_zone" className="w-72">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value={none}>
                                    None, use IP addresses
                                </SelectItem>
                                {zones.map((entry) => (
                                    <SelectItem key={entry.id} value={entry.id}>
                                        {entry.name}
                                        <span className="text-xs text-muted-foreground">
                                            {entry.provider}
                                        </span>
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.dns_zone_id} />
                    </div>
                    {zone && (
                        <div className="space-y-2 rounded-xl border bg-muted/30 p-4">
                            <div className="text-xs text-muted-foreground">
                                Databasify creates and keeps these records up to
                                date:
                            </div>
                            <HostnameList
                                rows={hostnamesUnder(
                                    database.name,
                                    zone.database_domain,
                                )}
                            />
                        </div>
                    )}
                    <div className="flex gap-2.5 rounded-xl border p-3 text-xs text-muted-foreground">
                        <ShieldCheck className="mt-0.5 size-4 shrink-0 text-green-600" />
                        <p>
                            Each node's certificate, signed by the database's
                            own CA, covers these names. The primary name always
                            points at the current primary and moves on failover.
                            Records are DNS-only, so keep any proxy off for
                            them.
                        </p>
                    </div>
                    <InputError message={errors.database} />
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="ghost" onClick={onDone}>
                            Cancel
                        </Button>
                        <Button disabled={processing}>Save domain</Button>
                    </div>
                </>
            )}
        </Form>
    );
}

export default function DomainSetting({
    database,
    zones,
    busy,
}: {
    database: Database;
    zones: DnsZoneOption[];
    busy: boolean;
}) {
    const [editing, setEditing] = useState(false);
    const hostnames = database.hostnames;

    return (
        <SettingRow
            icon={Globe}
            title="Domain"
            description="Hostnames for every node from one of your DNS domains."
            action={
                !editing &&
                zones.length > 0 && (
                    <Button
                        variant="outline"
                        disabled={busy}
                        onClick={() => setEditing(true)}
                    >
                        <Pencil />
                        {hostnames ? 'Change' : 'Pick a domain'}
                    </Button>
                )
            }
        >
            {editing ? (
                <ZoneForm
                    database={database}
                    zones={zones}
                    onDone={() => setEditing(false)}
                />
            ) : hostnames ? (
                <HostnameList
                    rows={[
                        ['Primary', hostnames.primary],
                        ['Primary, private network', hostnames.private],
                        ['Standbys, read-only', hostnames.replica],
                        [
                            'Standbys, private network',
                            hostnames.private_replica,
                        ],
                    ]}
                />
            ) : zones.length === 0 ? (
                <p className="text-muted-foreground">
                    Not set. Add a DNS account on the{' '}
                    <Link
                        href={DnsProviderController.index()}
                        className="text-primary underline underline-offset-4"
                    >
                        DNS page
                    </Link>{' '}
                    to give this database hostnames.
                </p>
            ) : (
                <p className="text-muted-foreground">
                    Not set. Clients connect by IP with the database's own CA
                    certificate.
                </p>
            )}
        </SettingRow>
    );
}
