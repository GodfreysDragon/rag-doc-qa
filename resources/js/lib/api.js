const BASE = '/api/v1';

async function request(path, options = {}) {
    const response = await fetch(`${BASE}${path}`, {
        ...options,
        headers: { Accept: 'application/json', ...(options.headers ?? {}) },
    });

    if (response.status === 204) {
        return null;
    }

    const body = await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = body.errors ? Object.values(body.errors)[0]?.[0] : null;
        throw new Error(firstError ?? body.message ?? `Request failed (${response.status})`);
    }

    return body;
}

export const api = {
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
