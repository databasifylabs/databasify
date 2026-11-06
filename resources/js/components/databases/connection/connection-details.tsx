import { Check, Copy, Download, KeyRound } from 'lucide-react';
import { useState } from 'react';
import TabNav from '@/components/tab-nav';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Tabs } from '@/components/ui/tabs';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useClipboard } from '@/hooks/use-clipboard';
import {
    connectionText,
    MASK,
    noteOf,
    parameterRowsOf,
} from '@/lib/connection';
import type { ConnectionContext, ConnectionFormat } from '@/lib/connection';
import { downloadFile } from '@/lib/download';
import type { Database, DatabaseEndpoints } from '@/types';

type Target = 'primary' | 'standby';

type Network = 'public' | 'vpc';

const POOL_PREFIX = 'pool:';

const FORMATS: Record<ConnectionFormat, string> = {
    parameters: 'Connection parameters',
    uri: 'Connection string',
    command: 'Flags',
    env: 'Laravel .env',
};

const NETWORKS: { value: Network; label: string }[] = [
    { value: 'public', label: 'Public network' },
    { value: 'vpc', label: 'VPC network' },
];

const TARGETS: { value: Target; label: string }[] = [
    { value: 'primary', label: 'Primary node' },
    { value: 'standby', label: 'Standby nodes' },
];

const STANDBY_HINT =
    'You can connect directly to standby nodes and route read traffic to them, reducing load on the primary node.';

