<?php

namespace App\Console\Commands;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Document;
use App\Services\Rag\Embeddings\EmbeddingProvider;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rag:reindex {--stale : Only documents embedded with a different model than the current one} {--queue : Dispatch to the queue instead of processing inline}')]
#[Description('Re-chunk and re-embed documents, e.g. after changing the embedding model or chunk size')]
class ReindexDocuments extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(EmbeddingProvider $embeddings): int
    {
        $currentModel = $embeddings->modelName();

        $documents = Document::query()
            ->when($this->option('stale'), fn ($query) => $query->where(
                fn ($where) => $where->whereNull('embedding_model')->orWhere('embedding_model', '!=', $currentModel),
            ))
            ->orderBy('id')
            ->get();

        if ($documents->isEmpty()) {
            $this->components->info('Nothing to reindex.');

            return self::SUCCESS;
        }

        $this->components->info("Reindexing {$documents->count()} document(s) with [{$currentModel}].");

        foreach ($documents as $document) {
            if ($this->option('queue')) {
                ProcessDocument::dispatch($document);
                $this->components->twoColumnDetail($document->title, 'queued');

                continue;
            }

            $this->components->task($document->title, function () use ($document): bool {
                ProcessDocument::dispatchSync($document);

                return $document->refresh()->status === DocumentStatus::Ready;
            });
        }

        return self::SUCCESS;
    }
}
