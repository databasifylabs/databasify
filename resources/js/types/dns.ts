import type { FormField } from './forms';

export type DnsProviderType = {
    value: string;
    label: string;
    icon: string | null;
    instructions?: string[];
    form: FormField[];
};

export type DnsZone = {
    id: string;
    name: string;
    databases_count: number;
};

export type DnsAccount = {
    id: string;
    name: string;
    type: DnsProviderType;
    zones: DnsZone[];
};
