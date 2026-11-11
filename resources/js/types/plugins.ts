export type InstalledPlugin = {
    id: string;
    name: string;
    author: string;
    community: boolean;
    version: string;
    source: string;
    icon: string | null;
    uploaded: boolean;
    enabled: boolean;
    error: string | null;
};
