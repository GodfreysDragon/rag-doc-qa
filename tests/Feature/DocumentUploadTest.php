<?php

namespace Tests\Feature;

use App\Enums\DocumentStatus;
use App\Jobs\ProcessDocument;
use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['rag.chunking.size' => 300, 'rag.chunking.overlap' => 50]);
    }

    public function test_uploading_a_text_file_chunks_and_embeds_it(): void
    {
        $text = implode("\n\n", array_map(
            fn (int $i): string => "Section {$i}. ".str_repeat("This section describes topic {$i} in some detail. ", 5),
            range(1, 6),
        ));

        $response = $this->postJson(route('api.documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('quarterly_report-2026.txt', $text),
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.title', 'quarterly report 2026')
            ->assertJsonPath('data.extension', 'txt');

        $document = Document::sole();
        $this->assertSame(DocumentStatus::Ready, $document->status);
        $this->assertSame('hash:512', $document->embedding_model);
        $this->assertGreaterThan(3, $document->chunk_count);
        $this->assertSame($document->chunk_count, $document->chunks()->count());
        Storage::disk('local')->assertExists($document->path);

        $chunk = Chunk::query()->where('document_id', $document->id)->orderBy('chunk_index')->first();
        $this->assertStringStartsWith('Section 1.', $chunk->content);
        $this->assertCount(512, json_decode($chunk->getRawOriginal('embedding'), true));
        $this->assertArrayNotHasKey('embedding', $chunk->toArray());
    }

    public function test_uploads_are_queued_for_processing(): void
    {
        Queue::fake();

        $this->postJson(route('api.documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('notes.md', '# Notes'),
        ])->assertCreated()->assertJsonPath('data.status', 'pending');

        Queue::assertPushed(ProcessDocument::class, fn (ProcessDocument $job) => $job->document->is(Document::sole()));
    }

    public function test_uploading_the_same_file_twice_returns_the_existing_document(): void
    {
        $file = fn () => UploadedFile::fake()->createWithContent('policy.txt', 'Refunds are available for 30 days.');

        $first = $this->postJson(route('api.documents.store'), ['file' => $file()])->assertCreated();

        $this->postJson(route('api.documents.store'), ['file' => $file()])
            ->assertOk()
            ->assertJsonPath('duplicate', true)
            ->assertJsonPath('data.id', $first->json('data.id'));

        $this->assertSame(1, Document::count());
    }

    public function test_unsupported_file_types_are_rejected(): void
    {
        $this->postJson(route('api.documents.store'), [
            'file' => UploadedFile::fake()->create('malware.exe', 10, 'application/octet-stream'),
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertSame(0, Document::count());
    }

    public function test_a_file_without_text_is_marked_failed_without_retrying(): void
    {
        $this->postJson(route('api.documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('empty.txt', "   \n\n  "),
        ])->assertCreated();

        $document = Document::sole();
        $this->assertSame(DocumentStatus::Failed, $document->status);
        $this->assertStringContainsString('No extractable text', $document->error);
        $this->assertSame(0, $document->chunks()->count());
    }

    public function test_documents_can_be_listed_and_shown(): void
    {
        $documents = Document::factory()->count(2)->ready()->create();

        $this->getJson(route('api.documents.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $documents[1]->id);

        $this->getJson(route('api.documents.show', $documents[0]))
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');
    }

    public function test_deleting_a_document_removes_its_file_and_chunks(): void
    {
        $this->postJson(route('api.documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('policy.txt', 'Refunds are available for 30 days.'),
        ]);
        $document = Document::sole();
        $this->assertSame(1, Chunk::count());

        $this->deleteJson(route('api.documents.destroy', $document))->assertNoContent();

        $this->assertModelMissing($document);
        $this->assertSame(0, Chunk::count());
        Storage::disk('local')->assertMissing($document->path);
    }

    public function test_a_document_can_be_reprocessed(): void
    {
        $this->postJson(route('api.documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('policy.txt', 'Refunds are available for 30 days.'),
        ]);
        $document = Document::sole();
        config(['rag.chunking.size' => 20, 'rag.chunking.overlap' => 5]);

        $this->postJson(route('api.documents.reprocess', $document))->assertOk();

        $this->assertGreaterThan(1, $document->refresh()->chunk_count);
        $this->assertSame($document->chunk_count, Chunk::count());
    }

    public function test_reindex_command_re_embeds_stale_documents(): void
    {
        $this->postJson(route('api.documents.store'), [
            'file' => UploadedFile::fake()->createWithContent('policy.txt', 'Refunds are available for 30 days.'),
        ]);
        Document::query()->update(['embedding_model' => 'voyage:old-model']);

        $this->artisan('rag:reindex', ['--stale' => true])->assertSuccessful();

        $this->assertSame('hash:512', Document::sole()->embedding_model);
    }
}
