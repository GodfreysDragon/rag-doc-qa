import { csrfHeaders, notifySignedOut } from './session';

const BASE = '/api/v1';

async function request(path, options = {}) {
    const response = await fetch(`${BASE}${path}`, {
        ...options,
        headers: {
            Accept: 'application/json',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...csrfHeaders(),
            ...(options.headers ?? {}),
        },
    });

    if (response.status === 401 && !options.quietUnauthenticated) {
        notifySignedOut();
    }

    if (response.status === 204) {
        return null;
    }

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = body.errors ? Object.values(body.errors)[0]?.[0] : null;
        const error = new Error(firstError ?? body.message ?? `Request failed (${response.status})`);
        error.status = response.status;
        throw error;
    }

    return body;
}

export const api = {
    /** The signed-in user, or null for a guest. */
    currentUser: () =>
        request('/user', { quietUnauthenticated: true })
            .then((body) => body.data)
            .catch((e) => {
                if (e.status === 401) {
                    return null;
                }
                throw e;
            }),

    login: (credentials) =>
        request('/login', { method: 'POST', body: JSON.stringify(credentials), quietUnauthenticated: true }).then((body) => body.data),

    register: (details) =>
        request('/register', { method: 'POST', body: JSON.stringify(details), quietUnauthenticated: true }).then((body) => body.data),

    /** Resolves with the same message whether or not the email has an account. */
    forgotPassword: (email) =>
        request('/forgot-password', { method: 'POST', body: JSON.stringify({ email }), quietUnauthenticated: true }).then((body) => body.message),

    resetPassword: (details) =>
        request('/reset-password', { method: 'POST', body: JSON.stringify(details), quietUnauthenticated: true }).then((body) => body.message),

    resendVerification: () => request('/email/verification-notification', { method: 'POST' }),

    deleteAccount: (password) => request('/user', { method: 'DELETE', body: JSON.stringify({ password }) }),

    logout: () => request('/logout', { method: 'POST' }),

    listDocuments: () => request('/documents').then((body) => body.data),

    getDocument: (id) => request(`/documents/${id}`).then((body) => body.data),

    deleteDocument: (id) => request(`/documents/${id}`, { method: 'DELETE' }),

    reprocessDocument: (id) => request(`/documents/${id}/reprocess`, { method: 'POST' }).then((body) => body.data),

    /**
     * Upload with progress. fetch() can't report upload progress, so this uses XHR.
     */
    uploadDocument(file, onProgress = () => {}) {
        return new Promise((resolve, reject) => {
            const form = new FormData();
            form.append('file', file);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', `${BASE}/documents`);
            xhr.setRequestHeader('Accept', 'application/json');
            for (const [name, value] of Object.entries(csrfHeaders())) {
                xhr.setRequestHeader(name, value);
            }
            xhr.upload.addEventListener('progress', (event) => {
                if (event.lengthComputable) {
                    onProgress(event.loaded / event.total);
                }
            });
            xhr.addEventListener('load', () => {
                let body = {};
                try {
                    body = JSON.parse(xhr.responseText);
                } catch {
                    // Non-JSON error page; fall through to the generic message.
                }

                if (xhr.status === 401) {
                    notifySignedOut();
                }

                if (xhr.status >= 200 && xhr.status < 300) {
                    resolve({ document: body.data, duplicate: Boolean(body.duplicate) });
                } else {
                    const firstError = body.errors ? Object.values(body.errors)[0]?.[0] : null;
                    reject(new Error(firstError ?? body.message ?? `Upload failed (${xhr.status})`));
                }
            });
            xhr.addEventListener('error', () => reject(new Error('Network error during upload.')));
            xhr.send(form);
        });
    },
};
