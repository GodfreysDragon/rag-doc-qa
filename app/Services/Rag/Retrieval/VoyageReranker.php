<?php

namespace App\Services\Rag\Retrieval;

use App\Services\Rag\Embeddings\EmbeddingConfigurationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Voyage AI cross-encoder re-ranking (https://docs.voyageai.com/reference/reranker-api).
 * Voyage bills these by its own tokens, so none count against the LLM budget.
 */
class VoyageReranker implements Reranker
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $url,
    ) {}

    public function rerank(string $query, array $chunks, int $topK): Reranking
    {
        if (blank($this->apiKey)) {
            throw new EmbeddingConfigurationException('VOYAGE_API_KEY is not set. Add it to .env or switch RAG_RERANKER.');
        }

        if ($chunks === []) {
            return new Reranking([]);
        }

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(30)
            ->retry([500, 2000], when: self::isTransient(...), throw: false)
            ->post($this->url, [
                'query' => $query,
                'documents' => array_map(fn (RetrievedChunk $chunk): string => $chunk->content, $chunks),
                'model' => $this->model,
                'top_k' => $topK,
            ]);

        if ($response->unauthorized() || $response->forbidden()) {
            throw new EmbeddingConfigurationException(
                "Voyage rejected the API key (HTTP {$response->status()}). Check VOYAGE_API_KEY in .env.",
            );
        }

        $response->throw();

        $ranked = collect($response->json('data'))
            ->sortByDesc('relevance_score')
            ->map(fn (array $row): RetrievedChunk => $chunks[$row['index']] ?? throw new RuntimeException('Voyage returned an unknown document index.'))
            ->values()
            ->all();

        return new Reranking(array_slice($ranked, 0, $topK));
    }

    public function tokenCeiling(int $candidates): int
    {
        return 0;
    }

    public function name(): string
    {
        return 'voyage:'.$this->model;
    }

    /**
     * Retry rate limits, server errors and dropped connections.
     */
    private static function isTransient(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && ($exception->response->status() === 429 || $exception->response->serverError());
    }
}
