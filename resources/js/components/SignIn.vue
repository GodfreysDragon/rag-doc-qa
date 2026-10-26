<script setup>
import { computed, reactive, ref } from 'vue';
import { api } from '../lib/api';

const props = defineProps({
    login: { type: Function, required: true },
    register: { type: Function, required: true },
});

// A link from a password-reset email opens /reset-password/{token}?email=…
const resetToken = window.location.pathname.match(/^\/reset-password\/([^/]+)/)?.[1] ?? null;

const mode = ref(resetToken ? 'reset' : 'login');
const busy = ref(false);
const error = ref(null);
const notice = ref(null);
const fieldClass = 'mt-1 block w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm outline-none focus:border-accent';
const form = reactive({
    name: '',
    email: new URLSearchParams(window.location.search).get('email') ?? '',
    password: '',
    password_confirmation: '',
});

const titles = { login: 'Sign in', register: 'Create an account', forgot: 'Reset your password', reset: 'Choose a new password' };
const buttons = { login: 'Sign in', register: 'Create account', forgot: 'Email me a reset link', reset: 'Change password' };
const needsPassword = computed(() => mode.value !== 'forgot');
const newPassword = computed(() => mode.value === 'register' || mode.value === 'reset');

async function submit() {
    busy.value = true;
    error.value = null;
    notice.value = null;

    try {
        if (mode.value === 'login') {
            await props.login({ email: form.email, password: form.password, remember: true });
        } else if (mode.value === 'register') {
            await props.register({ ...form });
        } else if (mode.value === 'forgot') {
            notice.value = await api.forgotPassword(form.email);
        } else {
            await api.resetPassword({ token: resetToken, ...form });
            window.history.replaceState(null, '', '/');
            switchMode('login');
            notice.value = 'Your password has been changed. Sign in with the new one.';
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

function switchMode(next) {
    mode.value = next;
    error.value = null;
    notice.value = null;
    form.password = '';
    form.password_confirmation = '';
}
</script>

<template>
    <div class="grid min-h-full place-items-center px-4 py-10">
        <div class="w-full max-w-sm">
            <div class="mb-6 flex items-center gap-2.5">
                <div class="grid size-9 place-items-center rounded-lg bg-accent text-white">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5">
                        <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                        <path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z" />
                        <path d="M9 13h6M9 17h4" />
                    </svg>
                </div>
                <div>
                    <h1 class="font-semibold leading-tight">DocQA</h1>
                    <p class="text-xs text-muted">Ask questions about your documents</p>
                </div>
            </div>

            <form class="space-y-3 rounded-xl border border-line bg-panel p-5" @submit.prevent="submit">
                <h2 class="text-sm font-semibold">{{ titles[mode] }}</h2>

                <p v-if="mode === 'forgot'" class="text-xs text-muted">Enter your email and we'll send you a link to choose a new password.</p>

                <label v-if="mode === 'register'" class="block">
                    <span class="text-xs text-muted">Name</span>
                    <input v-model="form.name" type="text" required autocomplete="name" :class="fieldClass" />
                </label>

                <label class="block">
                    <span class="text-xs text-muted">Email</span>
                    <input v-model="form.email" type="email" required autocomplete="email" :class="fieldClass" />
                </label>

                <label v-if="needsPassword" class="block">
                    <span class="flex items-baseline justify-between">
                        <span class="text-xs text-muted">{{ mode === 'reset' ? 'New password' : 'Password' }}</span>
                        <button v-if="mode === 'login'" type="button" class="text-xs text-accent hover:underline" @click="switchMode('forgot')">
                            Forgot password?
                        </button>
                    </span>
                    <input
                        v-model="form.password"
                        type="password"
                        required
                        :autocomplete="newPassword ? 'new-password' : 'current-password'"
                        :class="fieldClass"
                    />
                </label>

                <label v-if="newPassword" class="block">
                    <span class="text-xs text-muted">Confirm password</span>
                    <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" :class="fieldClass" />
                </label>

                <p v-if="error" class="text-xs text-rose-600 dark:text-rose-400" role="alert">{{ error }}</p>
                <p v-if="notice" class="text-xs text-emerald-700 dark:text-emerald-400" role="status">{{ notice }}</p>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-accent px-3 py-2 text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-50"
                    :disabled="busy"
                >
                    {{ busy ? 'Please wait…' : buttons[mode] }}
                </button>
            </form>

            <p class="mt-4 text-center text-xs text-muted">
                <template v-if="mode === 'login'">
                    No account yet?
                    <button type="button" class="text-accent hover:underline" @click="switchMode('register')">Create one</button>
                </template>
                <template v-else>
                    {{ mode === 'register' ? 'Already have an account?' : 'Remembered it?' }}
                    <button type="button" class="text-accent hover:underline" @click="switchMode('login')">Sign in</button>
                </template>
            </p>
        </div>
    </div>
</template>
