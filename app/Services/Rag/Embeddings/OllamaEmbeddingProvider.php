<?php

namespace App\Services\Rag\Embeddings;

use App\Services\Rag\Support\Vector;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Local embeddings through Ollama's /api/embed endpoint — free and private.
 */
class OllamaEmbeddingProvider implements EmbeddingProvider
{
    public function __construct(
        private readonly string $url,
        private readonly string $model,
        private readonly int $dimensions,
        private readonly string $documentPrefix = '',
        private readonly string $queryPrefix = '',
        private readonly int $batchSize = 64,
    ) {}

    public function embedDocuments(array $texts): array
    {
        $vectors = [];

        foreach (array_chunk($texts, $this->batchSize) as $batch) {
            $prefixed = array_map(fn (string $text): string => $this->documentPrefix.$text, $batch);
            array_push($vectors, ...$this->request($prefixed));
        }

        return $vectors;
    }

    public function embedQuery(string $text): array
    {
        return $this->request([$this->queryPrefix.$text])[0];
    }

    public function modelName(): string
    {
        return 'ollama:'.$this->model;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @param  list<string>  $input
     * @return list<list<float>>
     */
    private function request(array $input): array
    {
        $embeddings = Http::baseUrl($this->url)
            ->acceptJson()
            ->timeout(120)
            ->post('/api/embed', ['model' => $this->model, 'input' => $input])
            ->throw()
            ->json('embeddings');

        if (! is_array($embeddings) || count($embeddings) !== count($input)) {
            throw new RuntimeException('Ollama returned an unexpected embeddings payload.');
        }

        return array_map(
            fn (array $vector): array => Vector::normalize(array_map('floatval', $vector)),
            $embeddings,
        );
    }
}
