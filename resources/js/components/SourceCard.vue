<script setup>
import { computed, ref } from 'vue';
import { findSupportingPassage } from '../lib/passages';

const props = defineProps({
    source: { type: Object, required: true },
    cited: { type: Boolean, default: false },
    highlighted: { type: Boolean, default: false },
    claim: { type: String, default: null },
});

const PREVIEW_LENGTH = 320;

const expanded = ref(false);
const passageEl = ref(null);

const passage = computed(() => (props.claim ? findSupportingPassage(props.source.content, props.claim) : null));

const parts = computed(() => {
    const text = props.source.content;

    // The passage can sit past the preview cut-off, so a highlight shows the whole chunk.
    if (passage.value) {
        return {
            before: text.slice(0, passage.value.start),
            passage: text.slice(passage.value.start, passage.value.end),
            after: text.slice(passage.value.end),
        };
    }

    const preview = expanded.value || text.length <= PREVIEW_LENGTH ? text : `${text.slice(0, PREVIEW_LENGTH).trimEnd()}…`;

    return { before: preview, passage: null, after: '' };
});

const cardEl = ref(null);

/**
 * Scroll the highlighted passage into view, or the card itself when the
 * claim couldn't be matched to a passage.
 */
function reveal() {
    if (passageEl.value) {
        passageEl.value.scrollIntoView({ behavior: 'smooth', block: 'center' });
    } else {
        cardEl.value?.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
}

defineExpose({ reveal });

const similarityPct = computed(() =>
    props.source.similarity === null ? null : Math.max(0, Math.round(props.source.similarity * 100)),
);
</script>

<template>
    <div
        ref="cardEl"
        class="rounded-xl border bg-panel p-3 text-sm transition"
        :class="highlighted ? 'border-accent ring-2 ring-accent/30' : 'border-line'"
    >
        <div class="flex items-center gap-2">
            <span
                class="grid size-5 shrink-0 place-items-center rounded text-[0.7rem] font-semibold"
                :class="cited ? 'bg-accent text-white' : 'bg-canvas text-muted'"
            >
                {{ source.id }}
            </span>
            <span class="truncate font-medium">{{ source.document_title }}</span>
            <span v-if="source.page_number" class="shrink-0 text-xs text-muted">p. {{ source.page_number }}</span>
            <span class="ml-auto flex shrink-0 items-center gap-2 text-[0.7rem] text-muted">
                <span v-if="similarityPct !== null" class="flex items-center gap-1" title="Cosine similarity to the query">
                    <span class="h-1 w-10 overflow-hidden rounded-full bg-line">
                        <span class="block h-full bg-accent" :style="{ width: `${similarityPct}%` }" />
                    </span>
                    {{ source.similarity.toFixed(2) }}
                </span>
                <span
                    v-if="source.keyword_score !== null"
                    class="rounded bg-canvas px-1.5 py-0.5"
                    title="Also matched by keyword search"
                >
                    keyword
                </span>
            </span>
        </div>
        <p class="mt-2 leading-relaxed whitespace-pre-line text-muted">{{ parts.before }}<mark
                v-if="parts.passage"
                ref="passageEl"
                class="rounded bg-accent-soft px-0.5 text-ink"
            >{{ parts.passage }}</mark>{{ parts.after }}</p>
        <button
            v-if="!parts.passage && source.content.length > PREVIEW_LENGTH"
            type="button"
            class="mt-1 text-xs text-accent hover:underline"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Show less' : 'Show full chunk' }}
        </button>
    </div>
</template>
