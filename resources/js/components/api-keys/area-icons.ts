import type { LucideIcon } from 'lucide-react';
import {
    Cloud,
    DatabaseIcon,
    FolderKanban,
    Globe,
    HardDrive,
    Server,
    Settings2,
} from 'lucide-react';
import type { AccessLevel } from '@/types';

export const areaIcons: Record<string, LucideIcon> = {
    projects: FolderKanban,
    'server-providers': Cloud,
    'dns-providers': Globe,
    'storage-providers': HardDrive,
    servers: Server,
    databases: DatabaseIcon,
    system: Settings2,
};

export const levelColors: Record<AccessLevel, string> = {
    write: 'text-emerald-500',
    read: 'text-sky-500',
    none: 'text-muted-foreground/30',
};
