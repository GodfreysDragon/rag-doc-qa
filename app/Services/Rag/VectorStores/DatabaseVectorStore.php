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
 * Portable fallback for MySQL / SQLite: embeddings are stored as JSON and
 * similarity is computed in PHP with an exact (brute-force) scan.
 *
 * Exact search is O(n) per query — perfectly fine for a few thousand chunks,
 * and a good illustration of what an ANN index like pgvector's HNSW saves you
 * once the corpus grows. Keyword search likewise scores every LIKE match.
 */
class DatabaseVectorStore implements VectorStore
{
    private const SCAN_BATCH = 500;

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
                    'embedding' => json_encode($embeddings[$index]),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            foreach (array_chunk($rows, 100) as $batch) {
                $this->db->table('chunks')->insert($batch);
            }
        });
    }

    public function similaritySearch(array $queryVector, int $limit, ?array $documentIds = null): array
    {
        /** @var array<int, float> $scores chunk id => cosine similarity */
        $scores = [];

        $this->readyChunks($documentIds)
            ->select(['chunks.id', 'chunks.embedding'])
            ->orderBy('chunks.id')
            ->chunk(self::SCAN_BATCH, function ($rows) use ($queryVector, $limit, &$scores): void {
                foreach ($rows as $row) {
                    $scores[(int) $row->id] = Vector::dot($queryVector, json_decode($row->embedding, true));
                }

                // Keep memory bounded: only the current top candidates survive each batch.
                arsort($scores);
                $scores = array_slice($scores, 0, $limit, preserve_keys: true);
            });

        arsort($scores);

        return $this->hydrate($scores, 'similarity');
    }

    public function keywordSearch(string $query, int $limit, ?array $documentIds = null): array
    {
        $terms = Keywords::extract($query);

        if ($terms === []) {
            return [];
        }

        /** @var array<string, int> $documentFrequency */
        $documentFrequency = [];
        /** @var array<int, array<string, int>> $termFrequencies */
        $termFrequencies = [];
        $total = 0;

        // Every LIKE match is scored, in batches: capping the candidate set would
        // drop arbitrary matches, since there is no relevance order to cap by.
        $this->readyChunks($documentIds)
            ->select(['chunks.id', 'chunks.content'])
            ->where(function (Builder $where) use ($terms): void {
                foreach ($terms as $term) {
                    $where->orWhere('chunks.content', 'like', '%'.addcslashes($term, '%_\\').'%');
                }
            })
            ->chunkById(self::SCAN_BATCH, function ($candidates) use ($terms, &$documentFrequency, &$termFrequencies, &$total): void {
                foreach ($candidates as $candidate) {
                    $total++;
                    $words = array_count_values(Keywords::tokens($candidate->content));

                    foreach ($terms as $term) {
                        $count = $words[$term] ?? 0;

                        if ($count > 0) {
                            $termFrequencies[(int) $candidate->id][$term] = $count;
                            $documentFrequency[$term] = ($documentFrequency[$term] ?? 0) + 1;
                        }
                    }
                }
            }, 'chunks.id', 'id');

        if ($termFrequencies === []) {
            return [];
        }
        $scores = [];

        foreach ($termFrequencies as $chunkId => $frequencies) {
            $score = 0.0;

            foreach ($frequencies as $term => $count) {
                $score += log(1 + $count) * log(1 + $total / $documentFrequency[$term]);
            }

            $scores[$chunkId] = $score;
        }

        arsort($scores);

        return $this->hydrate(array_slice($scores, 0, $limit, preserve_keys: true), 'keywordScore');
    }

    /**
     * @param  list<int>|null  $documentIds
     */
    private function readyChunks(?array $documentIds): Builder
    {
        return $this->db->table('chunks')
            ->join('documents', 'documents.id', '=', 'chunks.document_id')
            ->where('documents.status', DocumentStatus::Ready->value)
            ->when($documentIds !== null, fn (Builder $query) => $query->whereIn('chunks.document_id', $documentIds));
    }

    /**
     * Load full rows for the winning chunk ids, preserving score order.
     *
     * @param  array<int, float>  $scores
     * @param  'similarity'|'keywordScore'  $scoreField
     * @return list<RetrievedChunk>
     */
    private function hydrate(array $scores, string $scoreField): array
    {
        if ($scores === []) {
            return [];
        }

        $rows = $this->db->table('chunks')
            ->join('documents', 'documents.id', '=', 'chunks.document_id')
            ->whereIn('chunks.id', array_keys($scores))
            ->get(['chunks.id', 'chunks.document_id', 'chunks.content', 'chunks.page_number', 'documents.title as document_title'])
            ->keyBy('id');

        $results = [];

        foreach ($scores as $chunkId => $score) {
            $row = $rows->get($chunkId);

            if ($row === null) {
                continue;
            }

            $chunk = new RetrievedChunk(
                chunkId: (int) $row->id,
                documentId: (int) $row->document_id,
                documentTitle: $row->document_title,
                pageNumber: $row->page_number === null ? null : (int) $row->page_number,
                content: $row->content,
            );
            $chunk->{$scoreField} = $score;
            $results[] = $chunk;
        }

        return $results;
    }
}
