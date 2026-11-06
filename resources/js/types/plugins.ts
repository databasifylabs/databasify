export type InstalledPlugin = {
    id: string;
    name: string;
    version: string;
    source: string;
    icon: string | null;
    uploaded: boolean;
    enabled: boolean;
    error: string | null;
};
