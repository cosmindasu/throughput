/**
 * `fetch()` mic, pentru endpoint-urile JSON simple (ca `SearchController` — a se vedea
 * `GlobalSearch.tsx`), NU pentru navigare de pagină (aia rămâne `router`/`<Link>` din
 * `@inertiajs/react`, care își gestionează singur CSRF-ul).
 *
 * Laravel scrie cookie-ul `XSRF-TOKEN` (criptat, decodabil doar de server) pe orice
 * răspuns din grupul `web`; `VerifyCsrfToken` acceptă aceeași valoare înapoi pe antetul
 * `X-XSRF-TOKEN` — exact mecanismul pe care `axios` îl automatiza înainte să fie scos din
 * proiect (frontend.md: „Axios a fost scos; există client XHR propriu"). Fără el, orice
 * POST/PATCH/DELETE de aici ar cădea cu 419 pe primul apel după `npm run build`.
 */

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`));

    return match ? decodeURIComponent(match[1]) : null;
}

export class ApiError extends Error {
    status: number;
    errors: Record<string, string[]> | undefined;

    constructor(status: number, body: { message?: string; errors?: Record<string, string[]> } | null) {
        super(body?.message ?? `Request failed with status ${status}`);
        this.status = status;
        this.errors = body?.errors;
    }
}

async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    const headers = new Headers({ Accept: 'application/json' });
    const isMutating = method !== 'GET' && method !== 'HEAD';

    if (isMutating) {
        headers.set('Content-Type', 'application/json');

        const token = readCookie('XSRF-TOKEN');
        if (token) {
            headers.set('X-XSRF-TOKEN', token);
        }
    }

    const response = await fetch(url, {
        method,
        headers,
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        const errorBody = await response.json().catch(() => null);
        throw new ApiError(response.status, errorBody);
    }

    if (response.status === 204) {
        return undefined as T;
    }

    return (await response.json()) as T;
}

export const api = {
    get: <T>(url: string) => request<T>('GET', url),
    post: <T>(url: string, body?: unknown) => request<T>('POST', url, body),
    patch: <T>(url: string, body?: unknown) => request<T>('PATCH', url, body),
    put: <T>(url: string, body?: unknown) => request<T>('PUT', url, body),
    delete: <T>(url: string) => request<T>('DELETE', url),
};
