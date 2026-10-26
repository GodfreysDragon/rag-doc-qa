<?php

namespace Tests\Feature;

use App\Models\Chunk;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_deleting_an_account_removes_its_documents_chunks_and_files(): void
    {
        $user = User::factory()->create();
        $mine = $this->upload($user, 'mine.txt');
        $theirs = $this->upload(User::factory()->create(), 'theirs.txt');

        $this->actingAs($user)->deleteJson(route('api.user.destroy'), ['password' => 'password'])->assertNoContent();

        $this->assertModelMissing($user);
        $this->assertModelMissing($mine);
        $this->assertGuest('web');
        Storage::disk('local')->assertMissing($mine->path);

        $this->assertSame([$theirs->id], Document::pluck('id')->all());
        $this->assertSame([$theirs->id], Chunk::pluck('document_id')->unique()->values()->all());
        Storage::disk('local')->assertExists($theirs->path);
    }

    public function test_the_current_password_is_required(): void
    {
        $user = User::factory()->create();
        $this->upload($user, 'mine.txt');

        $this->actingAs($user)->deleteJson(route('api.user.destroy'), ['password' => 'wrong'])->assertJsonValidationErrors('password');

        $this->assertModelExists($user);
        $this->assertSame(1, Document::count());
    }

    private function upload(User $user, string $name): Document
    {
        $this->actingAs($user)->postJson(route('api.documents.store'), [
            'file' => UploadedFile::fake()->createWithContent($name, "The contents of {$name}."),
        ])->assertCreated();

        return Document::where('user_id', $user->id)->latest('id')->firstOrFail();
    }
}
