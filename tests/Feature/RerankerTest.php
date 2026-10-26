<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Providers\AppServiceProvider;
use App\Services\Rag\Chunking\ChunkData;
use App\Services\Rag\DocumentChat;
use App\Services\Rag\Embeddings\EmbeddingConfigurationException;
use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Llm\ChatModel;
use App\Services\Rag\Retrieval\ClaudeReranker;
use App\Services\Rag\Retrieval\Reranker;
use App\Services\Rag\Retrieval\Reranking;
use App\Services\Rag\Retrieval\RetrievedChunk;
use App\Services\Rag\Retrieval\Retriever;
use App\Services\Rag\Retrieval\VoyageReranker;
use App\Services\Rag\VectorStores\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;
use Tests\Fakes\FakeChatModel;
use Tests\TestCase;

class RerankerTest extends TestCase
{
    use RefreshDatabase;

    private const PASSAGES = [
        'Customers may request a refund within 30 days of purchase.',
        'Refunds are issued to the original payment method.',
        'Shipping is free for orders over 50 euros.',
        'Our support team is available Monday to Friday.',
    ];

    public function test_claude_orders_passages_by_the_ids_it_returns(): void
    {
        $llm = new FakeChatModel(rewrite: 'Most relevant first: [3, 1, 3, 9]');

        $reranking = (new ClaudeReranker($llm, 'claude-haiku-5-5'))->rerank('Is shipping free?', $this->chunks(4), topK: 3);

        // 3 and 1 as ranked (the repeat and the unknown id 9 are ignored), then the rest in fused order.
        $this->assertSame([3, 1, 2], $this->ids($reranking->chunks));
        $this->assertSame(['input_tokens' => 50, 'output_tokens' => 5], $reranking->usage);

        $call = $llm->completeCalls[0];
        $this->assertSame(ClaudeReranker::SYSTEM_PROMPT, $call['system']);
        $this->assertStringStartsWith('<question>Is shipping free?</question>', $call['messages'][0]['content']);
        $this->assertStringContainsString("<passage id=\"4\">\nchunk 4\n</passage>", $call['messages'][0]['content']);
    }

    public function test_an_unusable_claude_reply_keeps_the_fused_order(): void
    {
        $reranking = (new ClaudeReranker(new FakeChatModel(rewrite: 'I cannot rank these.'), 'claude-haiku-5-5'))
            ->rerank('Anything?', $this->chunks(4), topK: 3);

        $this->assertSame([1, 2, 3], $this->ids($reranking->chunks));
    }

    public function test_long_passages_are_shortened_in_the_prompt(): void
    {
        $llm = new FakeChatModel(rewrite: '[2, 1]');
        $chunks = [new RetrievedChunk(1, 1, 'Doc', null, str_repeat('a', 500)), new RetrievedChunk(2, 1, 'Doc', null, 'short')];

        (new ClaudeReranker($llm, 'claude-haiku-5-5', maxPassageChars: 100))->rerank('q', $chunks, topK: 2);

        $this->assertStringNotContainsString(str_repeat('a', 101), $llm->completeCalls[0]['messages'][0]['content']);
    }

    public function test_the_retriever_reranks_the_fused_candidates(): void
    {
        $this->index(self::PASSAGES);
        $reranker = $this->reversingReranker();

        $results = $this->retriever($reranker, topK: 2, rerankCandidates: 4)->retrieve('refund');

        $fused = $this->retriever(null, topK: 4)->retrieve('refund');
        $this->assertSame(4, $reranker->received);
        $this->assertSame(array_slice(array_reverse($this->ids($fused)), 0, 2), $this->ids($results));
    }

    public function test_a_failing_reranker_falls_back_to_the_fused_order(): void
    {
        $this->index(self::PASSAGES);

        $failing = new class implements Reranker
        {
            public function rerank(string $query, array $chunks, int $topK): Reranking
            {
                throw new RuntimeException('Overloaded');
            }

            public function tokenCeiling(int $candidates): int
            {
                return 0;
            }

            public function name(): string
            {
                return 'failing';
            }
        };

        [$results, $usage] = $this->retriever($failing, topK: 2)->retrieveWithUsage('refund');

        $this->assertSame($this->ids($this->retriever(null, topK: 2)->retrieve('refund')), $this->ids($results));
        $this->assertNull($usage);
    }

