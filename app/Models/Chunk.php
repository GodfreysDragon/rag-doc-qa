<?php

namespace App\Models;

use Database\Factories\ChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The embedding column is written and read exclusively by the VectorStore,
 * because its storage format depends on the database driver.
 */
#[Fillable(['document_id', 'chunk_index', 'content', 'page_number', 'token_count'])]
#[Hidden(['embedding', 'search_vector'])]
class Chunk extends Model
{
    /** @use HasFactory<ChunkFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
