<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import ChatPanel from './ChatPanel.vue';
import DocumentSidebar from './DocumentSidebar.vue';
import { useChat } from '../composables/useChat';
import { useDocuments } from '../composables/useDocuments';

defineProps({
    user: { type: Object, required: true },
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

onMounted(docs.load);
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
                @sign-out="signOut"
            />
        </aside>

        <main class="flex min-w-0 flex-1 flex-col">
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
