/**
 * The API uses the session cookie, so state-changing requests carry Laravel's
 * CSRF token, which it publishes in the XSRF-TOKEN cookie.
 *
 * @returns {Record<string, string>}
 */
export function csrfHeaders() {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? { 'X-XSRF-TOKEN': decodeURIComponent(match[1]) } : {};
}

const SIGNED_OUT = 'docqa:signed-out';

/** Tell the app the session has ended (e.g. it expired), so it shows the sign-in screen. */
export function notifySignedOut() {
    window.dispatchEvent(new Event(SIGNED_OUT));
}

/** @param {() => void} callback */
export function onSignedOut(callback) {
    window.addEventListener(SIGNED_OUT, callback);

    return () => window.removeEventListener(SIGNED_OUT, callback);
}