export default function ConnectionDetails({
    database,
    endpoints,
    appEnv,
    discovers = false,
}: {
    database: Database;
    endpoints: DatabaseEndpoints;
    appEnv: Record<string, string>;
    discovers?: boolean;
}) {
    const [target, setTarget] = useState<Target>('primary');
    const [network, setNetwork] = useState<Network>('public');
    const service =
        discovers && network === 'public' ? database.connection.service : null;
    const standbyReads =
        service && target === 'standby'
            ? {
                  uri_query: service.standby_uri_query,
                  command: service.standby_command,
              }
            : {};
    const profile = service
        ? { ...database.connection, ...service, ...standbyReads }
        : database.connection;
    const accounts = profile.fixed_username === null;
    const keys = profile.env_keys;
    const pools = database.pool_port === null ? [] : database.pools;
    const [copiedText, copy] = useClipboard(1500);
    const copied = copiedText !== null;
    const [format, setFormat] = useState<ConnectionFormat>('parameters');
    const [revealed, setRevealed] = useState(false);
    const [userName, setUserName] = useState(
        database.users.find(
            (user) => keys.username && user.name === appEnv[keys.username],
        )?.name ??
            database.users[0]?.name ??
            '',
    );
    const [databaseChoice, setDatabaseChoice] = useState(
        (keys.database ? appEnv[keys.database] : undefined) ??
            database.schemas[0]?.name ??
            '',
    );

    const standby = endpoints.standby;
    const pair =
        target === 'standby' && standby && !service ? standby : endpoints;
    const pool = databaseChoice.startsWith(POOL_PREFIX)
        ? databaseChoice.slice(POOL_PREFIX.length)
        : null;
    const caFile = `${database.name}-ca-certificate.crt`;
    const context: ConnectionContext = {
        profile,
        appEnv,
        caFile,
        values: {
            host: pair[network].host,
            port:
                pool && database.pool_port
                    ? database.pool_port
                    : pair[network].port,
            username: profile.fixed_username ?? userName,
            password: accounts
                ? (database.users.find((user) => user.name === userName)
                      ?.password ?? '')
                : (appEnv[keys.password] ?? ''),
            database: pool ?? databaseChoice,
        },
    };

    const note = noteOf(format, context);

    const copyAll = () => copy(connectionText(format, context, true));

    return (
        <Card className="gap-0 py-0">
            <CardHeader className="gap-3 pt-5 pb-4">
                <CardTitle className="flex items-center gap-2">
                    <KeyRound className="size-4 text-muted-foreground" />
                    Connection details
                </CardTitle>
                {standby && (
                    <Tabs
                        value={target}
                        onValueChange={(value) =>
                            setTarget(
                                value === 'standby' ? 'standby' : 'primary',
                            )
                        }
                    >
                        <TabNav tabs={TARGETS} />
                    </Tabs>
                )}
                {standby && target === 'standby' && (
                    <p className="text-sm leading-relaxed text-muted-foreground">
                        {STANDBY_HINT}
                    </p>
                )}
                <div className="flex flex-wrap items-center gap-2">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        value={network}
                        onValueChange={(value) =>
                            value &&
                            setNetwork(value === 'vpc' ? 'vpc' : 'public')
                        }
                    >
                        {NETWORKS.map((option) => (
                            <ToggleGroupItem
                                key={option.value}
                                value={option.value}
                            >
                                {option.label}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                    <Select
                        value={format}
                        onValueChange={(value) =>
                            setFormat(
                                (
                                    Object.keys(FORMATS) as ConnectionFormat[]
                                ).find((key) => key === value) ?? 'parameters',
                            )
                        }
                    >
                        <SelectTrigger size="sm" className="min-w-0 flex-1">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(FORMATS).map(([key, label]) => (
                                <SelectItem key={key} value={key}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
            </CardHeader>
            <CardContent className="px-6 pb-5">
                <div className="rounded-lg border">
                    <div className="px-4 py-3 font-mono text-[13px] leading-6 break-all">
                        {format === 'parameters' ? (
                            parameterRowsOf(context).map((row) => (
                                <div key={row.label}>
                                    <span className="font-semibold">
                                        {row.label}
                                    </span>
                                    {' = '}
                                    {row.secret && !revealed ? MASK : row.value}
                                    {row.secret && (
                                        <Button
                                            variant="link"
                                            size="sm"
                                            onClick={() =>
                                                setRevealed((value) => !value)
                                            }
                                            className="ml-2 h-auto p-0 font-sans"
                                        >
                                            {revealed ? 'hide' : 'show'}
                                        </Button>
                                    )}
                                </div>
                            ))
                        ) : (
                            <pre className="font-mono whitespace-pre-wrap">
                                {connectionText(format, context, revealed)}
                            </pre>
                        )}
                        {format !== 'parameters' && (
                            <Button
                                variant="link"
                                size="sm"
                                onClick={() => setRevealed((value) => !value)}
                                className="mt-1 h-auto p-0 font-sans text-xs"
                            >
                                {revealed ? 'Hide password' : 'Show password'}
                            </Button>
                        )}
                    </div>
                    {note && (
                        <p className="border-t px-4 py-2 text-xs text-muted-foreground">
                            {note}
                        </p>
                    )}
                    {(accounts || profile.selects_database) && (
                        <div className="grid grid-cols-2 gap-3 border-t px-4 py-3">
                            {accounts && (
                                <label className="space-y-1.5">
                                    <span className="font-mono text-xs font-semibold">
                                        User:
                                    </span>
                                    <Select
                                        value={userName}
                                        onValueChange={setUserName}
                                    >
                                        <SelectTrigger
                                            size="sm"
                                            className="w-full font-mono"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {database.users.map((user) => (
                                                <SelectItem
                                                    key={user.id}
                                                    value={user.name}
                                                >
                                                    {user.name}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </label>
                            )}
                            {profile.selects_database && (
                                <label className="space-y-1.5">
                                    <span className="font-mono text-xs font-semibold">
                                        {pools.length > 0
                                            ? 'Database/Pool:'
                                            : 'Database:'}
                                    </span>
                                    <Select
                                        value={databaseChoice}
                                        onValueChange={setDatabaseChoice}
                                    >
                                        <SelectTrigger
                                            size="sm"
                                            className="w-full font-mono"
                                        >
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectGroup>
                                                {pools.length > 0 && (
                                                    <SelectLabel>
                                                        Databases
                                                    </SelectLabel>
                                                )}
                                                {database.schemas.map(
                                                    (schema) => (
                                                        <SelectItem
                                                            key={schema.id}
                                                            value={schema.name}
                                                        >
                                                            {schema.name}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectGroup>
                                            {pools.length > 0 && (
                                                <SelectGroup>
                                                    <SelectLabel>
                                                        Pools
                                                    </SelectLabel>
                                                    {pools.map((entry) => (
                                                        <SelectItem
                                                            key={entry.id}
                                                            value={`${POOL_PREFIX}${entry.name}`}
                                                        >
                                                            {entry.name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectGroup>
                                            )}
                                        </SelectContent>
                                    </Select>
                                </label>
                            )}
                        </div>
                    )}
                    <div className="flex flex-wrap items-center gap-1 border-t px-2 py-1.5">
                        <Button variant="ghost" size="sm" onClick={copyAll}>
                            {copied ? <Check /> : <Copy />}
                            {copied ? 'Copied' : 'Copy'}
                        </Button>
                        {database.ca_certificate && (
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() =>
                                    downloadFile(
                                        caFile,
                                        database.ca_certificate ?? '',
                                        'application/x-pem-file',
                                    )
                                }
                            >
                                <Download />
                                Download CA certificate
                            </Button>
                        )}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}
