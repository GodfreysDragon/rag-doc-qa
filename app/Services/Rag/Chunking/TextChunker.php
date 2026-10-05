<?php

namespace App\Services\Rag\Chunking;

use App\Services\Rag\Extraction\ExtractedDocument;
use InvalidArgumentException;

/**
 * Recursive character splitter.
 *
 * Tries to cut on the most meaningful boundary first (paragraph → line →
 * sentence → word) and only falls back to a finer separator for pieces that
 * are still too long. Pieces are then greedily packed into chunks of up to
 * `size` characters, and each new chunk re-includes up to `overlap`
 * characters from the end of the previous one.
 *
 * Chunks never cross a page boundary, so every chunk has a single page to cite.
 */
class TextChunker
{
    /** @var list<string> */
    private const SEPARATORS = ["\n\n", "\n", '. ', '? ', '! ', '; ', ', ', ' '];

    public function __construct(
        private readonly int $size = 1200,
        private readonly int $overlap = 200,
    ) {
        if ($overlap >= $size) {
            throw new InvalidArgumentException('Chunk overlap must be smaller than chunk size.');
        }
    }

    /**
     * @return list<ChunkData>
     */
    public function chunk(ExtractedDocument $document): array
    {
        $chunks = [];

        foreach ($document->segments as $segment) {
            foreach ($this->splitText($segment['text']) as $content) {
                $chunks[] = new ChunkData(
                    content: $content,
                    pageNumber: $segment['page'],
                    tokenCount: self::estimateTokens($content),
                );
            }
        }

        return $chunks;
    }

    /**
     * @return list<string>
     */
    public function splitText(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        return $this->merge($this->split($text, self::SEPARATORS));
    }

    /**
     * Rough token estimate (~4 characters per token for English prose).
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Break text into pieces no longer than the chunk size. Separators are kept
     * attached to the preceding piece so merging restores the original text.
     *
     * @param  list<string>  $separators
     * @return list<string>
     */
    private function split(string $text, array $separators): array
    {
        if (mb_strlen($text) <= $this->size) {
            return [$text];
        }

        foreach ($separators as $position => $separator) {
            if (! str_contains($text, $separator)) {
                continue;
            }

            $parts = explode($separator, $text);
            $lastIndex = count($parts) - 1;
            $finer = array_slice($separators, $position + 1);
            $pieces = [];

            foreach ($parts as $index => $part) {
                $piece = $index < $lastIndex ? $part.$separator : $part;

                if ($piece === '') {
                    continue;
                }

                array_push($pieces, ...$this->split($piece, $finer));
            }

            return $pieces;
        }

        return mb_str_split($text, $this->size);
    }

    /**
     * Greedily pack pieces into chunks, carrying a tail of the previous chunk
     * forward as overlap.
     *
     * @param  list<string>  $pieces
     * @return list<string>
     */
    private function merge(array $pieces): array
    {
        $chunks = [];
        $window = [];
        $windowLength = 0;

        foreach ($pieces as $piece) {
            $pieceLength = mb_strlen($piece);

            if ($windowLength + $pieceLength > $this->size && $window !== []) {
                $chunks[] = trim(implode('', $window));

                while ($window !== [] && ($windowLength > $this->overlap || $windowLength + $pieceLength > $this->size)) {
                    $windowLength -= mb_strlen(array_shift($window));
                }
            }

            $window[] = $piece;
            $windowLength += $pieceLength;
        }

        if ($window !== []) {
            $chunks[] = trim(implode('', $window));
        }

        return array_values(array_unique(array_filter($chunks, fn (string $chunk): bool => $chunk !== '')));
    }
}
