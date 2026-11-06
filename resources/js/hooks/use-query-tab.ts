import { usePage } from '@inertiajs/react';
import { useState } from 'react';

export function useQueryTab(
    tabs: readonly string[],
    fallback: string,
): [string, (tab: string) => void] {
    const { url } = usePage();
    const [tab, setTab] = useState(() => {
        const requested = new URLSearchParams(url.split('?')[1] ?? '').get(
            'tab',
        );

        return tabs.find((name) => name === requested) ?? fallback;
    });

    const select = (next: string) => {
        setTab(next);
        const location = new URL(window.location.href);
        location.searchParams.set('tab', next);
        window.history.replaceState(window.history.state, '', location);
    };

    return [tab, select];
}
