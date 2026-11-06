import { useEffect, useState } from 'react';
import { stars as starsRoute } from '@/routes/repository';

let request: Promise<number | null> | null = null;

function fetchStars(): Promise<number | null> {
    request ??= fetch(starsRoute.url(), {
        headers: { Accept: 'application/json' },
    })
        .then((response) => (response.ok ? response.json() : null))
        .then((body: { stars: number | null } | null) => body?.stars ?? null)
        .catch(() => null);

    return request;
}

const compact = new Intl.NumberFormat('en', {
    notation: 'compact',
    maximumFractionDigits: 1,
});

export function useRepositoryStars(): string | null {
    const [stars, setStars] = useState<number | null>(null);

    useEffect(() => {
        let active = true;
        void fetchStars().then((count) => {
            if (active) {
                setStars(count);
            }
        });

        return () => {
            active = false;
        };
    }, []);

    return stars === null ? null : compact.format(stars);
}
