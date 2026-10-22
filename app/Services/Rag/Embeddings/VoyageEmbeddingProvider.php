<?php

namespace App\Services\Rag\Embeddings;

use App\Services\Rag\Support\Vector;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Voyage AI embeddings (https://docs.voyageai.com/reference/embeddings-api).
 */
class VoyageEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly string $url,
        private readonly int $batchSize = 64,
    ) {}

    public function embedDocuments(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            array_push($vectors, ...$this->request($batch, 'document'));
        }

        return $vectors;
    }

    public function embedQuery(string $text): array
    {
        return $this->request([$text], 'query')[0];
    }

    public function modelName(): string
    {
        return 'voyage:'.$this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @param  list<string>  $input
     * @return list<list<float>>
     */
    private function request(array $input, string $inputType): array
    {
        if (blank($this->apiKey)) {
            throw new EmbeddingConfigurationException('VOYAGE_API_KEY is not set. Add it to .env or switch EMBEDDING_PROVIDER.');
        }

        $response = Http::withToken($this->apiKey)
            ->acceptJson()
            ->timeout(60)
            ->retry([500, 2000, 5000], when: self::isTransient(...), throw: false)
            ->post($this->url, [
                'input' => $input,
                'model' => $this->model,
                'input_type' => $inputType,
                'output_dimension' => $this->dimensions,
            ]);

        if ($response->unauthorized() || $response->forbidden()) {
            throw new EmbeddingConfigurationException(
                "Voyage rejected the API key (HTTP {$response->status()}). Check VOYAGE_API_KEY in .env.",
            );
        }

        $response->throw();

        $data = collect($response->json('data'))->sortBy('index')->values();

        if ($data->count() !== count($input)) {
            throw new RuntimeException('Voyage returned an unexpected number of embeddings.');
        }

        return $data
            ->map(fn (array $row): array => Vector::normalize(array_map('floatval', $row['embedding'])))
            ->all();
    }

    /**
     * Retry rate limits, server errors and dropped connections; never retry a 4xx caused by the request itself.
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
