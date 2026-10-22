<?php

namespace App\Services\Rag\Chunking;

final readonly class ChunkData
{
    public function __construct(
        public string $content,
        public ?int $pageNumber,
        public int $tokenCount,
    ) {}
}
