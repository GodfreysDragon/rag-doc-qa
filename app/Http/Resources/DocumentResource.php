<?php

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'original_name' => $this->original_name,
            'extension' => strtolower(pathinfo($this->original_name, PATHINFO_EXTENSION)),
            'size_bytes' => $this->size_bytes,
            'status' => $this->status,
            'error' => $this->error,
            'page_count' => $this->page_count,
            'chunk_count' => $this->chunk_count,
            'embedding_model' => $this->embedding_model,
            'processed_at' => $this->processed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
