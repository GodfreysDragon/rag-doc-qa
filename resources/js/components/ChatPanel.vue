<script setup>
import { nextTick, ref, watch } from 'vue';
import AssistantMessage from './AssistantMessage.vue';

const props = defineProps({
    messages: { type: Array, required: true },
    isStreaming: { type: Boolean, default: false },
    readyCount: { type: Number, default: 0 },
    scopeCount: { type: Number, default: 0 },
});

const emit = defineEmits(['ask', 'stop', 'reset', 'open-sidebar']);

const draft = ref('');
const scroller = ref(null);
const textarea = ref(null);
const stickToBottom = ref(true);

const suggestions = [
    'Summarise the key points of these documents.',
    'What are the main risks or open questions mentioned?',
    'List any dates, deadlines or figures with their context.',
];

function submit(text = draft.value) {
    const question = text.trim();
    if (!question || props.isStreaming || props.readyCount === 0) {
        return;
    }
    emit('ask', question);
    draft.value = '';
    stickToBottom.value = true;
    nextTick(autoGrow);
}

function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    }
}

function autoGrow() {
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
}

function onScroll() {
    const el = scroller.value;
    stickToBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 80;
}

// Follow the streaming answer unless the user has scrolled up to read.
watch(
    () => props.messages.map((m) => m.content.length + (m.sources?.length ?? 0)).join(),
    () => {
        if (stickToBottom.value) {
            nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight }));
        }
    },
);
</script>

<template>
    <header class="flex items-center justify-between gap-3 border-b border-line bg-panel px-4 py-3 sm:px-6">
        <div class="flex items-center gap-3">
            <button
                type="button"
                class="rounded-md p-1.5 text-muted hover:bg-canvas hover:text-ink lg:hidden"
                aria-label="Open document library"
                @click="emit('open-sidebar')"
            >
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="size-5">
                    <path d="M4 6h16M4 12h16M4 18h16" />
                </svg>
            </button>
            <div class="text-sm">
                <span class="font-medium">Chat</span>
                <span class="text-muted">
                    ·
                    {{ scopeCount > 0 ? `${scopeCount} selected document${scopeCount === 1 ? '' : 's'}` : `all ${readyCount} document${readyCount === 1 ? '' : 's'}` }}
                </span>
            </div>
        </div>
        <button
            v-if="messages.length > 0"
            type="button"
            class="rounded-md px-2.5 py-1.5 text-sm text-muted hover:bg-canvas hover:text-ink"
            @click="emit('reset')"
        >
            New chat
        </button>
    </header>

    <div ref="scroller" class="min-h-0 flex-1 overflow-y-auto" @scroll.passive="onScroll">
        <div class="mx-auto w-full max-w-3xl px-4 py-6 sm:px-6">
            <div v-if="messages.length === 0" class="flex flex-col items-center pt-[12vh] text-center">
                <div class="grid size-12 place-items-center rounded-2xl bg-accent-soft text-accent">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" class="size-6">
                        <path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.4A8 8 0 1 1 21 12Z" />
                        <path d="M9 10h6M9 14h3" />
                    </svg>
                </div>
                <h2 class="mt-4 text-xl font-semibold">Ask your documents anything</h2>
                <p class="mt-2 max-w-md text-sm text-muted">
                    Questions are embedded and matched against your document chunks with hybrid vector + keyword search.
                    The best passages are passed to Claude, which answers with citations.
                </p>

                <p v-if="readyCount === 0" class="mt-6 rounded-lg border border-line bg-panel px-4 py-3 text-sm text-muted">
                    Upload a document in the library to start asking questions.
                </p>
                <div v-else class="mt-6 grid w-full gap-2 sm:grid-cols-3">
                    <button
                        v-for="suggestion in suggestions"
                        :key="suggestion"
                        type="button"
                        class="rounded-xl border border-line bg-panel px-4 py-3 text-left text-sm transition hover:border-accent/60"
                        @click="submit(suggestion)"
                    >
                        {{ suggestion }}
                    </button>
                </div>
            </div>

            <div v-else class="space-y-6">
                <template v-for="message in messages" :key="message.id">
                    <div v-if="message.role === 'user'" class="flex justify-end">
                        <div class="max-w-[85%] rounded-2xl rounded-br-md bg-accent px-4 py-2.5 text-sm whitespace-pre-wrap text-white">
                            {{ message.content }}
                        </div>
                    </div>
                    <AssistantMessage v-else :message="message" />
                </template>
            </div>
        </div>
    </div>

    <div class="border-t border-line bg-panel px-4 py-3 sm:px-6">
        <form class="mx-auto flex w-full max-w-3xl items-end gap-2" @submit.prevent="submit()">
            <textarea
                ref="textarea"
                v-model="draft"
                rows="1"
                class="max-h-50 min-h-11 flex-1 resize-none rounded-xl border border-line bg-canvas px-4 py-2.5 text-sm outline-none placeholder:text-muted focus:border-accent"
                :placeholder="readyCount === 0 ? 'Upload a document first…' : 'Ask a question…  (Shift+Enter for a new line)'"
                :disabled="readyCount === 0"
                @input="autoGrow"
                @keydown="onKeydown"
            />
            <button
                v-if="isStreaming"
                type="button"
                class="h-11 rounded-xl border border-line px-4 text-sm font-medium hover:bg-canvas"
                @click="emit('stop')"
            >
                Stop
            </button>
            <button
                v-else
                type="submit"
                class="h-11 rounded-xl bg-accent px-4 text-sm font-medium text-white transition disabled:opacity-40"
                :disabled="!draft.trim() || readyCount === 0"
            >
                Send
            </button>
        </form>
    </div>
</template>
