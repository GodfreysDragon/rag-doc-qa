import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { api } from '../lib/api';

const POLL_MS = 2000;

export function useDocuments() {
    const documents = ref([]);
    const uploads = ref([]);
    const loading = ref(true);
    const error = ref(null);
    let pollTimer = null;

    const hasPending = computed(() =>
        documents.value.some((doc) => doc.status === 'pending' || doc.status === 'processing'),
    );

    const readyDocuments = computed(() => documents.value.filter((doc) => doc.status === 'ready'));

    async function load() {
        try {
            documents.value = await api.listDocuments();
            error.value = null;
        } catch (e) {
            error.value = e.message;
        } finally {
            loading.value = false;
        }
    }

    // Poll only while something is still being chunked/embedded.
    watch(hasPending, (pending) => {
        clearInterval(pollTimer);
        if (pending) {
            pollTimer = setInterval(load, POLL_MS);
        }
    });

    onBeforeUnmount(() => clearInterval(pollTimer));

    async function upload(fileList) {
        const files = Array.from(fileList);

        await Promise.all(
            files.map(async (file) => {
                const entry = { key: crypto.randomUUID(), name: file.name, progress: 0, error: null };
                uploads.value.push(entry);
                const tracked = uploads.value[uploads.value.length - 1];

                try {
                    const { document } = await api.uploadDocument(file, (p) => (tracked.progress = p));
                    if (!documents.value.some((doc) => doc.id === document.id)) {
                        documents.value.unshift(document);
                    }
                    uploads.value = uploads.value.filter((u) => u.key !== entry.key);
                } catch (e) {
                    tracked.error = e.message;
                }
            }),
        );
    }

    function dismissUpload(key) {
        uploads.value = uploads.value.filter((u) => u.key !== key);
    }

    async function remove(id) {
        const previous = documents.value;
        documents.value = documents.value.filter((doc) => doc.id !== id);

        try {
            await api.deleteDocument(id);
        } catch (e) {
            documents.value = previous;
            error.value = e.message;
        }
    }

    async function reprocess(id) {
        const updated = await api.reprocessDocument(id);
        documents.value = documents.value.map((doc) => (doc.id === id ? updated : doc));
    }

    return { documents, readyDocuments, uploads, loading, error, load, upload, dismissUpload, remove, reprocess };
}
