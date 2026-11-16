export type InstalledPlugin = {
    id: string;
    key: string;
    name: string;
    author: string;
    author_url: string | null;
    community: boolean;
    version: string;
    source: string;
    icon: string | null;
    uploaded: boolean;
    enabled: boolean;
    error: string | null;
};