    public function test_reranking_tokens_count_towards_the_answer_and_its_reservation(): void
    {
        $this->index(self::PASSAGES);
        $reranker = new ClaudeReranker(new FakeChatModel(rewrite: '[2, 1]'), 'claude-haiku-5-5');

        $chat = fn (?Reranker $reranker): DocumentChat => new DocumentChat($this->retriever($reranker, topK: 2), new FakeChatModel, rewriteQueries: false);

        $done = collect(iterator_to_array($chat($reranker)->answer('How long do I have to get a refund?'), false))->last();

        // The answer's 100 + 10 plus the re-ranking's 50 + 5.
        $this->assertSame(['input_tokens' => 150, 'output_tokens' => 15], $done['usage']);
        $this->assertSame(
            $chat(null)->tokenCeiling('Refund?') + $reranker->tokenCeiling(20),
            $chat($reranker)->tokenCeiling('Refund?'),
        );
    }

    public function test_voyage_sends_the_candidates_and_orders_by_relevance(): void
    {
        Http::fake(['api.voyageai.com/*' => Http::response([
            'data' => [['index' => 0, 'relevance_score' => 0.41], ['index' => 2, 'relevance_score' => 0.93]],
            'usage' => ['total_tokens' => 120],
        ])]);

        $reranking = (new VoyageReranker('test-key', 'rerank-2.5', 'https://api.voyageai.com/v1/rerank'))
            ->rerank('Is shipping free?', $this->chunks(3), topK: 2);

        $this->assertSame([3, 1], $this->ids($reranking->chunks));
        $this->assertNull($reranking->usage, 'Voyage tokens are not LLM tokens.');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer test-key')
            && $request['query'] === 'Is shipping free?'
            && $request['documents'] === ['chunk 1', 'chunk 2', 'chunk 3']
            && $request['model'] === 'rerank-2.5'
            && $request['top_k'] === 2);
    }

    public function test_voyage_needs_an_api_key(): void
    {
        $this->expectException(EmbeddingConfigurationException::class);

        (new VoyageReranker(null, 'rerank-2.5', 'https://api.voyageai.com/v1/rerank'))->rerank('q', $this->chunks(2), topK: 2);
    }

    public function test_the_configured_reranker_is_wired_into_retrieval(): void
    {
        $llm = new FakeChatModel(rewrite: '[1]');
        $this->app->instance(ChatModel::class, $llm);
        $this->index(self::PASSAGES);

        $this->assertSame(0, app(Retriever::class)->rerankTokenCeiling(), 'Tests run without re-ranking (phpunit.xml).');

        config(['rag.retrieval.reranker' => 'claude']);
        app(Retriever::class)->retrieve('refund');

        $this->assertSame(['claude-haiku-5-5'], $llm->models);
        $this->assertCount(1, $llm->completeCalls);

        $this->expectException(InvalidArgumentException::class);
        AppServiceProvider::reranker($this->app, 'cohere');
    }

    public function test_the_evaluation_reports_a_reranked_mode(): void
    {
        $this->app->instance(ChatModel::class, new FakeChatModel(rewrite: '[1]'));

        $this->artisan('rag:eval', ['--rerank' => 'claude'])
            ->expectsOutputToContain('reranked')
            ->expectsOutputToContain('Re-ranked by [claude:claude-haiku-5-5]')
            ->assertSuccessful();
    }

    /**
     * @return list<RetrievedChunk>
     */
    private function chunks(int $count): array
    {
        return array_map(fn (int $id): RetrievedChunk => new RetrievedChunk($id, 1, 'Doc', null, "chunk {$id}"), range(1, $count));
    }

    /**
     * @param  list<RetrievedChunk>  $chunks
     * @return list<int>
     */
    private function ids(array $chunks): array
    {
        return array_map(fn (RetrievedChunk $chunk): int => $chunk->chunkId, $chunks);
    }

    private function retriever(?Reranker $reranker, int $topK, int $rerankCandidates = 20): Retriever
    {
        return new Retriever(app(EmbeddingProvider::class), app(VectorStore::class), topK: $topK, reranker: $reranker, rerankCandidates: $rerankCandidates);
    }

    /**
     * A re-ranker that reverses the candidates and records how many it received.
     */
    private function reversingReranker(): Reranker
    {
        return new class implements Reranker
        {
            public int $received = 0;

            public function rerank(string $query, array $chunks, int $topK): Reranking
            {
                $this->received = count($chunks);

                return new Reranking(array_slice(array_reverse($chunks), 0, $topK));
            }

            public function tokenCeiling(int $candidates): int
            {
                return 0;
            }

            public function name(): string
            {
                return 'reverse';
            }
        };
    }

    /**
     * @param  list<string>  $passages
     */
    private function index(array $passages): Document
    {
        $document = Document::factory()->ready()->create(['title' => 'Store Policy']);

        app(VectorStore::class)->replaceDocumentChunks(
            $document,
            array_map(fn (string $text): ChunkData => new ChunkData($text, null, 10), $passages),
            app(EmbeddingProvider::class)->embedDocuments($passages),
        );

        return $document;
    }
}
