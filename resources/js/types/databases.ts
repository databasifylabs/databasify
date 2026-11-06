import type { PointInTimeRecovery } from './storage';
import type { NetworkSummary } from './servers';

export type DatabaseNode = {
    id: string;
    server_id: string;
    name: string;
    server_name: string;
    public_ip: string;
    private_ip: string;
    kind: 'member' | 'read_only';
    role: 'primary' | 'replica' | 'offline' | 'unknown';
    is_seed: boolean;
    private_domain?: string | null;
    engine_version: string | null;
    domain: string | null;
    cpus: number | null;
    memory_mb: number | null;
    disk_gb: number | null;
    created_at: string | null;
};

export type Endpoint = {
    host: string;
    port: number;
};

export type EndpointPair = {
    public: Endpoint;
    vpc: Endpoint;
};

export type DatabaseEndpoints = EndpointPair & {
    standby: EndpointPair | null;
};

export type DatabaseUser = {
    id: string;
    name: string;
    password: string;
    password_encryption: string | null;
    is_default: boolean;
};

export type PasswordEncryptionOption = {
    value: string;
    label: string;
};

export type Schema = {
    id: string;
    name: string;
    is_default: boolean;
};

export type TrustedSource = {
    id: string;
    cidr: string;
    label: string | null;
};

export type DatabaseMigration = {
    id: string;
    mode: 'continuous' | 'one_time';
    status: 'copying' | 'syncing' | 'completed' | 'stopped' | 'failed';
    source: string;
    detail: string | null;
    lag_seconds: number | null;
    started_at: string | null;
    finished_at: string | null;
    cleanup_failed: boolean;
};

export type SourceReport = {
    reachable: boolean;
    continuous: boolean;
    blockers: string[];
    problems: string[];
    warnings: string[];
    facts: Record<string, string>;
    error: string | null;
};

export type ConfigField = {
    key: string;
    label: string;
    description: string;
    default: string;
    options: string[];
    multiple: boolean;
    unit: string | null;
    min: number | null;
    max: number | null;
    apply: 'dynamic' | 'restart';
    tuned: boolean;
};

export type DatabaseAlert = {
    id: string;
    node_id: string;
    node_name: string;
    metric: 'cpu_percent' | 'memory_percent' | 'disk_percent';
    label: string;
    opened_at: string;
};

export type ConnectionService = {
    uri_scheme: string;
    uri_query: string;
    uri_port: false;
    command: string;
    env_note: string;
    standby_uri_query: string;
    standby_command: string;
};

export type ConnectionProfile = {
    uri_scheme: string;
    uri_query: string;
    uri_port?: boolean;
    service: ConnectionService | null;
    tls_parameter: string;
    tls_mode: string;
    command: string;
    fixed_username: string | null;
    selects_database: boolean;
    env_keys: {
        host: string;
        port: string;
        username: string | null;
        password: string;
        database: string | null;
    };
    env_note: string | null;
    command_note: string | null;
};

export type MigrationSourceForm = {
    default_port: number;
    credentials_required: boolean;
    selects_databases: boolean;
};

export type ConnectionPool = {
    id: string;
    name: string;
    schema: string;
    mode: 'transaction' | 'session' | 'statement';
    size: number;
};

export type MaintenanceWindow = {
    day: number;
    hour: number;
    next_start_at: string;
};

export type Database = {
    id: string;
    name: string;
    engine: string;
    engine_label: string;
    engine_icon: string | null;
    mode: 'single' | 'ha';
    status: string;
    dns_zone_id: string | null;
    hostnames: {
        primary: string;
        private: string;
        replica: string;
        private_replica: string;
    } | null;
    operation: string | null;
    network: NetworkSummary | null;
    version_line: string;
    version_label: string;
    engine_version: string | null;
    available_version: string | null;
    available_update_refreshes_image: boolean;
    retune_needed: boolean;
    maintenance: MaintenanceWindow;
    created_at: string | null;
    domain: string | null;
    ca_certificate: string | null;
    port: number;
    pool_port: number | null;
    pools: ConnectionPool[];
    supports_users: boolean;
    password_encryptions: PasswordEncryptionOption[];
    connection: ConnectionProfile;
    migration_source: MigrationSourceForm;
    failover_hint: string;
    automatic_failover: boolean;
    supports_point_in_time_recovery: boolean;
    point_in_time_recovery_unavailable: string | null;
    point_in_time_recovery: PointInTimeRecovery | null;
    restored_from: { database: string; target: string } | null;
    offers_persistence: boolean;
    persistence: 'snapshots' | 'off';
    services: string[];
    config: Record<string, string>;
    tuned_config: Record<string, string>;
    config_fields: ConfigField[];
    endpoints: DatabaseEndpoints;
    max_standbys: number;
    max_read_only_nodes: number;
    users: DatabaseUser[];
    schemas: Schema[];
    trusted_sources: TrustedSource[];
    alerts: DatabaseAlert[];
    migrations: DatabaseMigration[];
    outbound_ips: string[];
    nodes: DatabaseNode[];
};

export type DatabaseSummary = Pick<
    Database,
    | 'id'
    | 'name'
    | 'engine_label'
    | 'engine_icon'
    | 'engine_version'
    | 'available_version'
    | 'available_update_refreshes_image'
    | 'mode'
    | 'status'
    | 'operation'
    | 'created_at'
    | 'point_in_time_recovery'
    | 'nodes'
>;

export type MetricName =
    | 'cpu_percent'
    | 'load_1m'
    | 'memory_percent'
    | 'disk_percent'
    | 'connections'
    | 'ops_per_second';

export type MetricPeriod = '1h' | '6h' | '24h' | '7d';

export type MetricHistory = {
    period: MetricPeriod;
    bucket_seconds: number;
    nodes: { id: string; name: string }[];
    points: {
        t: string;
        values: Record<string, Partial<Record<MetricName, number | null>>>;
    }[];
};

export type DnsZoneOption = {
    id: string;
    name: string;
    database_domain: string;
    provider: string;
};
