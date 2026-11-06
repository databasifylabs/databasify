import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { ListFilters } from '@/types';

const DEBOUNCE_MS = 300;

function queryOf(filters: ListFilters): Record<string, string> {
    return Object.fromEntries(
        Object.entries(filters).filter(
            (entry): entry is [string, string] =>
                entry[1] !== null && entry[1] !== '',
        ),
    );
}

export function useListFilters<T extends ListFilters>(
    initial: T,
    only: string[],
) {
    const [filters, setFilters] = useState<T>(initial);
    const query = JSON.stringify(queryOf(filters));
    const applied = useRef(query);
    const reloaded = useRef(only);

    useEffect(() => {
        if (query === applied.current) {
            return;
        }

        const timer = window.setTimeout(() => {
            applied.current = query;
            router.get(window.location.pathname, JSON.parse(query), {
                only: reloaded.current,
                preserveState: true,
                preserveScroll: true,
                replace: true,
            });
        }, DEBOUNCE_MS);

        return () => window.clearTimeout(timer);
    }, [query]);

    const setFilter = (key: keyof T, value: string | null) =>
        setFilters((current) => ({ ...current, [key]: value }));

    const clearFilters = () =>
        setFilters((current) => {
            const cleared: ListFilters = Object.fromEntries(
                Object.keys(current).map((key) => [key, null]),
            );

            return { ...current, ...cleared };
        });

    return { filters, setFilter, clearFilters, isFiltering: query !== '{}' };
}
