import { useEffect, useState } from 'react';

export function useCatalog<T>(url: string | null): {
    items: T[];
    loading: boolean;
    error: string | null;
} {
    const [state, setState] = useState<{
        url: string | null;
        items: T[];
        error: string | null;
    }>({ url: null, items: [], error: null });

    useEffect(() => {
        if (url === null) {
            return;
        }

        let current = true;

        fetch(url, { headers: { Accept: 'application/json' } })
            .then(async (response) => {
                const body = await response.json();

                if (!current) {
                    return;
                }

                setState(
                    response.ok
                        ? { url, items: body as T[], error: null }
                        : {
                              url,
                              items: [],
                              error:
                                  (body as { message?: string }).message ??
                                  'The provider did not answer.',
                          },
                );
            })
            .catch(() => {
                if (current) {
                    setState({
                        url,
                        items: [],
                        error: 'The provider did not answer.',
                    });
                }
            });

        return () => {
            current = false;
        };
    }, [url]);

    const fresh = url !== null && state.url === url;

    return {
        items: fresh ? state.items : [],
        loading: url !== null && !fresh,
        error: fresh ? state.error : null,
    };
}
