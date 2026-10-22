<?php

namespace App\Services\Rag\Extraction;

/**
 * Plain text pulled out of an uploaded file, kept page-by-page where the
 * format has pages so answers can cite "page 4" rather than just a file.
 */
final readonly class ExtractedDocument
{
    /**
     * @param  list<array{page: int|null, text: string}>  $segments
     */
    public function __construct(
        public array $segments,
        public ?int $pageCount = null,
    ) {}

    public function isEmpty(): bool
    {
        foreach ($this->segments as $segment) {
            if (trim($segment['text']) !== '') {
                return false;
            }
        }

        return true;
    }
}
