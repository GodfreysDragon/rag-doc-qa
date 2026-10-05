<script setup>
import { computed, ref } from 'vue';

const props = defineProps({
    source: { type: Object, required: true },
    cited: { type: Boolean, default: false },
    highlighted: { type: Boolean, default: false },
});

const expanded = ref(false);

const preview = computed(() => {
    const text = props.source.content;
    return expanded.value || text.length <= 320 ? text : `${text.slice(0, 320).trimEnd()}…`;
});

const similarityPct = computed(() =>
    props.source.similarity === null ? null : Math.max(0, Math.round(props.source.similarity * 100)),
);
</script>

<template>
    <div
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
        <p class="mt-2 leading-relaxed whitespace-pre-line text-muted">{{ preview }}</p>
        <button
            v-if="source.content.length > 320"
            type="button"
            class="mt-1 text-xs text-accent hover:underline"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'Show less' : 'Show full chunk' }}
        </button>
    </div>
</template>
