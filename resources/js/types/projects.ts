export type Project = {
    id: string;
    name: string;
};

export type ProjectUsage = Project & {
    servers_count: number;
    databases_count: number;
    networks_count: number;
    server_providers_count: number;
    dns_providers_count: number;
    storage_providers_count: number;
};

export type MovableResourceType =
    | 'server'
    | 'database'
    | 'network'
    | 'server-provider'
    | 'dns-provider'
    | 'storage-provider';
