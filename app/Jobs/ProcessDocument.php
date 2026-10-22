<?php

namespace App\Jobs;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Rag\DocumentIngestor;
use App\Services\Rag\Embeddings\EmbeddingConfigurationException;
use App\Services\Rag\Extraction\UnprocessableDocumentException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessDocument implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    /**
     * Keep below the queue connection's retry_after (DB_QUEUE_RETRY_AFTER=360 in .env).
     */
    public int $timeout = 300;

    public function __construct(public Document $document) {}

    public function uniqueId(): string
    {
        return (string) $this->document->id;
    }

    /**
     * Ingestion is idempotent: chunks for the document are replaced in a
     * single transaction, so a retry never leaves duplicates behind.
     */
    public function handle(DocumentIngestor $ingestor): void
    {
        try {
            $ingestor->ingest($this->document);
        } catch (UnprocessableDocumentException|EmbeddingConfigurationException $exception) {
            $this->fail($exception);
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->document->update([
            'status' => DocumentStatus::Failed,
            'error' => str($exception?->getMessage() ?? 'Processing failed.')->limit(1000)->toString(),
        ]);
    }
}
