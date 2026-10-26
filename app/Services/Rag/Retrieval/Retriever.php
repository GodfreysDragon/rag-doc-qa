<?php

namespace App\Services\Rag\Retrieval;

use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\VectorStores\VectorStore;
use InvalidArgumentException;

/**
 * Hybrid retrieval: dense vector search + lexical keyword search, fused into
 * one ranking.
 *
 * "weighted" (the default) is relative score fusion: each ranking's scores
 * are min-max scaled to 0–1 and combined as w·vector + (1 − w)·keyword, so a
 * chunk that is the clear best on one side keeps most of that lead. "rrf" is
 * Reciprocal Rank Fusion (Cormack et al., 2009), Σ 1 / (k + rank); it only
 * uses ranks, but it rewards any chunk that appears in both lists, so a weak
 * keyword match can push the top vector hit out of the results.
 */
class Retriever
{
    public const FUSIONS = ['weighted', 'rrf'];

    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly VectorStore $store,
        private readonly int $topK = 6,
        private readonly int $candidates = 20,
        private readonly bool $hybrid = true,
        private readonly int $rrfK = 60,
        private readonly float $minScore = 0.0,
        private readonly string $fusion = 'weighted',
        private readonly float $vectorWeight = 0.6,
    ) {
        if (! in_array($fusion, self::FUSIONS, true)) {
            throw new InvalidArgumentException("Unknown fusion [{$fusion}]; use one of: ".implode(', ', self::FUSIONS).'.');
        }

        if ($vectorWeight < 0 || $vectorWeight > 1) {
            throw new InvalidArgumentException('The vector weight must be between 0 and 1.');
        }
    }

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

        $fused = $this->fusion === 'rrf'
            ? $this->fuseByRank([$vectorHits, $keywordHits])
            : $this->fuseByScore($vectorHits, $keywordHits);

        return array_slice($fused, 0, $this->topK);
    }

    /**
     * Reciprocal Rank Fusion.
     *
     * @param  list<list<RetrievedChunk>>  $rankings
     * @return list<RetrievedChunk>
     */
    public function fuseByRank(array $rankings): array
    {
        $merged = [];

        foreach ($rankings as $ranking) {
            foreach ($ranking as $rank => $chunk) {
                self::accumulate($merged, $chunk, 1 / ($this->rrfK + $rank + 1));
            }
        }

        return self::sorted($merged);
    }

    /**
     * Relative score fusion. A chunk missing from one ranking scores 0 on that side.
     *
     * @param  list<RetrievedChunk>  $vectorHits
     * @param  list<RetrievedChunk>  $keywordHits
     * @return list<RetrievedChunk>
     */
    public function fuseByScore(array $vectorHits, array $keywordHits): array
    {
        $merged = [];
        $sides = [
            [$vectorHits, $this->vectorWeight, fn (RetrievedChunk $chunk): float => (float) $chunk->similarity],
            [$keywordHits, 1 - $this->vectorWeight, fn (RetrievedChunk $chunk): float => (float) $chunk->keywordScore],
        ];

        foreach ($sides as [$hits, $weight, $score]) {
            $scaled = self::minMax(array_map($score, $hits));

            foreach ($hits as $index => $chunk) {
                self::accumulate($merged, $chunk, $weight * $scaled[$index]);
            }
        }

        return self::sorted($merged);
    }

    /**
     * @param  array<int, RetrievedChunk>  $merged
     */
    private static function accumulate(array &$merged, RetrievedChunk $chunk, float $score): void
    {
        $existing = $merged[$chunk->chunkId] ?? null;

        if ($existing === null) {
            $merged[$chunk->chunkId] = $existing = $chunk;
        } else {
            $existing->similarity ??= $chunk->similarity;
            $existing->keywordScore ??= $chunk->keywordScore;
        }

        $existing->score += $score;
    }

    /**
     * @param  array<int, RetrievedChunk>  $merged
     * @return list<RetrievedChunk>
     */
    private static function sorted(array $merged): array
    {
        $results = array_values($merged);
        usort($results, fn (RetrievedChunk $a, RetrievedChunk $b): int => $b->score <=> $a->score);

        return $results;
    }

    /**
     * Scale scores to 0–1, best = 1. Equal scores (including a single hit) all become 1.
     *
     * @param  list<float>  $scores
     * @return list<float>
     */
    private static function minMax(array $scores): array
    {
        if ($scores === []) {
            return [];
        }

        $min = min($scores);
        $range = max($scores) - $min;

        return array_map(fn (float $score): float => $range > 0 ? ($score - $min) / $range : 1.0, $scores);
    }
}
