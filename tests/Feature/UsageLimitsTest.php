<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Rag\Chunking\ChunkData;
use App\Services\Rag\DocumentChat;
use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Llm\ChatModel;
use App\Services\Rag\TokenBudget;
use App\Services\Rag\VectorStores\VectorStore;
use Generator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Fakes\FakeChatModel;
use Tests\TestCase;

class UsageLimitsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Queue::fake();

        // FakeChatModel reports 100 input + 10 output tokens per answer.
        $this->app->instance(ChatModel::class, new FakeChatModel);
    }

    public function test_uploads_are_rate_limited_per_user(): void
    {
        config(['rag.limits.uploads_per_hour' => 2]);
        $user = User::factory()->create();
        $upload = fn (int $i): array => ['file' => UploadedFile::fake()->createWithContent("note-{$i}.txt", "Note number {$i}.")];

        $this->actingAs($user)->postJson(route('api.documents.store'), $upload(1))->assertCreated();
        $this->actingAs($user)->postJson(route('api.documents.store'), $upload(2))->assertCreated();
        $this->actingAs($user)->postJson(route('api.documents.store'), $upload(3))->assertTooManyRequests();

        $this->actingAs(User::factory()->create())->postJson(route('api.documents.store'), $upload(3))->assertCreated();
    }

    public function test_questions_are_refused_once_the_users_daily_budget_is_spent(): void
    {
        // A question is let in only if its worst case still fits: here, two real answers (110 tokens each) and no more.
        config(['rag.limits.daily_tokens_per_user' => $this->ceiling() + 219, 'rag.limits.daily_tokens_total' => 0]);
        $user = $this->userWithDocument();

        $this->ask($user)->assertOk();
        $this->ask($user)->assertOk();
        $this->assertSame(220, app(TokenBudget::class)->usedBy($user), 'Reservations are replaced by the real usage.');

        $this->ask($user)
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'The daily usage limit has been reached. Please try again tomorrow.');
        $this->assertSame(220, app(TokenBudget::class)->usedBy($user), 'A refused question reserves nothing.');

        $this->ask($this->userWithDocument())->assertOk();
    }

    public function test_the_app_wide_budget_applies_across_users(): void
    {
        config(['rag.limits.daily_tokens_per_user' => 0, 'rag.limits.daily_tokens_total' => $this->ceiling() + 100]);

        $this->ask($this->userWithDocument())->assertOk();

        $this->ask($this->userWithDocument())->assertTooManyRequests();
    }

    public function test_questions_in_flight_hold_their_reservation(): void
    {
        $budget = new TokenBudget(app('cache.store'), dailyPerUser: 1000);
        $user = User::factory()->create();

        $this->assertTrue($budget->reserve($user, 600));
        $this->assertFalse($budget->reserve($user, 600), 'A second question at the same time must not fit.');
        $this->assertSame(600, $budget->usedBy($user));

        $budget->settle($user, 600, 100);
        $this->assertSame(100, $budget->usedBy($user));
        $this->assertTrue($budget->reserve($user, 600));
    }

    public function test_query_rewrites_count_towards_the_budget(): void
    {
        $user = $this->userWithDocument();

        $response = $this->actingAs($user)->postJson(route('api.chat'), [
            'question' => 'And for how long?',
            'history' => [['role' => 'user', 'content' => 'Can I get a refund?'], ['role' => 'assistant', 'content' => 'Yes [1].']],
        ]);
        $content = $response->streamedContent();

        // FakeChatModel: the rewrite costs 50 + 5 tokens and the answer 100 + 10.
        $this->assertStringContainsString('"usage":{"input_tokens":150,"output_tokens":15}', $content);
        $this->assertSame(165, app(TokenBudget::class)->usedBy($user));
    }

    public function test_a_failed_answer_releases_its_reservation(): void
    {
        $this->app->instance(ChatModel::class, new class extends FakeChatModel
        {
            public function stream(string $system, array $messages): Generator
            {
                throw new RuntimeException('The model is unavailable.');
                yield;
            }
        });
        $user = $this->userWithDocument();

        $this->assertStringContainsString('event: error', $this->ask($user)->streamedContent());
        $this->assertSame(0, app(TokenBudget::class)->usedBy($user));
    }

    public function test_the_reservation_grows_with_history_and_the_answer_limit(): void
    {
        $short = $this->ceiling();
        $history = [['role' => 'user', 'content' => str_repeat('word ', 400)], ['role' => 'assistant', 'content' => 'An answer.']];

        $this->assertGreaterThan($short + 500, app(DocumentChat::class)->tokenCeiling('How long is the refund window?', $history));

        config(['rag.llm.max_tokens' => 32000]);
        $this->assertSame($short + 16000, $this->ceiling());
    }

    public function test_a_limit_of_zero_means_unlimited(): void
    {
        config([
            'rag.limits.uploads_per_hour' => 0,
            'rag.limits.daily_tokens_per_user' => 0,
            'rag.limits.daily_tokens_total' => 0,
        ]);
        $user = $this->userWithDocument();

        foreach (range(1, 3) as $i) {
            $this->ask($user)->assertOk();
            $this->actingAs($user)
                ->postJson(route('api.documents.store'), ['file' => UploadedFile::fake()->createWithContent("n{$i}.txt", "Note {$i}.")])
                ->assertCreated();
        }
    }

    /**
     * Ask a question and, when it streams, run the stream to the end so its token usage is recorded.
     */
    private function ask(User $user): TestResponse
    {
        $response = $this->actingAs($user)->postJson(route('api.chat'), ['question' => 'How long is the refund window?']);

        if ($response->baseResponse instanceof StreamedResponse) {
            $response->streamedContent();
        }

        return $response;
    }

    /**
     * What ask() reserves before its answer starts.
     */
    private function ceiling(): int
    {
        return app(DocumentChat::class)->tokenCeiling('How long is the refund window?');
    }

    private function userWithDocument(): User
    {
        $user = User::factory()->create();
        $document = Document::factory()->ready()->for($user)->create();
        $passage = 'Customers may request a refund within 30 days of purchase.';

        app(VectorStore::class)->replaceDocumentChunks(
            $document,
            [new ChunkData($passage, null, 10)],
            app(EmbeddingProvider::class)->embedDocuments([$passage]),
        );

        return $user;
    }
}
