<?php

namespace App\Services\Rag\Evaluation;

use App\Enums\DocumentStatus;
use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\DocumentIngestor;
use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Retrieval\RetrievedChunk;
use App\Services\Rag\Retrieval\Retriever;
use App\Services\Rag\VectorStores\VectorStore;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Measures retrieval quality against a golden set of questions.
 *
 * The corpus is indexed with the real ingestion pipeline inside a database
 * transaction that is always rolled back, so evaluating never touches the
 * user's own documents. Each question is then run through vector-only,
 * keyword-only and hybrid retrieval, recording the rank of the first chunk
 * that comes from the expected document and contains the expected phrase.
 */
class RetrievalEvaluator
{
    public const MODES = ['vector', 'keyword', 'hybrid'];

    public function __construct(
        private readonly DocumentIngestor $ingestor,
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
        $directory = 'eval-'.Str::uuid();

        DB::beginTransaction();

        try {
            $documentIds = $this->index($corpusFiles, $directory);
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
                $documentId = $documentIds[$case['document']]
                    ?? throw new InvalidArgumentException("Case \"{$case['question']}\" refers to [{$case['document']}], which is not in the corpus.");

                $ranks = [];

                foreach ($retrievers as $mode => $retrieve) {
                    $ranks[$mode] = $this->rankOfExpected($retrieve($case['question']), $documentId, $case['expect']);
                }

                $results[] = [...$case, 'ranks' => $ranks];
            }

            return [
                'chunk_count' => Chunk::query()->whereIn('document_id', $scope)->count(),
                'results' => $results,
            ];
        } finally {
            DB::rollBack();
            Storage::disk('local')->deleteDirectory($directory);
        }
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
     * @param  list<string>  $corpusFiles
     * @return array<string, int> Corpus file name => document id.
     */
    private function index(array $corpusFiles, string $directory): array
    {
        $documentIds = [];

        foreach ($corpusFiles as $file) {
            $name = basename($file);

            $document = Document::create([
                'title' => str(pathinfo($name, PATHINFO_FILENAME))->replace(['_', '-'], ' ')->squish()->toString(),
                'original_name' => $name,
                'path' => Storage::disk('local')->putFileAs($directory, new File($file), $name),
                'mime_type' => 'text/plain',
                'size_bytes' => filesize($file),
                // Not the file hash: the user may have uploaded the same sample file, and content_hash is unique.
                'content_hash' => hash('sha256', "{$directory}/{$name}"),
                'status' => DocumentStatus::Pending,
            ]);

            $this->ingestor->ingest($document);
            $documentIds[$name] = $document->id;
        }

        return $documentIds;
    }

    /**
     * @param  list<RetrievedChunk>  $chunks
     */
    private function rankOfExpected(array $chunks, int $documentId, string $expect): ?int
    {
        $needle = $this->normalize($expect);

        foreach ($chunks as $index => $chunk) {
            if ($chunk->documentId === $documentId && str_contains($this->normalize($chunk->content), $needle)) {
                return $index + 1;
            }
        }

        return null;
    }

    private function normalize(string $text): string
    {
        return Str::of($text)->lower()->squish()->toString();
    }
}
