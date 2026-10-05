import { computed, ref } from 'vue';
import { postEventStream } from '../lib/sse';

export function useChat() {
    const messages = ref([]);
    let controller = null;

    const isStreaming = computed(() => messages.value.some((m) => m.status === 'streaming'));

    /**
     * Only completed turns become history. The server re-retrieves sources for
     * every question, so history carries the conversation, not old context.
     */
    function history() {
        return messages.value
            .filter((m) => m.status === 'done' && m.content.trim() !== '')
            .map((m) => ({ role: m.role, content: m.content }));
    }

    async function ask(question, documentIds = null) {
        const priorTurns = history();

        messages.value.push({ id: crypto.randomUUID(), role: 'user', content: question, status: 'done' });
        messages.value.push({
            id: crypto.randomUUID(),
            role: 'assistant',
            content: '',
            status: 'streaming',
            stage: 'searching',
            searchQuery: null,
            sources: [],
            usage: null,
            model: null,
            error: null,
        });
        const reply = messages.value[messages.value.length - 1];

        controller = new AbortController();

        try {
            await postEventStream(
                '/api/v1/chat',
                { question, history: priorTurns, document_ids: documentIds },
                {
                    signal: controller.signal,
                    onEvent(type, data) {
                        switch (type) {
                            case 'query':
                                reply.searchQuery = data.query;
                                break;
                            case 'sources':
                                reply.sources = data.sources;
                                reply.stage = 'answering';
                                break;
                            case 'text':
                                reply.content += data.text;
                                break;
                            case 'reset':
                                reply.content = '';
                                break;
                            case 'done':
                                reply.usage = data.usage;
                                reply.model = data.model;
                                reply.status = data.stop_reason === 'refusal' ? 'error' : 'done';
                                if (data.stop_reason === 'refusal') {
                                    reply.error = 'The model declined to answer this request.';
                                }
                                break;
                            case 'error':
                                reply.status = 'error';
                                reply.error = data.message;
                                break;
                        }
                    },
                },
            );

            if (reply.status === 'streaming') {
                reply.status = 'done';
            }
        } catch (e) {
            if (e.name === 'AbortError') {
                reply.status = reply.content ? 'done' : 'error';
                reply.error = reply.content ? null : 'Stopped.';
            } else {
                reply.status = 'error';
                reply.error = e.message;
            }
        } finally {
            controller = null;
        }
    }

    function stop() {
        controller?.abort();
    }

    function reset() {
        stop();
        messages.value = [];
    }

    return { messages, isStreaming, ask, stop, reset };
}
