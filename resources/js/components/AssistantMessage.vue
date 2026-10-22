<script setup>
import { computed, nextTick, ref } from 'vue';
import { renderAnswer } from '../lib/markdown';
import { claimForCitation } from '../lib/passages';
import SourceCard from './SourceCard.vue';

const props = defineProps({
    message: { type: Object, required: true },
});

const showSources = ref(false);
const highlighted = ref(null);
const focus = ref(null);
const sourceRefs = ref({});

const html = computed(() => renderAnswer(props.message.content, props.message.sources.length));

const citedIds = computed(() => {
    const ids = new Set();
    for (const match of props.message.content.matchAll(/\[(\d+)\]/g)) {
        ids.add(Number(match[1]));
    }
    return ids;
});

const stageLabel = computed(() =>
    props.message.stage === 'searching' ? 'Searching your documents…' : 'Reading sources…',
);

async function onAnswerClick(event) {
    const button = event.target.closest('.citation');
    if (!button) return;

    const id = Number(button.dataset.source);
    showSources.value = true;
    highlighted.value = id;

    focus.value = { id, claim: claimForCitation(button) };
    await nextTick();
    sourceRefs.value[id]?.reveal();
    setTimeout(() => {
        if (highlighted.value === id) highlighted.value = null;
    }, 2000);
}
</script>

<template>
    <div class="flex gap-3">
        <div class="mt-0.5 grid size-7 shrink-0 place-items-center rounded-full bg-accent-soft text-accent">
            <svg viewBox="0 0 24 24" fill="currentColor" class="size-3.5">
                <path d="M12 2l2.4 7.4H22l-6.2 4.5 2.4 7.4L12 16.8l-6.2 4.5 2.4-7.4L2 9.4h7.6z" />
            </svg>
        </div>

        <div class="min-w-0 flex-1">
            <p v-if="message.searchQuery" class="mb-2 truncate text-xs text-muted" :title="message.searchQuery">
                Searched for “{{ message.searchQuery }}”
            </p>

            <div
                v-if="message.content"
                class="prose-answer text-[0.94rem]"
                :class="{ 'typing-caret': message.status === 'streaming' }"
                @click="onAnswerClick"
                v-html="html"
            />
            <div v-else-if="message.status === 'streaming'" class="flex items-center gap-2 text-sm text-muted">
                <span class="size-2 animate-pulse rounded-full bg-accent" />
                {{ stageLabel }}
            </div>

            <p
                v-if="message.error"
                class="mt-2 rounded-lg border border-rose-500/30 bg-rose-500/10 px-3 py-2 text-sm text-rose-700 dark:text-rose-300"
            >
                {{ message.error }}
            </p>

            <div v-if="message.sources.length > 0" class="mt-3">
                <button
                    type="button"
                    class="inline-flex items-center gap-1.5 text-xs font-medium text-muted hover:text-ink"
                    :aria-expanded="showSources"
                    @click="showSources = !showSources"
                >
                    <svg
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        class="size-3.5 transition-transform"
                        :class="{ 'rotate-90': showSources }"
                    >
                        <path d="m9 6 6 6-6 6" />
                    </svg>
                    {{ message.sources.length }} sources retrieved
                    <span v-if="citedIds.size > 0" class="text-muted">· {{ citedIds.size }} cited</span>
                </button>

                <div v-if="showSources" class="mt-2 space-y-2">
                    <SourceCard
                        v-for="source in message.sources"
                        :key="source.chunk_id"
                        :ref="(el) => (sourceRefs[source.id] = el)"
                        :source="source"
                        :cited="citedIds.has(source.id)"
                        :highlighted="highlighted === source.id"
                        :claim="focus?.id === source.id ? focus.claim : null"
                    />
                </div>
            </div>

            <p v-if="message.usage" class="mt-2 text-[0.7rem] text-muted">
                {{ message.model }} · {{ message.usage.input_tokens.toLocaleString() }} in /
                {{ message.usage.output_tokens.toLocaleString() }} out tokens
            </p>
        </div>
    </div>
</template>
