import { onBeforeUnmount, ref } from 'vue';
import { api } from '../lib/api';
import { onSignedOut } from '../lib/session';

export function useAuth() {
    const user = ref(null);
    const checking = ref(true);

    async function load() {
        try {
            user.value = await api.currentUser();
        } finally {
            checking.value = false;
        }
    }

    async function login(credentials) {
        user.value = await api.login(credentials);
    }

    async function register(details) {
        user.value = await api.register(details);
    }

    async function logout() {
        try {
            await api.logout();
        } finally {
            user.value = null;
        }
    }

    async function deleteAccount(password) {
        await api.deleteAccount(password);
        user.value = null;
    }

    // Any request that comes back 401 (an expired session) returns to the sign-in screen.
    const stopListening = onSignedOut(() => (user.value = null));
    onBeforeUnmount(stopListening);

    return { user, checking, load, login, register, logout, deleteAccount };
}
