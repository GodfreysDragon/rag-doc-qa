import { csrfHeaders, notifySignedOut } from './session';

/**
 * POST a JSON body and consume a Server-Sent Events response.
 *
 * The browser's EventSource only supports GET, so this reads the response
 * body stream manually and parses the `event:` / `data:` framing.
 *
 * @param {string} url
 * @param {object} body
 * @param {{ onEvent: (type: string, data: any) => void, signal?: AbortSignal }} options
 */
export async function postEventStream(url, body, { onEvent, signal }) {
    const response = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'text/event-stream', ...csrfHeaders() },
        body: JSON.stringify(body),
        signal,
    });

    if (response.status === 401) {
        notifySignedOut();
    }

    if (!response.ok) {
        const payload = await response.json().catch(() => ({}));
        const firstError = payload.errors ? Object.values(payload.errors)[0]?.[0] : null;
        throw new Error(firstError ?? payload.message ?? `Request failed (${response.status})`);
    }

    const reader = response.body.pipeThrough(new TextDecoderStream()).getReader();
    let buffer = '';

    while (true) {
        const { value, done } = await reader.read();

        if (done) {
            break;
        }

        buffer += value;

        let boundary;
        while ((boundary = buffer.indexOf('\n\n')) !== -1) {
            const frame = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);
            dispatchFrame(frame, onEvent);
        }
    }

    if (buffer.trim() !== '') {
        dispatchFrame(buffer, onEvent);
    }
}

function dispatchFrame(frame, onEvent) {
    let type = 'message';
    const dataLines = [];

    for (const line of frame.split('\n')) {
        if (line.startsWith('event:')) {
            type = line.slice(6).trim();
        } else if (line.startsWith('data:')) {
            dataLines.push(line.slice(5).trimStart());
        }
    }

    if (dataLines.length === 0) {
        return;
    }

    let data = dataLines.join('\n');
    try {
        data = JSON.parse(data);
    } catch {
        // Leave non-JSON payloads as strings.
    }

    onEvent(type, data);
}
