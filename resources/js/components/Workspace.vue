<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ChatPanel from './ChatPanel.vue';
import DocumentSidebar from './DocumentSidebar.vue';
import { useChat } from '../composables/useChat';
import { useDocuments } from '../composables/useDocuments';
import { api } from '../lib/api';

const props = defineProps({
    user: { type: Object, required: true },
    deleteAccount: { type: Function, required: true },
});

const emit = defineEmits(['sign-out']);

const docs = useDocuments();
const chat = useChat();

const selectedIds = ref(new Set());
const sidebarOpen = ref(false);

// Drop selections for documents that were deleted or are no longer ready.
watch(docs.readyDocuments, (ready) => {
    const readyIds = new Set(ready.map((d) => d.id));
    selectedIds.value = new Set([...selectedIds.value].filter((id) => readyIds.has(id)));
});

const scope = computed(() => (selectedIds.value.size > 0 ? [...selectedIds.value] : null));

function toggleSelected(id) {
    const next = new Set(selectedIds.value);
    next.has(id) ? next.delete(id) : next.add(id);
    selectedIds.value = next;
}

function ask(question) {
    sidebarOpen.value = false;
    chat.ask(question, scope.value);
}

function signOut() {
    chat.stop();
    emit('sign-out');
}

async function deleteAccount(password) {
    chat.stop();
    await props.deleteAccount(password);
}

const resent = ref(false);

async function resendVerification() {
    await api.resendVerification();
    resent.value = true;
}

onMounted(() => {
    // Arriving from the verification link (/?verified=1): tidy the URL.
    if (new URLSearchParams(window.location.search).has('verified')) {
        window.history.replaceState(null, '', '/');
    }

    docs.load();
});
</script>

<template>
    <div class="flex h-full">
        <!-- Mobile backdrop -->
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-black/40 lg:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-40 w-80 max-w-[85vw] border-r border-line bg-panel transition-transform lg:static lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <DocumentSidebar
                :documents="docs.documents.value"
                :uploads="docs.uploads.value"
                :loading="docs.loading.value"
                :error="docs.error.value"
                :selected-ids="selectedIds"
                :user="user"
                @upload="docs.upload"
                @dismiss-upload="docs.dismissUpload"
                @remove="docs.remove"
                @reprocess="docs.reprocess"
                @toggle="toggleSelected"
                @clear-selection="selectedIds = new Set()"
                :delete-account="deleteAccount"
                @sign-out="signOut"
            />
        </aside>

        <main class="flex min-w-0 flex-1 flex-col">
            <div
                v-if="user.verification_required && !user.email_verified"
                class="border-b border-amber-300 bg-amber-50 px-4 py-2.5 text-xs text-amber-900 dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200"
                role="status"
            >
                Confirm your email address to upload documents and ask questions. We sent a link to {{ user.email }}.
                <button type="button" class="ml-1 font-medium underline disabled:no-underline disabled:opacity-60" :disabled="resent" @click="resendVerification">
                    {{ resent ? 'Sent' : 'Send it again' }}
                </button>
            </div>
            <ChatPanel
                :messages="chat.messages.value"
                :is-streaming="chat.isStreaming.value"
                :ready-count="docs.readyDocuments.value.length"
                :scope-count="selectedIds.size"
                @ask="ask"
                @stop="chat.stop"
                @reset="chat.reset"
                @open-sidebar="sidebarOpen = true"
            />
        </main>
    </div>
</template>
