<?php

namespace App\Services\Rag\Llm;

/**
 * The text of a non-streaming reply and the tokens it cost.
 */
final readonly class Completion
{
    public function __construct(
        public string $text,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
    ) {}

    /**
     * @return array{input_tokens: int, output_tokens: int}
     */
    public function usage(): array
    {
        return ['input_tokens' => $this->inputTokens, 'output_tokens' => $this->outputTokens];
    }
}
