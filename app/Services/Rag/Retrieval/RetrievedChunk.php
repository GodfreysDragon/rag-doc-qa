<?php

namespace App\Services\Rag\Retrieval;

final class RetrievedChunk
{
    public function __construct(
        public readonly int $chunkId,
        public readonly int $documentId,
        public readonly string $documentTitle,
        public readonly ?int $pageNumber,
        public readonly string $content,
        public ?float $similarity = null,
        public ?float $keywordScore = null,
        public float $score = 0.0,
    ) {}

    /**
     * @return array{chunk_id: int, document_id: int, document_title: string, page_number: int|null, content: string, similarity: float|null, keyword_score: float|null, score: float}
     */
    public function toArray(): array
    {
        return [
            'chunk_id' => $this->chunkId,
            'document_id' => $this->documentId,
            'document_title' => $this->documentTitle,
            'page_number' => $this->pageNumber,
            'content' => $this->content,
            'similarity' => $this->similarity === null ? null : round($this->similarity, 4),
            'keyword_score' => $this->keywordScore === null ? null : round($this->keywordScore, 4),
            'score' => round($this->score, 5),
        ];
    }
}
