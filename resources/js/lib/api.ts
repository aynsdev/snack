/**
 * A small JSON client for Snack's non-Inertia endpoints.
 *
 * Most of the app navigates through Inertia, where props are server-owned and
 * replaced on each visit. A channel timeline cannot work that way: it merges
 * paginated history, messages arriving over the WebSocket, and the user's own
 * unconfirmed sends, and it has to survive a broadcast landing mid-request. So
 * those few endpoints answer with JSON and the timeline owns its own state.
 *
 * Inertia v3 dropped Axios, so this wraps `fetch` directly and supplies the two
 * things Laravel needs: the CSRF token and an Accept header that makes the
 * framework answer with JSON instead of a redirect.
 */

/** Thrown for any non-2xx response, carrying enough to react to it. */
export class ApiError extends Error {
    constructor(
        readonly status: number,
        /** Laravel's `errors` bag, when the failure was a 422. */
        readonly errors: Record<string, string[]> = {},
        message = 'Request failed',
    ) {
        super(message);
        this.name = 'ApiError';
    }

    /** A validation failure — the request was understood and refused. */
    get isValidationError(): boolean {
        return this.status === 422;
    }

    /**
     * The first message the user should actually see, preferring the specific
     * field error over the generic one.
     */
    get userMessage(): string {
        return Object.values(this.errors)[0]?.[0] ?? this.message;
    }
}

/**
 * Read Laravel's CSRF cookie.
 *
 * Laravel sets `XSRF-TOKEN` url-encoded and expects it echoed back decoded in
 * `X-XSRF-TOKEN`. Reading the cookie rather than a meta tag means the token
 * stays correct after a session is regenerated mid-session.
 */
function csrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]*)/);

    return match ? decodeURIComponent(match[1]) : '';
}

async function request<T>(
    method: string,
    url: string,
    body?: unknown,
    signal?: AbortSignal,
): Promise<T> {
    const response = await fetch(url, {
        method,
        signal,
        // Same-origin so the session cookie rides along; the endpoints are all
        // first-party.
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': csrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    if (!response.ok) {
        // A 419 (expired CSRF/session) or a 5xx may not carry JSON at all, so
        // parsing is allowed to fail without masking the real status.
        const payload = await response.json().catch(() => ({}));

        throw new ApiError(
            response.status,
            payload.errors ?? {},
            payload.message ?? `Request failed with status ${response.status}`,
        );
    }

    return response.json() as Promise<T>;
}

/**
 * A multipart upload with progress.
 *
 * Uses XMLHttpRequest rather than `fetch` for one reason: `fetch` still cannot
 * report how much of a request body has been sent. For a 40 MB video that is the
 * difference between a progress bar and a spinner that might mean anything, so
 * the older API earns its place here and nowhere else.
 */
function upload<T>(
    url: string,
    file: File,
    onProgress?: (percent: number) => void,
    signal?: AbortSignal,
): Promise<T> {
    return new Promise<T>((resolve, reject) => {
        const request = new XMLHttpRequest();
        const form = new FormData();

        form.append('file', file);

        request.open('POST', url, true);
        request.withCredentials = true;
        request.setRequestHeader('Accept', 'application/json');
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('X-XSRF-TOKEN', csrfToken());

        // Deliberately no Content-Type: the browser has to set it itself so it
        // can append the multipart boundary, and naming it here breaks the body.

        request.upload.addEventListener('progress', (event) => {
            if (event.lengthComputable) {
                onProgress?.(Math.round((event.loaded / event.total) * 100));
            }
        });

        request.addEventListener('load', () => {
            const payload: {
                errors?: Record<string, string[]>;
                message?: string;
            } = parseJson(request.responseText);

            if (request.status >= 200 && request.status < 300) {
                resolve(payload as T);

                return;
            }

            reject(
                new ApiError(
                    request.status,
                    payload.errors ?? {},
                    payload.message ??
                        `Upload failed with status ${request.status}`,
                ),
            );
        });

        request.addEventListener('error', () =>
            reject(
                new ApiError(0, {}, 'The upload could not reach the server.'),
            ),
        );

        request.addEventListener('abort', () =>
            reject(new ApiError(0, {}, 'Upload cancelled.')),
        );

        signal?.addEventListener('abort', () => request.abort(), {
            once: true,
        });

        request.send(form);
    });
}

function parseJson(text: string): Record<string, never> {
    try {
        return JSON.parse(text);
    } catch {
        // A 419 or a 5xx may answer with HTML. The status is what matters.
        return {} as Record<string, never>;
    }
}

export const api = {
    get: <T>(url: string, signal?: AbortSignal) =>
        request<T>('GET', url, undefined, signal),
    post: <T>(url: string, body?: unknown, signal?: AbortSignal) =>
        request<T>('POST', url, body, signal),
    patch: <T>(url: string, body?: unknown, signal?: AbortSignal) =>
        request<T>('PATCH', url, body, signal),
    delete: <T>(url: string, signal?: AbortSignal) =>
        request<T>('DELETE', url, undefined, signal),
    upload,
};
