export type CursorLinks = {
    prev: string | null;
    next: string | null;
};

export type CursorPaginated<T> = {
    data: T[];
    links: CursorLinks;
    meta: {
        per_page: number;
        next_cursor: string | null;
        prev_cursor: string | null;
    };
};
