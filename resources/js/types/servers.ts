import type { FormField } from './forms';

export type ServerStatus =
    | 'creating'
    | 'pending'
    | 'provisioning'
    | 'ready'
    | 'failed';

export type NetworkKind = 'provider' | 'custom' | 'wireguard';

export type NetworkMemberStatus =
    | 'joining'
    | 'connected'
    | 'degraded'
    | 'out_of_sync'
    | 'unreachable';

export type NetworkSummary = {
    id: string;
    name: string;
    kind: NetworkKind;
    cidr: string;
    region?: string | null;
    provider?: string | null;
    provider_label?: string | null;
    provider_type?: string | null;
    provider_icon?: string | null;
};

export type Server = {
    id: string;
    name: string;
    public_ip: string | null;
    private_ip: string | null;
    network_id: string | null;
    network_status?: NetworkMemberStatus | null;
    network_issue?: string | null;
    network_checked_at?: string | null;
    network?: NetworkSummary | null;
    provider?: ProviderOption | null;
    region: string | null;
    plan: string | null;
    ssh_port: number;
    ssh_user: string;
    status: ServerStatus;
    os: string | null;
    distribution: string | null;
    distribution_icon: string | null;
    cpus: number | null;
    memory_mb: number | null;
    disk_gb: number | null;
    host_key_fingerprint: string | null;
    created_at?: string | null;
    database?: ServerMembership | null;
};

export type ServerMembership = {
    id: string;
    name: string;
    engine: string;
    engine_icon: string | null;
    kind: 'member' | 'read_only';
    node_id: string;
};

export type ServerUser = {
    id: string;
    username: string;
};

export type ProviderType = {
    value: string;
    label: string;
    icon: string | null;
    server_label: string;
    instructions?: string[];
    form: FormField[];
};

export type ProviderOption = {
    id: string;
    name: string;
    type: ProviderType;
};

export type OperatingSystemOption = {
    value: string;
    label: string;
    version: string;
    distribution: string;
    icon: string | null;
    family: 'debian' | 'rhel';
    recommended: boolean;
    providers: string[];
};
