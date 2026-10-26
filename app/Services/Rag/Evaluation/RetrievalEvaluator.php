<?php

namespace App\Services\Rag\Evaluation;

use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Retrieval\Reranker;
use App\Services\Rag\Retrieval\RetrievedChunk;
use App\Services\Rag\Retrieval\Retriever;
use App\Services\Rag\VectorStores\VectorStore;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Measures retrieval quality against a golden set of questions.
 *
 * The corpus is indexed by EvaluationCorpus inside a transaction that is
 * always rolled back. Each question is then run through vector-only,
 * keyword-only and both kinds of hybrid retrieval (rank fusion and weighted
 * score fusion), optionally followed by a re-ranker, recording the rank of the
 * first chunk that comes from the expected document and contains the expected
 * phrase.
 */
class RetrievalEvaluator
{
    public const MODES = ['vector', 'keyword', 'rrf', 'weighted'];

    public function __construct(
        private readonly EvaluationCorpus $corpus,
        private readonly EmbeddingProvider $embeddings,
        private readonly VectorStore $store,
    ) {}

    /**
     * @param  list<string>  $corpusFiles  Absolute paths of the documents to index.
     * @param  list<array{category: string, question: string, document: string, expect: string}>  $cases
     * @param  Reranker|null  $reranker  Also evaluate weighted fusion followed by this re-ranker, as mode "reranked".
     * @return array{chunk_count: int, rerank_usage: array{input_tokens: int, output_tokens: int}, results: list<array{category: string, question: string, document: string, expect: string, ranks: array<string, int|null>}>}
     */
    public function evaluate(array $corpusFiles, array $cases, int $topK, int $candidates, int $rrfK, float $vectorWeight, ?Reranker $reranker = null): array
    {
        return $this->corpus->using($corpusFiles, function (array $documentIds, int $chunkCount) use ($cases, $topK, $candidates, $rrfK, $vectorWeight, $reranker): array {
            $scope = array_values($documentIds);
            $rerankUsage = ['input_tokens' => 0, 'output_tokens' => 0];

            $hybrid = fn (string $fusion, int $limit = 0): Retriever => new Retriever(
                $this->embeddings, $this->store, topK: $limit ?: $topK, candidates: $candidates, rrfK: $rrfK, fusion: $fusion, vectorWeight: $vectorWeight,
            );

            $retrievers = [
                'vector' => fn (string $question): array => (new Retriever($this->embeddings, $this->store, topK: $topK, candidates: $candidates, hybrid: false))
                    ->retrieve($question, $scope),
                'keyword' => fn (string $question): array => array_slice($this->store->keywordSearch($question, $candidates, $scope), 0, $topK),
                'rrf' => fn (string $question): array => $hybrid('rrf')->retrieve($question, $scope),
                'weighted' => fn (string $question): array => $hybrid('weighted')->retrieve($question, $scope),
            ];

            // Called directly rather than through Retriever, which falls back to the
            // fused order on errors: a failed re-ranking must fail the evaluation.
            if ($reranker !== null) {
                $retrievers['reranked'] = function (string $question) use ($hybrid, $reranker, $scope, $candidates, $topK, &$rerankUsage): array {
                    $reranking = $reranker->rerank($question, $hybrid('weighted', $candidates)->retrieve($question, $scope), $topK);

                    foreach ($reranking->usage ?? [] as $kind => $tokens) {
                        $rerankUsage[$kind] += $tokens;
                    }

                    return $reranking->chunks;
                };
            }

            $results = [];

            foreach ($cases as $case) {
                $documentId = self::documentIdFor($case, $documentIds);
                $ranks = [];

                foreach ($retrievers as $mode => $retrieve) {
                    $ranks[$mode] = $this->rankOfExpected($retrieve($case['question']), $documentId, $case['expect']);
                }

                $results[] = [...$case, 'ranks' => $ranks];
            }

            return ['chunk_count' => $chunkCount, 'rerank_usage' => $rerankUsage, 'results' => $results];
        });
    }

    /**
     * Hit rate at 1, 3 and k, and mean reciprocal rank (0 for a miss).
     *
     * @param  list<int|null>  $ranks
     * @return array{hit_at_1: float, hit_at_3: float, hit_at_k: float, mrr: float}
     */
    public static function metrics(array $ranks): array
    {
        $count = max(count($ranks), 1);
        $hitsWithin = fn (int $limit): float => count(array_filter($ranks, fn (?int $rank): bool => $rank !== null && $rank <= $limit)) / $count;

        return [
            'hit_at_1' => $hitsWithin(1),
            'hit_at_3' => $hitsWithin(3),
            'hit_at_k' => count(array_filter($ranks, fn (?int $rank): bool => $rank !== null)) / $count,
            'mrr' => array_sum(array_map(fn (?int $rank): float => $rank === null ? 0.0 : 1 / $rank, $ranks)) / $count,
        ];
    }

    /**
     * @param  array{question: string, document: string}  $case
     * @param  array<string, int>  $documentIds
     */
    public static function documentIdFor(array $case, array $documentIds): int
    {
        return $documentIds[$case['document']]
            ?? throw new InvalidArgumentException("Case \"{$case['question']}\" refers to [{$case['document']}], which is not in the corpus.");
    }

    /**
     * Whether chunk text contains the expected phrase, ignoring case and whitespace.
     */
    public static function containsExpected(string $content, string $expect): bool
    {
        return str_contains(self::normalize($content), self::normalize($expect));
    }

    /**
     * @param  list<RetrievedChunk>  $chunks
     */
    private function rankOfExpected(array $chunks, int $documentId, string $expect): ?int
    {
        foreach ($chunks as $index => $chunk) {
            if ($chunk->documentId === $documentId && self::containsExpected($chunk->content, $expect)) {
                return $index + 1;
            }
        }

        return null;
    }

    private static function normalize(string $text): string
    {
        return Str::of($text)->lower()->squish()->toString();
    }
}
