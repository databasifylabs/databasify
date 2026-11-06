import { useState } from 'react';

export function useExpandedDatabases() {
    const [expandedIds, setExpandedIds] = useState<ReadonlySet<string>>(
        new Set(),
    );

    const isOpen = (id: string) => expandedIds.has(id);

    const toggle = (id: string) =>
        setExpandedIds((current) => {
            const next = new Set(current);

            if (!next.delete(id)) {
                next.add(id);
            }

            return next;
        });

    return { isOpen, toggle };
}
