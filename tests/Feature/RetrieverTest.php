<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Services\Rag\Chunking\ChunkData;
use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Embeddings\HashEmbeddingProvider;
use App\Services\Rag\Retrieval\RetrievedChunk;
use App\Services\Rag\Retrieval\Retriever;
use App\Services\Rag\Support\Vector;
use App\Services\Rag\VectorStores\DatabaseVectorStore;
use App\Services\Rag\VectorStores\PgvectorStore;
use App\Services\Rag\VectorStores\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class RetrieverTest extends TestCase
{
    use RefreshDatabase;

    private const POLICY = [
        'Customers may request a refund within 30 days of purchase. Refunds are issued to the original payment method.',
        'Shipping is free for orders over 50 euros. Standard delivery takes three to five business days.',
        'Our support team is available Monday to Friday, 9am to 5pm Central European Time.',
    ];

    private const HANDBOOK = [
        'Employees accrue 25 vacation days per year. Unused days roll over until March 31.',
        'The office security badge must be worn at all times inside the building.',
    ];

    public function test_container_picks_the_vector_store_for_the_database_driver(): void
    {
        $expectedStore = DB::connection()->getDriverName() === 'pgsql'
            ? PgvectorStore::class
            : DatabaseVectorStore::class;

        $this->assertInstanceOf($expectedStore, app(VectorStore::class));
        $this->assertInstanceOf(HashEmbeddingProvider::class, app(EmbeddingProvider::class));
    }

    public function test_similarity_search_ranks_the_relevant_chunk_first(): void
    {
        $this->index('Store Policy', self::POLICY);
        $this->index('Employee Handbook', self::HANDBOOK);

        $results = app(VectorStore::class)->similaritySearch(
            app(EmbeddingProvider::class)->embedQuery('How many days do I have to get a refund?'),
            limit: 3,
        );

        $this->assertCount(3, $results);
        $this->assertStringContainsString('refund within 30 days', $results[0]->content);
        $this->assertSame('Store Policy', $results[0]->documentTitle);
        $this->assertGreaterThan($results[1]->similarity, $results[0]->similarity);
    }

    public function test_search_can_be_scoped_to_specific_documents(): void
    {
        $this->index('Store Policy', self::POLICY);
        $handbook = $this->index('Employee Handbook', self::HANDBOOK);

        $results = app(Retriever::class)->retrieve('refund days', [$handbook->id]);

        $this->assertNotEmpty($results);
        foreach ($results as $result) {
            $this->assertSame($handbook->id, $result->documentId);
        }
    }

    public function test_documents_that_are_not_ready_are_never_searched(): void
    {
        $document = $this->index('Store Policy', self::POLICY);
        $document->update(['status' => 'processing']);

        $this->assertSame([], app(Retriever::class)->retrieve('refund'));
    }

    public function test_keyword_search_finds_exact_terms(): void
    {
        $this->index('Store Policy', self::POLICY);
        $this->index('Employee Handbook', self::HANDBOOK);

        $results = app(VectorStore::class)->keywordSearch('security badge', limit: 5);

        $this->assertNotEmpty($results);
        $this->assertStringContainsString('security badge', $results[0]->content);
        $this->assertNotNull($results[0]->keywordScore);
    }

    public function test_keyword_search_matches_hyphenated_codes(): void
    {
        $this->index('Vacuum Manual', [
            'E-12: Wheel stuck. A drive wheel cannot turn.',
            'E-417: Dock communication failure. The vacuum cannot find its dock.',
        ]);

        $results = app(VectorStore::class)->keywordSearch('What does E-417 mean?', limit: 5);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('E-417', $results[0]->content);
    }

    public function test_keyword_search_scores_every_match_however_many_there_are(): void
    {
        $this->index('Notices', array_map(fn (int $i): string => "Notice {$i}: wear your badge.", range(1, 600)));
        $this->index('Security Policy', ['Security badge rules: a lost security badge must be reported the same day.']);

        $results = app(VectorStore::class)->keywordSearch('lost security badge', limit: 5);

        $this->assertSame('Security Policy', $results[0]->documentTitle);
    }

    public function test_scoped_similarity_search_looks_past_closer_chunks_in_other_documents(): void
    {
        $this->index('Notices', array_map(fn (int $i): string => "Refund notice {$i}: ask about your refund.", range(1, 100)));
        $policy = $this->index('Store Policy', [self::POLICY[0]]);
        $query = app(EmbeddingProvider::class)->embedQuery('refund notice');

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Make the planner walk the HNSW index, as it does on a large table. The index's
            // first candidates are all Notices chunks, which the document filter then drops.
            DB::statement('SET LOCAL enable_seqscan = off');
            DB::statement('SET LOCAL enable_sort = off');
            DB::enableQueryLog();
        }

        $results = app(VectorStore::class)->similaritySearch($query, limit: 3, documentIds: [$policy->id]);

        $this->assertCount(1, $results);
        $this->assertSame($policy->id, $results[0]->documentId);

        if (DB::connection()->getDriverName() === 'pgsql') {
            ['query' => $sql, 'bindings' => $bindings] = DB::getQueryLog()[0];
            $plan = collect(DB::select("EXPLAIN {$sql}", $bindings))->pluck('QUERY PLAN')->implode("\n");

            $this->assertStringContainsString('chunks_embedding_hnsw_idx', $plan);
        }
    }

    public function test_hybrid_retrieval_merges_both_rankings(): void
    {
        $this->index('Store Policy', self::POLICY);
        $this->index('Employee Handbook', self::HANDBOOK);

        $results = app(Retriever::class)->retrieve('vacation days roll over');

        $this->assertStringContainsString('vacation days', $results[0]->content);
        $this->assertNotNull($results[0]->similarity);
        $this->assertNotNull($results[0]->keywordScore);
    }

    public function test_reciprocal_rank_fusion_rewards_agreement_between_rankings(): void
    {
        $chunk = fn (int $id): RetrievedChunk => new RetrievedChunk($id, 1, 'Doc', null, "chunk {$id}");
        $retriever = new Retriever(app(EmbeddingProvider::class), app(VectorStore::class), rrfK: 60);

        $fused = $retriever->fuseByRank([
            [$chunk(1), $chunk(2), $chunk(3)],
            [$chunk(3), $chunk(4), $chunk(1)],
        ]);

        $this->assertSame([1, 3, 2, 4], array_map(fn (RetrievedChunk $c) => $c->chunkId, $fused));
        $this->assertEqualsWithDelta(1 / 61 + 1 / 63, $fused[0]->score, 1e-9);
    }

    public function test_weighted_fusion_keeps_a_clear_vector_winner_on_top(): void
    {
        $vector = fn (int $id, float $similarity): RetrievedChunk => new RetrievedChunk($id, 1, 'Doc', null, "chunk {$id}", similarity: $similarity);
        $keyword = fn (int $id, float $score): RetrievedChunk => new RetrievedChunk($id, 1, 'Doc', null, "chunk {$id}", keywordScore: $score);
        $vectorHits = fn (): array => [$vector(1, 0.9), $vector(2, 0.5), $vector(3, 0.4)];
        $keywordHits = fn (): array => [$keyword(2, 1.0), $keyword(3, 0.9), $keyword(4, 0.1)];
        $retriever = new Retriever(app(EmbeddingProvider::class), app(VectorStore::class), vectorWeight: 0.6);

        $fused = $retriever->fuseByScore($vectorHits(), $keywordHits());

        // Chunk 1 is the best vector hit by far and has no keyword match; chunk 2 is mediocre on both.
        $this->assertSame([1, 2, 3, 4], array_map(fn (RetrievedChunk $c) => $c->chunkId, $fused));
        $this->assertEqualsWithDelta(0.6, $fused[0]->score, 1e-9);
        $this->assertEqualsWithDelta(0.6 * 0.2 + 0.4 * 1.0, $fused[1]->score, 1e-9);
        $this->assertSame(0.5, $fused[1]->similarity);
        $this->assertSame(1.0, $fused[1]->keywordScore);

        // Rank fusion rewards appearing in both lists, so it puts chunk 2 first.
        $this->assertSame(2, $retriever->fuseByRank([$vectorHits(), $keywordHits()])[0]->chunkId);
    }

    public function test_weighted_fusion_scores_a_lone_hit_as_the_best_on_its_side(): void
    {
        $retriever = new Retriever(app(EmbeddingProvider::class), app(VectorStore::class), vectorWeight: 0.7);

        $fused = $retriever->fuseByScore([], [new RetrievedChunk(5, 1, 'Doc', null, 'chunk 5', keywordScore: 0.02)]);

        $this->assertCount(1, $fused);
        $this->assertEqualsWithDelta(0.3, $fused[0]->score, 1e-9);
    }

    public function test_the_configured_fusion_is_used_and_validated(): void
    {
        $this->index('Company Policy', self::POLICY);
        config(['rag.retrieval.fusion' => 'rrf']);

        $results = app(Retriever::class)->retrieve('refund within 30 days');

        $this->assertStringContainsString('refund', $results[0]->content);
        $this->assertLessThan(1 / 30, $results[0]->score, 'RRF scores are sums of 1 / (60 + rank).');

        $this->expectException(InvalidArgumentException::class);
        new Retriever(app(EmbeddingProvider::class), app(VectorStore::class), fusion: 'average');
    }

    public function test_hash_embeddings_are_normalised(): void
    {
        $vector = (new HashEmbeddingProvider(256))->embedQuery('Normalised vectors make cosine a dot product');

        $this->assertCount(256, $vector);
        $this->assertEqualsWithDelta(1.0, Vector::dot($vector, $vector), 1e-9);
    }

    /**
     * @param  list<string>  $passages
     */
    private function index(string $title, array $passages): Document
    {
        $document = Document::factory()->ready()->create(['title' => $title]);
        $chunks = array_map(fn (string $text): ChunkData => new ChunkData($text, null, 10), $passages);

        app(VectorStore::class)->replaceDocumentChunks(
            $document,
            $chunks,
            app(EmbeddingProvider::class)->embedDocuments($passages),
        );

        return $document;
    }
}
