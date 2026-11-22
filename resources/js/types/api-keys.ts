export type AccessLevel = 'none' | 'read' | 'write';

export type AccessAreaOption = {
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

export type AiAgentOption = {
    value: string;
    label: string;
    icon: string | null;
    api_key: boolean;
    public_url: boolean;
};

export type ConnectedApp = {
    id: string;
    name: string;
    agent: string | null;
    icon: string | null;
    redirect_host: string;
    levels: Record<string, AccessLevel>;
    all_projects: boolean;
    projects: { id: string; name: string }[];
    connected_at: string | null;
    last_used_at: string | null;
};
