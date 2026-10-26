<script setup>
import { ref } from 'vue';

const props = defineProps({
    documents: { type: Array, required: true },
    uploads: { type: Array, required: true },
    loading: { type: Boolean, default: false },
    error: { type: String, default: null },
    selectedIds: { type: Set, required: true },
    user: { type: Object, required: true },
});

const emit = defineEmits(['upload', 'dismiss-upload', 'remove', 'reprocess', 'toggle', 'clear-selection', 'sign-out']);

const fileInput = ref(null);
const dragging = ref(false);

const ACCEPT = '.pdf,.docx,.txt,.md';

function onDrop(event) {
    dragging.value = false;
    if (event.dataTransfer?.files?.length) {
        emit('upload', event.dataTransfer.files);
    }
}

function onPick(event) {
    if (event.target.files?.length) {
        emit('upload', event.target.files);
    }
    event.target.value = '';
}

function confirmRemove(doc) {
    if (window.confirm(`Delete “${doc.title}” and its ${doc.chunk_count} chunks?`)) {
        emit('remove', doc.id);
    }
}

function formatSize(bytes) {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(0)} KB`;
    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

const statusLabel = {
    pending: 'Queued',
    processing: 'Embedding…',
    ready: 'Ready',
    failed: 'Failed',
};

const statusClass = {
    pending: 'bg-amber-500/15 text-amber-700 dark:text-amber-300',
    processing: 'bg-sky-500/15 text-sky-700 dark:text-sky-300',
    ready: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300',
    failed: 'bg-rose-500/15 text-rose-700 dark:text-rose-300',
};
</script>

<template>
    <div class="flex h-full flex-col">
        <header class="flex items-center gap-2.5 border-b border-line px-5 py-4">
            <div class="grid size-8 place-items-center rounded-lg bg-accent text-white">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4.5">
                    <path d="M14 3v4a1 1 0 0 0 1 1h4" />
                    <path d="M17 21H7a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2Z" />
                    <path d="M9 13h6M9 17h4" />
                </svg>
            </div>
            <div>
                <h1 class="text-sm font-semibold leading-tight">DocQA</h1>
                <p class="text-xs text-muted">Ask questions about your documents</p>
            </div>
        </header>

        <div class="p-4">
            <button
                type="button"
                class="flex w-full flex-col items-center gap-1 rounded-xl border-2 border-dashed px-4 py-6 text-center transition"
                :class="dragging ? 'border-accent bg-accent-soft' : 'border-line hover:border-accent/60'"
                @click="fileInput.click()"
                @dragover.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6 text-accent">
                    <path d="M12 16V4m0 0-4 4m4-4 4 4" />
                    <path d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" />
                </svg>
                <span class="text-sm font-medium">Drop files or click to upload</span>
                <span class="text-xs text-muted">PDF, DOCX, TXT, MD · up to 20 MB</span>
            </button>
            <input ref="fileInput" type="file" class="hidden" multiple :accept="ACCEPT" @change="onPick" />
        </div>

        <div class="flex items-center justify-between px-5 pb-2">
            <h2 class="text-xs font-semibold tracking-wide text-muted uppercase">Library</h2>
            <button
                v-if="props.selectedIds.size > 0"
                type="button"
                class="text-xs text-accent hover:underline"
                @click="emit('clear-selection')"
            >
                Search all
            </button>
        </div>

        <p v-if="props.selectedIds.size === 0 && documents.some((d) => d.status === 'ready')" class="px-5 pb-2 text-xs text-muted">
            Searching all documents. Tick some to narrow the search.
        </p>

        <div class="min-h-0 flex-1 space-y-1.5 overflow-y-auto px-3 pb-4">
            <div
                v-for="upload in uploads"
                :key="upload.key"
                class="rounded-lg border border-line px-3 py-2.5"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="truncate text-sm">{{ upload.name }}</span>
                    <button
                        v-if="upload.error"
                        type="button"
                        class="text-xs text-muted hover:text-ink"
                        @click="emit('dismiss-upload', upload.key)"
                    >
                        Dismiss
                    </button>
                </div>
                <p v-if="upload.error" class="mt-1 text-xs text-rose-600 dark:text-rose-400">{{ upload.error }}</p>
                <div v-else class="mt-2 h-1 overflow-hidden rounded-full bg-line">
                    <div class="h-full bg-accent transition-all" :style="{ width: `${Math.round(upload.progress * 100)}%` }" />
                </div>
            </div>

            <p v-if="loading" class="px-2 py-4 text-sm text-muted">Loading…</p>
            <p v-else-if="error" class="px-2 py-4 text-sm text-rose-600">{{ error }}</p>
            <p v-else-if="documents.length === 0 && uploads.length === 0" class="px-2 py-4 text-sm text-muted">
                No documents yet. Upload one to get started.
            </p>

            <div
                v-for="doc in documents"
                :key="doc.id"
                class="group rounded-lg border px-3 py-2.5 transition"
                :class="props.selectedIds.has(doc.id) ? 'border-accent bg-accent-soft' : 'border-transparent hover:bg-canvas'"
            >
                <div class="flex items-start gap-2.5">
                    <input
                        type="checkbox"
                        class="mt-0.5 size-4 shrink-0 accent-(--color-accent)"
                        :disabled="doc.status !== 'ready'"
                        :checked="props.selectedIds.has(doc.id)"
                        :aria-label="`Limit search to ${doc.title}`"
                        @change="emit('toggle', doc.id)"
                    />
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm font-medium" :title="doc.original_name">{{ doc.title }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">
                            <span class="rounded px-1.5 py-0.5 font-medium" :class="statusClass[doc.status]">
                                {{ statusLabel[doc.status] }}
                            </span>
                            <span class="uppercase">{{ doc.extension }}</span>
                            <span>{{ formatSize(doc.size_bytes) }}</span>
                            <span v-if="doc.page_count">{{ doc.page_count }} pp</span>
                            <span v-if="doc.status === 'ready'">{{ doc.chunk_count }} chunks</span>
                        </div>
                        <p v-if="doc.status === 'failed'" class="mt-1.5 text-xs text-rose-600 dark:text-rose-400">
                            {{ doc.error }}
                            <button type="button" class="ml-1 underline" @click="emit('reprocess', doc.id)">Retry</button>
                        </p>
                    </div>
                    <button
                        type="button"
                        class="rounded p-1 text-muted opacity-0 transition group-hover:opacity-100 hover:text-rose-600 focus:opacity-100"
                        :aria-label="`Delete ${doc.title}`"
                        @click="confirmRemove(doc)"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-4">
                            <path d="M4 7h16M10 11v6M14 11v6M5 7l1 12a2 2 0 0 0 2 2h8a2 2 0 0 0 2-2l1-12M9 7V4h6v3" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <footer class="flex items-center justify-between gap-2 border-t border-line px-5 py-3 text-xs">
            <span class="truncate text-muted" :title="props.user.email">{{ props.user.name }}</span>
            <button type="button" class="shrink-0 text-muted hover:text-ink" @click="emit('sign-out')">Sign out</button>
        </footer>
    </div>
</template>
