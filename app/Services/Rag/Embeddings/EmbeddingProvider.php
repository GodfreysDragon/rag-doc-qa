<?php

namespace App\Services\Rag\Embeddings;

/**
 * Turns text into dense vectors.
 *
 * Documents and queries are embedded through separate methods because
 * retrieval-tuned models are asymmetric: a short question and the passage
 * that answers it look different, and models like Voyage and nomic-embed are
 * trained with a distinct "query" vs "document" mode to bridge that gap.
 */
interface EmbeddingProvider
{
    /**
     * @param  list<string>  $texts
     * @return list<list<float>> L2-normalised vectors, in input order.
     */
    public function embedDocuments(array $texts): array;

    /**
     * @return list<float> L2-normalised vector.
     */
    public function embedQuery(string $text): array;

    /**
     * Identifier stored alongside each document so stale indexes can be detected.
     */
    public function modelName(): string;

    public function dimensions(): int;
}
