<?php

namespace App\Services\Rag\Evaluation;

use App\Enums\DocumentStatus;
use App\Models\Chunk;
use App\Models\Document;
use App\Services\Rag\DocumentIngestor;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Indexes an evaluation corpus with the real ingestion pipeline inside a
 * database transaction that is always rolled back, so evaluating never touches
 * the user's own documents.
 */
class EvaluationCorpus
{
    public function __construct(private readonly DocumentIngestor $ingestor) {}

    /**
     * @template TResult
     *
     * @param  list<string>  $files  Absolute paths of the documents to index.
     * @param  callable(array<string, int> $documentIds, int $chunkCount): TResult  $callback  Receives corpus file name => document id.
     * @return TResult
     */
    public function using(array $files, callable $callback): mixed
    {
        $directory = 'eval-'.Str::uuid();

        DB::beginTransaction();

        try {
            $documentIds = $this->index($files, $directory);

            return $callback($documentIds, Chunk::query()->whereIn('document_id', $documentIds)->count());
        } finally {
            DB::rollBack();
            Storage::disk('local')->deleteDirectory($directory);
        }
    }

    /**
     * @param  list<string>  $files
     * @return array<string, int>
     */
    private function index(array $files, string $directory): array
    {
        $documentIds = [];

        foreach ($files as $file) {
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
}
