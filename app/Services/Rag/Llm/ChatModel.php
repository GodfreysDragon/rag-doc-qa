<?php

namespace App\Services\Rag\Llm;

use Generator;

interface ChatModel
{
    /**
     * Stream a response.
     *
     * Yields, in order:
     *   - zero or more ['type' => 'text', 'text' => string]
     *   - ['type' => 'reset'] if a partial answer must be discarded (e.g. a
     *     server-side fallback to another model took over mid-stream)
     *   - exactly one final ['type' => 'done', 'stop_reason' => string|null, 'model' => string, 'usage' => array{input_tokens: int, output_tokens: int}]
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     * @return Generator<int, array<string, mixed>>
     */
    public function stream(string $system, array $messages): Generator;

    /**
     * Single non-streaming completion returning the text of the reply.
     *
     * @param  list<array{role: 'user'|'assistant', content: string}>  $messages
     */
    public function complete(string $system, array $messages, int $maxTokens = 2000): string;
}
