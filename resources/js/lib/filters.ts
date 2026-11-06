import { statusLabel } from '@/lib/status';
import type { FilterOption } from '@/types';

export function statusOptionsOf(statuses: string[]): FilterOption[] {
    return statuses.map((status) => ({
        value: status,
        label: statusLabel(status),
    }));
}
