export type AccessLevel = 'none' | 'read' | 'write';

export type ApiAreaOption = {
    value: string;
    label: string;
    read_only: boolean;
};

export type ApiKey = {
    id: string;
    name: string;
    levels: Record<string, AccessLevel>;
    all_projects: boolean;
    projects: { id: string; name: string }[];
    expires_at: string | null;
    last_used_at: string | null;
    expired: boolean;
};

export type CreatedApiKeyFlash = {
    name: string;
    key: string;
};
