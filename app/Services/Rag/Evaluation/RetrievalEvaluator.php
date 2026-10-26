<?php

namespace App\Services\Rag\Evaluation;

use App\Services\Rag\Embeddings\EmbeddingProvider;
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
 * keyword-only and hybrid retrieval, recording the rank of the first chunk
 * that comes from the expected document and contains the expected phrase.
 */
class RetrievalEvaluator
{
    public const MODES = ['vector', 'keyword', 'hybrid'];

    public function __construct(
        private readonly EvaluationCorpus $corpus,
        private readonly EmbeddingProvider $embeddings,
        private readonly VectorStore $store,
    ) {}

    /**
     * @param  list<string>  $corpusFiles  Absolute paths of the documents to index.
     * @param  list<array{category: string, question: string, document: string, expect: string}>  $cases
     * @return array{chunk_count: int, results: list<array{category: string, question: string, document: string, expect: string, ranks: array<string, int|null>}>}
     */
    public function evaluate(array $corpusFiles, array $cases, int $topK, int $candidates, int $rrfK): array
    {
        return $this->corpus->using($corpusFiles, function (array $documentIds, int $chunkCount) use ($cases, $topK, $candidates, $rrfK): array {
            $scope = array_values($documentIds);

            $retrievers = [
                'vector' => fn (string $question): array => (new Retriever($this->embeddings, $this->store, topK: $topK, candidates: $candidates, hybrid: false))
                    ->retrieve($question, $scope),
                'keyword' => fn (string $question): array => array_slice($this->store->keywordSearch($question, $candidates, $scope), 0, $topK),
                'hybrid' => fn (string $question): array => (new Retriever($this->embeddings, $this->store, topK: $topK, candidates: $candidates, hybrid: true, rrfK: $rrfK))
                    ->retrieve($question, $scope),
            ];

            $results = [];

            foreach ($cases as $case) {
                $documentId = self::documentIdFor($case, $documentIds);
                $ranks = [];

                foreach ($retrievers as $mode => $retrieve) {
                    $ranks[$mode] = $this->rankOfExpected($retrieve($case['question']), $documentId, $case['expect']);
                }

                $results[] = [...$case, 'ranks' => $ranks];
            }

            return ['chunk_count' => $chunkCount, 'results' => $results];
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
