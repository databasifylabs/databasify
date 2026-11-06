import { router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import {
    Cloud,
    DatabaseIcon,
    Globe,
    LayoutGrid,
    Search,
    Server,
    Settings,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { dashboard, search } from '@/routes';
import { index as databasesIndex } from '@/routes/databases';
import { index as dnsIndex } from '@/routes/dns';
import { edit as editProfile } from '@/routes/profile';
import { index as providersIndex } from '@/routes/providers';
import { index as serversIndex } from '@/routes/servers';

type SearchKind = 'page' | 'database' | 'server';

type SearchResult = {
    kind: SearchKind;
    title: string;
    subtitle: string;
    url: string;
};

const pages: SearchResult[] = [
    {
        kind: 'page',
        title: 'Dashboard',
        subtitle: 'Page',
        url: dashboard.url(),
    },
    {
        kind: 'page',
        title: 'Databases',
        subtitle: 'Page',
        url: databasesIndex.url(),
    },
    {
        kind: 'page',
        title: 'Servers',
        subtitle: 'Page',
        url: serversIndex.url(),
    },
    {
        kind: 'page',
        title: 'Providers',
        subtitle: 'Page',
        url: providersIndex.url(),
    },
    { kind: 'page', title: 'DNS', subtitle: 'Page', url: dnsIndex.url() },
    {
        kind: 'page',
        title: 'Settings',
        subtitle: 'Page',
        url: editProfile.url(),
    },
];

const pageIcons: Record<string, LucideIcon> = {
    Dashboard: LayoutGrid,
    Databases: DatabaseIcon,
    Servers: Server,
    Providers: Cloud,
    DNS: Globe,
    Settings: Settings,
};

function iconOf(result: SearchResult): LucideIcon {
    if (result.kind === 'database') {
        return DatabaseIcon;
    }

    if (result.kind === 'server') {
        return Server;
    }

    return pageIcons[result.title] ?? Search;
}

function useRemoteResults(term: string): SearchResult[] {
    const [results, setResults] = useState<{
        term: string;
        items: SearchResult[];
    }>({ term: '', items: [] });

    useEffect(() => {
        if (term === '') {
            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(() => {
            fetch(search.url({ query: { q: term } }), {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            })
                .then((response) => (response.ok ? response.json() : null))
                .then((body: { results: SearchResult[] } | null) => {
                    if (body) {
                        setResults({ term, items: body.results });
                    }
                })
                .catch(() => undefined);
        }, 150);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    return results.term === term ? results.items : [];
}

export function SearchCommand() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [active, setActive] = useState(0);
    const term = query.trim();
    const remote = useRemoteResults(term);
    const items = [
        ...pages.filter((page) =>
            page.title.toLowerCase().includes(term.toLowerCase()),
        ),
        ...remote,
    ];

    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent) => {
            if (event.key === 'k' && (event.metaKey || event.ctrlKey)) {
                event.preventDefault();
                setOpen((current) => !current);
            }
        };

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, []);

    const openPalette = (next: boolean) => {
        setOpen(next);
        setQuery('');
        setActive(0);
    };

    const visit = (result: SearchResult) => {
        openPalette(false);
        router.visit(result.url);
    };

    const onKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActive((index) => Math.min(index + 1, items.length - 1));
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActive((index) => Math.max(index - 1, 0));
        }

        if (event.key === 'Enter' && items[active]) {
            event.preventDefault();
            visit(items[active]);
        }
    };

    return (
        <>
            <button
                type="button"
                onClick={() => openPalette(true)}
                className="flex h-9 w-9 items-center justify-center gap-2 rounded-md border bg-muted/40 text-sm text-muted-foreground transition-colors hover:bg-muted sm:w-56 sm:justify-start sm:px-3"
            >
                <Search className="size-4 shrink-0" />
                <span className="hidden flex-1 truncate text-left whitespace-nowrap sm:inline">
                    Search…
                </span>
                <kbd className="hidden rounded border bg-background px-1.5 font-mono text-[11px] sm:inline">
                    ⌘K
                </kbd>
            </button>

            <Dialog open={open} onOpenChange={openPalette}>
                <DialogContent className="top-[20%] translate-y-0 gap-0 overflow-hidden p-0 sm:max-w-xl [&>button]:hidden">
                    <DialogTitle className="sr-only">Search</DialogTitle>
                    <DialogDescription className="sr-only">
                        Find a database, server or page
                    </DialogDescription>
                    <div className="flex items-center gap-2 border-b px-4">
                        <Search className="size-4 shrink-0 text-muted-foreground" />
                        <input
                            autoFocus
                            value={query}
                            onChange={(event) => {
                                setQuery(event.target.value);
                                setActive(0);
                            }}
                            onKeyDown={onKeyDown}
                            placeholder="Search databases, servers and pages"
                            className="h-12 w-full bg-transparent text-sm outline-none placeholder:text-muted-foreground"
                        />
                    </div>
                    <div className="max-h-80 overflow-y-auto p-2">
                        {items.length === 0 ? (
                            <p className="px-3 py-6 text-center text-sm text-muted-foreground">
                                Nothing matches “{term}”.
                            </p>
                        ) : (
                            items.map((item, index) => {
                                const Icon = iconOf(item);

                                return (
                                    <button
                                        key={`${item.kind}-${item.url}`}
                                        type="button"
                                        onMouseMove={() => setActive(index)}
                                        onClick={() => visit(item)}
                                        className={cn(
                                            'flex w-full items-center gap-3 rounded-md px-3 py-2 text-left text-sm',
                                            index === active && 'bg-muted',
                                        )}
                                    >
                                        <Icon className="size-4 shrink-0 text-muted-foreground" />
                                        <span className="flex-1 truncate font-medium">
                                            {item.title}
                                        </span>
                                        <span className="truncate text-xs text-muted-foreground">
                                            {item.subtitle}
                                        </span>
                                    </button>
                                );
                            })
                        )}
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}
