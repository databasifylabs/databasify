import type { ConnectionProfile } from '@/types';

export type ConnectionFormat = 'parameters' | 'uri' | 'command' | 'env';

export type ConnectionValues = {
    host: string;
    port: number;
    username: string;
    password: string;
    database: string;
};

export type ParameterRow = {
    label: string;
    value: string;
    secret: boolean;
};

export type ConnectionContext = {
    profile: ConnectionProfile;
    values: ConnectionValues;
    appEnv: Record<string, string>;
    caFile: string;
};

export const MASK = '••••••••••••••••';

export function parameterRowsOf({
    profile,
    values,
}: ConnectionContext): ParameterRow[] {
    const discovers = profile.uri_port === false;

    return [
        { label: 'username', value: values.username, secret: false },
        { label: 'password', value: values.password, secret: true },
        {
            label: 'host',
            value: discovers
                ? `${profile.uri_scheme}://${values.host}`
                : values.host,
            secret: false,
        },
        ...(discovers
            ? []
            : [{ label: 'port', value: String(values.port), secret: false }]),
        ...(profile.selects_database
            ? [{ label: 'database', value: values.database, secret: false }]
            : []),
        {
            label: profile.tls_parameter,
            value: profile.tls_mode,
            secret: false,
        },
    ];
}

function uriOf({ profile, values }: ConnectionContext, secret: string): string {
    const path =
        profile.selects_database && values.database
            ? `/${values.database}`
            : '';

    const port = profile.uri_port === false ? '' : `:${values.port}`;

    return `${profile.uri_scheme}://${values.username}:${secret}@${values.host}${port}${path}${profile.uri_query}`;
}

function commandOf(
    { profile, values, caFile }: ConnectionContext,
    secret: string,
): string {
    const parts: Record<string, string> = {
        host: values.host,
        port: String(values.port),
        username: values.username,
        password: secret,
        database: values.database,
        ca: caFile,
    };

    return profile.command.replace(
        /\{(\w+)\}/g,
        (match, key: string) => parts[key] ?? match,
    );
}

function envOf(
    { profile, values, appEnv }: ConnectionContext,
    secret: string,
): string {
    const keys = profile.env_keys;
    const overrides: Record<string, string> = {
        [keys.host]: values.host,
        [keys.port]: String(values.port),
        [keys.password]: secret,
        ...(keys.username ? { [keys.username]: values.username } : {}),
        ...(keys.database ? { [keys.database]: values.database } : {}),
    };

    return Object.entries(appEnv)
        .map(([key, value]) => {
            if (key in overrides) {
                return `${key}=${overrides[key]}`;
            }

            return `${key}=${value}`;
        })
        .join('\n');
}

export function connectionText(
    format: ConnectionFormat,
    context: ConnectionContext,
    revealed: boolean,
): string {
    const secret = revealed ? context.values.password : MASK;

    if (format === 'parameters') {
        return parameterRowsOf(context)
            .map((row) => `${row.label} = ${row.secret ? secret : row.value}`)
            .join('\n');
    }

    if (format === 'uri') {
        return uriOf(context, secret);
    }

    if (format === 'command') {
        return commandOf(context, secret);
    }

    return envOf(context, secret);
}

export function noteOf(
    format: ConnectionFormat,
    { profile, caFile }: ConnectionContext,
): string | null {
    const note =
        format === 'env'
            ? profile.env_note
            : format === 'command'
              ? profile.command_note
              : null;

    return note ? note.replace('{ca}', caFile) : null;
}
