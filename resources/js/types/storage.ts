export type StorageProvider = {
    id: string;
    name: string;
    endpoint: string;
    region: string;
    bucket: string;
    path_prefix: string;
    path_style: boolean;
    access_key_id: string;
    verified_at: string | null;
    databases?: { id: string; name: string }[];
};

export type StorageProviderOption = {
    id: string;
    name: string;
    bucket: string;
};

export type ArchiveHealth = 'pending' | 'healthy' | 'failing';

export type PointInTimeRecovery = {
    storage: StorageProviderOption;
    path: string;
    retention_days: number;
    backup_hour: number;
    health: ArchiveHealth;
    health_label: string;
    backups_count: number;
    earliest_restorable_at: string | null;
    latest_restorable_at: string | null;
    last_backup_at: string | null;
    problem: string | null;
    checked_at: string | null;
    enabled_at: string | null;
};
