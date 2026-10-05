<?php

namespace App\Services\Rag\Retrieval;

use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\VectorStores\VectorStore;

/**
 * Hybrid retrieval: dense vector search + lexical keyword search, merged with
 * Reciprocal Rank Fusion (Cormack et al., 2009).
 *
 * RRF scores each chunk by Σ 1 / (k + rank) across the result lists it
 * appears in. It only uses ranks, so there's no need to calibrate cosine
 * similarities against ts_rank scores, which live on unrelated scales.
 */
class Retriever
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly VectorStore $store,
        private readonly int $topK = 6,
        private readonly int $candidates = 20,
        private readonly bool $hybrid = true,
        private readonly int $rrfK = 60,
        private readonly float $minScore = 0.0,
    ) {}

    /**
     * @param  list<int>|null  $documentIds
     * @return list<RetrievedChunk>
     */
    public function retrieve(string $query, ?array $documentIds = null): array
    {
        $queryVector = $this->embeddings->embedQuery($query);

        $vectorHits = array_values(array_filter(
            $this->store->similaritySearch($queryVector, $this->candidates, $documentIds),
            fn (RetrievedChunk $chunk): bool => $chunk->similarity >= $this->minScore,
        ));

        if (! $this->hybrid) {
            foreach ($vectorHits as $chunk) {
                $chunk->score = (float) $chunk->similarity;
            }

            return array_slice($vectorHits, 0, $this->topK);
        }

        $keywordHits = $this->store->keywordSearch($query, $this->candidates, $documentIds);

        return array_slice($this->fuse([$vectorHits, $keywordHits]), 0, $this->topK);
    }

    /**
     * @param  list<list<RetrievedChunk>>  $rankings
     * @return list<RetrievedChunk>
     */
    public function fuse(array $rankings): array
    {
        /** @var array<int, RetrievedChunk> $merged */
        $merged = [];

        foreach ($rankings as $ranking) {
            foreach ($ranking as $rank => $chunk) {
                $existing = $merged[$chunk->chunkId] ?? null;

                if ($existing === null) {
                    $merged[$chunk->chunkId] = $existing = $chunk;
                } else {
                    $existing->similarity ??= $chunk->similarity;
                    $existing->keywordScore ??= $chunk->keywordScore;
                }

                $existing->score += 1 / ($this->rrfK + $rank + 1);
            }
        }

        $results = array_values($merged);
        usort($results, fn (RetrievedChunk $a, RetrievedChunk $b): int => $b->score <=> $a->score);

        return $results;
    }
}
