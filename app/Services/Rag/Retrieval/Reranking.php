<?php

namespace App\Services\Rag\Retrieval;

final class Reranking
{
    /**
     * @param  list<RetrievedChunk>  $chunks  The top results, best first.
     * @param  array{input_tokens: int, output_tokens: int}|null  $usage  LLM tokens used, or null if none.
     */
    public function __construct(
        public readonly array $chunks,
        public readonly ?array $usage = null,
    ) {}
}
