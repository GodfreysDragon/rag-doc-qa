<?php

namespace App\Services\Rag;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Rag\Chunking\TextChunker;
use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Extraction\TextExtractor;
use App\Services\Rag\Extraction\UnprocessableDocumentException;
use App\Services\Rag\VectorStores\VectorStore;
use Illuminate\Support\Facades\Storage;

/**
 * The index-time half of the RAG pipeline:
 *
 *   file ──► text (per page) ──► overlapping chunks ──► embeddings ──► vector store
 */
class DocumentIngestor
{
    public function __construct(
        private readonly TextExtractor $extractor,
        private readonly TextChunker $chunker,
        private readonly EmbeddingProvider $embeddings,
        private readonly VectorStore $store,
    ) {}

    public function ingest(Document $document): void
    {
        $document->update(['status' => DocumentStatus::Processing, 'error' => null]);

        $disk = Storage::disk('local');

        if (! $disk->exists($document->path)) {
            throw new UnprocessableDocumentException('The uploaded file is missing from storage.');
        }

        $extracted = $this->extractor->extract(
            $disk->path($document->path),
            pathinfo($document->original_name, PATHINFO_EXTENSION),
        );

        $chunks = $this->chunker->chunk($extracted);

        $vectors = $this->embeddings->embedDocuments(
            array_map(fn ($chunk): string => $this->embeddingInput($document, $chunk->content), $chunks),
        );

        $this->store->replaceDocumentChunks($document, $chunks, $vectors);

        $document->update([
            'status' => DocumentStatus::Ready,
            'page_count' => $extracted->pageCount,
            'chunk_count' => count($chunks),
            'embedding_model' => $this->embeddings->modelName(),
            'processed_at' => now(),
        ]);
    }

    /**
     * Prepend the document title to the text that gets embedded (not the
     * stored content). A chunk that says "the fee is 3%" is far more findable
     * when its vector also encodes which document it came from.
     */
    private function embeddingInput(Document $document, string $content): string
    {
        return "Document: {$document->title}\n\n{$content}";
    }
}
