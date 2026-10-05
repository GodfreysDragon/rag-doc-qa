<?php

namespace App\Models;

use App\Enums\DocumentStatus;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'title',
    'original_name',
    'path',
    'mime_type',
    'size_bytes',
    'content_hash',
    'status',
    'error',
    'page_count',
    'chunk_count',
    'embedding_model',
    'processed_at',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /**
     * @return HasMany<Chunk, $this>
     */
    public function chunks(): HasMany
    {
        return $this->hasMany(Chunk::class);
    }

    /**
     * @param  Builder<Document>  $query
     */
    public function scopeReady(Builder $query): void
    {
        $query->where('status', DocumentStatus::Ready);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'processed_at' => 'datetime',
            'size_bytes' => 'integer',
            'page_count' => 'integer',
            'chunk_count' => 'integer',
        ];
    }
}
