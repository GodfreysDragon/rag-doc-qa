<?php

namespace App\Services\Rag\Retrieval;

/**
 * Re-orders fused retrieval candidates by reading the query and each passage
 * together, which first-stage retrieval (one vector per text, word overlap)
 * can't do.
 */
interface Reranker
{
    /**
     * @param  list<RetrievedChunk>  $chunks  Candidates in fused order.
     */
    public function rerank(string $query, array $chunks, int $topK): Reranking;

    /**
     * An upper estimate of the LLM tokens one re-ranking of this many
     * candidates uses, for reserving token budget; 0 if it uses none.
     */
    public function tokenCeiling(int $candidates): int;

    public function name(): string;
}
