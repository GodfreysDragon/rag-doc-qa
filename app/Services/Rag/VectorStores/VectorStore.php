<?php

namespace App\Services\Rag\VectorStores;

use App\Models\Document;
use App\Services\Rag\Chunking\ChunkData;
use App\Services\Rag\Retrieval\RetrievedChunk;

interface VectorStore
{
    /**
     * Atomically replace all chunks (and their embeddings) for a document.
     *
     * @param  list<ChunkData>  $chunks
     * @param  list<list<float>>  $embeddings
     */
    public function replaceDocumentChunks(Document $document, array $chunks, array $embeddings): void;

    /**
     * Nearest neighbours by cosine similarity, best first.
     *
     * @param  list<float>  $queryVector
     * @param  list<int>|null  $documentIds  Restrict the search to these documents.
     * @return list<RetrievedChunk>
     */
    public function similaritySearch(array $queryVector, int $limit, ?array $documentIds = null): array;

    /**
     * Lexical full-text matches, best first.
     *
     * @param  list<int>|null  $documentIds
     * @return list<RetrievedChunk>
     */
    public function keywordSearch(string $query, int $limit, ?array $documentIds = null): array;
}
