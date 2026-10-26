<script setup>
import { reactive, ref } from 'vue';

const props = defineProps({
    login: { type: Function, required: true },
    register: { type: Function, required: true },
});

const mode = ref('login');
const busy = ref(false);
const error = ref(null);
const fieldClass = 'mt-1 block w-full rounded-lg border border-line bg-canvas px-3 py-2 text-sm outline-none focus:border-accent';
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' });

async function submit() {
    busy.value = true;
    error.value = null;

    try {
        if (mode.value === 'login') {
            await props.login({ email: form.email, password: form.password, remember: true });
        } else {
            await props.register({ ...form });
        }
    } catch (e) {
        error.value = e.message;
    } finally {
        busy.value = false;
    }
}

function switchMode() {
    mode.value = mode.value === 'login' ? 'register' : 'login';
    error.value = null;
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
                <h2 class="text-sm font-semibold">{{ mode === 'login' ? 'Sign in' : 'Create an account' }}</h2>

                <label v-if="mode === 'register'" class="block">
                    <span class="text-xs text-muted">Name</span>
                    <input v-model="form.name" type="text" required autocomplete="name" :class="fieldClass" />
                </label>

                <label class="block">
                    <span class="text-xs text-muted">Email</span>
                    <input v-model="form.email" type="email" required autocomplete="email" :class="fieldClass" />
                </label>

                <label class="block">
                    <span class="text-xs text-muted">Password</span>
                    <input
                        v-model="form.password"
                        type="password"
                        required
                        :autocomplete="mode === 'login' ? 'current-password' : 'new-password'"
                        :class="fieldClass"
                    />
                </label>

                <label v-if="mode === 'register'" class="block">
                    <span class="text-xs text-muted">Confirm password</span>
                    <input v-model="form.password_confirmation" type="password" required autocomplete="new-password" :class="fieldClass" />
                </label>

                <p v-if="error" class="text-xs text-rose-600 dark:text-rose-400" role="alert">{{ error }}</p>

                <button
                    type="submit"
                    class="w-full rounded-lg bg-accent px-3 py-2 text-sm font-medium text-white transition hover:opacity-90 disabled:opacity-50"
                    :disabled="busy"
                >
                    {{ busy ? 'Please wait…' : mode === 'login' ? 'Sign in' : 'Create account' }}
                </button>
            </form>

            <p class="mt-4 text-center text-xs text-muted">
                {{ mode === 'login' ? 'No account yet?' : 'Already have an account?' }}
                <button type="button" class="text-accent hover:underline" @click="switchMode">
                    {{ mode === 'login' ? 'Create one' : 'Sign in' }}
                </button>
            </p>
        </div>
    </div>
</template>

