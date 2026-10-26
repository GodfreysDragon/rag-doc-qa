<script setup>
import { onMounted } from 'vue';
import SignIn from './components/SignIn.vue';
import Workspace from './components/Workspace.vue';
import { useAuth } from './composables/useAuth';

const auth = useAuth();

onMounted(auth.load);
</script>

<template>
    <div v-if="auth.checking.value" class="grid h-full place-items-center text-sm text-muted">Loading…</div>
    <!-- Keyed by user, so signing out and back in starts a fresh workspace (no stale chat or documents). -->
    <Workspace
        v-else-if="auth.user.value"
        :key="auth.user.value.id"
        :user="auth.user.value"
        :delete-account="auth.deleteAccount"
        @sign-out="auth.logout"
    />
    <SignIn v-else :login="auth.login" :register="auth.register" />
</template>
