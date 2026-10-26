<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Rag\Chunking\ChunkData;
use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Llm\ChatModel;
use App\Services\Rag\TokenBudget;
use App\Services\Rag\VectorStores\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
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
        config(['rag.limits.daily_tokens_per_user' => 200, 'rag.limits.daily_tokens_total' => 0]);
        $user = $this->userWithDocument();

        $this->ask($user)->assertOk();
        $this->ask($user)->assertOk();
        $this->assertSame(220, app(TokenBudget::class)->usedBy($user));

        $this->ask($user)
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'The daily usage limit has been reached. Please try again tomorrow.');

        $this->ask($this->userWithDocument())->assertOk();
    }

    public function test_the_app_wide_budget_applies_across_users(): void
    {
        config(['rag.limits.daily_tokens_per_user' => 0, 'rag.limits.daily_tokens_total' => 100]);

        $this->ask($this->userWithDocument())->assertOk();

        $this->ask($this->userWithDocument())->assertTooManyRequests();
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
