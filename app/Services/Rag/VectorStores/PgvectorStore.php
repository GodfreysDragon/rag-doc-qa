<?php

namespace App\Services\Rag\VectorStores;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Services\Rag\Retrieval\RetrievedChunk;
use App\Services\Rag\Support\Keywords;
use App\Services\Rag\Support\Vector;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * PostgreSQL + pgvector. Similarity search is pushed entirely into SQL:
 * `<=>` is pgvector's cosine-distance operator and is served by the HNSW
 * index, so a query is an approximate nearest-neighbour lookup rather than a
 * full table scan. Keyword search uses the generated tsvector column + GIN index.
 */
class PgvectorStore implements VectorStore
{
    public function __construct(private readonly ConnectionInterface $db) {}

    public function replaceDocumentChunks(Document $document, array $chunks, array $embeddings): void
    {
        $now = now();

        $this->db->transaction(function () use ($document, $chunks, $embeddings, $now): void {
            $this->db->table('chunks')->where('document_id', $document->id)->delete();

            $rows = [];

            foreach ($chunks as $index => $chunk) {
                $rows[] = [
                    'document_id' => $document->id,
                    'chunk_index' => $index,
                    'content' => $chunk->content,
                    'page_number' => $chunk->pageNumber,
                    'token_count' => $chunk->tokenCount,
                    'embedding' => Vector::toPgvector($embeddings[$index]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 200) as $batch) {
                $this->db->table('chunks')->insert($batch);
            }
        });
    }

    public function similaritySearch(array $queryVector, int $limit, ?array $documentIds = null): array
    {
        $vector = Vector::toPgvector($queryVector);

        return $this->baseQuery($documentIds)
            ->selectRaw('1 - (chunks.embedding <=> ?::vector) as similarity', [$vector])
            ->orderByRaw('chunks.embedding <=> ?::vector', [$vector])
            ->limit($limit)
            ->get()
            ->map(fn (object $row): RetrievedChunk => $this->toRetrievedChunk($row, similarity: (float) $row->similarity))
            ->all();
    }

    public function keywordSearch(string $query, int $limit, ?array $documentIds = null): array
    {
        $terms = Keywords::extract($query);

        if ($terms === []) {
            return [];
        }

        // OR the terms together; ts_rank_cd rewards chunks matching more of them, closer together.
        $tsQuery = implode(' | ', $terms);

        return $this->baseQuery($documentIds)
            ->selectRaw("ts_rank_cd(chunks.search_vector, to_tsquery('english', ?)) as keyword_score", [$tsQuery])
            ->whereRaw("chunks.search_vector @@ to_tsquery('english', ?)", [$tsQuery])
            ->orderByDesc('keyword_score')
            ->limit($limit)
            ->get()
            ->map(fn (object $row): RetrievedChunk => $this->toRetrievedChunk($row, keywordScore: (float) $row->keyword_score))
            ->all();
    }

    /**
     * @param  list<int>|null  $documentIds
     */
    private function baseQuery(?array $documentIds): Builder
    {
        return $this->db->table('chunks')
            ->join('documents', 'documents.id', '=', 'chunks.document_id')
            ->where('documents.status', DocumentStatus::Ready->value)
            ->when($documentIds !== null, fn (Builder $query) => $query->whereIn('chunks.document_id', $documentIds))
            ->select([
                'chunks.id',
                'chunks.document_id',
                'chunks.content',
                'chunks.page_number',
                'documents.title as document_title',
            ]);
    }

    private function toRetrievedChunk(object $row, ?float $similarity = null, ?float $keywordScore = null): RetrievedChunk
    {
        return new RetrievedChunk(
            chunkId: (int) $row->id,
            documentId: (int) $row->document_id,
            documentTitle: $row->document_title,
            pageNumber: $row->page_number === null ? null : (int) $row->page_number,
            content: $row->content,
            similarity: $similarity,
            keywordScore: $keywordScore,
        );
    }
}
