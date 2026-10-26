<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Rag\Chunking\ChunkData;
use App\Services\Rag\Embeddings\EmbeddingProvider;
use App\Services\Rag\Llm\ChatModel;
use App\Services\Rag\VectorStores\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeChatModel;
use Tests\TestCase;

class DocumentOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->otherUser = User::factory()->create();
        $this->actingAs($this->user);
    }

    public function test_users_only_list_their_own_documents(): void
    {
        $own = Document::factory()->for($this->user)->create();
        Document::factory()->for($this->otherUser)->create();

        $this->getJson(route('api.documents.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own->id);
    }

    public function test_other_users_documents_answer_not_found(): void
    {
        $theirs = Document::factory()->ready()->for($this->otherUser)->create();

        $this->getJson(route('api.documents.show', $theirs))->assertNotFound();
        $this->postJson(route('api.documents.reprocess', $theirs))->assertNotFound();
        $this->deleteJson(route('api.documents.destroy', $theirs))->assertNotFound();

        $this->assertModelExists($theirs);
    }

    public function test_two_users_can_upload_the_same_file(): void
    {
        Queue::fake();
        $upload = fn (): UploadedFile => UploadedFile::fake()->createWithContent('policy.txt', 'Refunds within 30 days.');

        $this->postJson(route('api.documents.store'), ['file' => $upload()])->assertCreated();
        $this->postJson(route('api.documents.store'), ['file' => $upload()])->assertOk()->assertJsonPath('duplicate', true);

        $this->actingAs($this->otherUser)
            ->postJson(route('api.documents.store'), ['file' => $upload()])
            ->assertCreated();

        $this->assertSame(2, Document::count());
    }

    public function test_questions_only_search_the_askers_documents(): void
    {
        $llm = new FakeChatModel;
        $this->app->instance(ChatModel::class, $llm);
        $this->index($this->otherUser, 'Their Refund Policy', 'Customers may request a refund within 30 days of purchase.');

        $response = $this->postJson(route('api.chat'), ['question' => 'How long is the refund window?'])->assertOk();

        $this->assertStringContainsString('"sources":[]', $response->streamedContent());
        $this->assertSame([], $llm->streamCalls);

        $own = $this->index($this->user, 'Our Refund Policy', 'Refunds are possible for 14 days.');
        $response = $this->postJson(route('api.chat'), ['question' => 'How long is the refund window?'])->assertOk();

        preg_match('/"type":"sources","sources":(\[.*?\])\}\n/', $response->streamedContent(), $match);
        $this->assertSame([$own->id], array_values(array_unique(array_column(json_decode($match[1], true), 'document_id'))));
    }

    public function test_selecting_another_users_document_is_rejected(): void
    {
        $theirs = Document::factory()->ready()->for($this->otherUser)->create();

        $this->postJson(route('api.chat'), ['question' => 'Hi', 'document_ids' => [$theirs->id]])
            ->assertJsonValidationErrors('document_ids.0');
    }

    private function index(User $owner, string $title, string $passage): Document
    {
        $document = Document::factory()->ready()->for($owner)->create(['title' => $title]);

        app(VectorStore::class)->replaceDocumentChunks(
            $document,
            [new ChunkData($passage, null, 10)],
            app(EmbeddingProvider::class)->embedDocuments([$passage]),
        );

        return $document;
    }
}
