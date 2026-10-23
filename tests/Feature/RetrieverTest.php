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

        $fused = $retriever->fuse([
            [$chunk(1), $chunk(2), $chunk(3)],
            [$chunk(3), $chunk(4), $chunk(1)],
        ]);

        $this->assertSame([1, 3, 2, 4], array_map(fn (RetrievedChunk $c) => $c->chunkId, $fused));
        $this->assertEqualsWithDelta(1 / 61 + 1 / 63, $fused[0]->score, 1e-9);
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
