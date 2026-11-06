function csrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class HttpError extends Error {
    constructor(
        message: string,
        public readonly errors: Record<string, string[]> = {},
    ) {
        super(message);
    }
}

async function parse<T>(response: Response): Promise<T> {
    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const errors =
            (body as { errors?: Record<string, string[]> }).errors ?? {};
        const message =
            Object.values(errors)[0]?.[0] ??
            (body as { message?: string }).message ??
            `Request failed (${response.status})`;

        throw new HttpError(message, errors);
    }

    return body as T;
}

export async function getJson<T>(url: string): Promise<T> {
    return parse<T>(
        await fetch(url, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        }),
    );
}

export async function postJson<T>(url: string, data: unknown): Promise<T> {
    return parse<T>(
        await fetch(url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
            body: JSON.stringify(data),
        }),
    );
}

export function deleteJson(url: string): void {
    void fetch(url, {
        method: 'DELETE',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': csrfToken() },
        credentials: 'same-origin',
        keepalive: true,
    });
}
