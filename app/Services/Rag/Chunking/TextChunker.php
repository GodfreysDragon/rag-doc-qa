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
 * Markdown headings are section boundaries. Whole sections are packed into
 * chunks so a chunk never ends on a heading that belongs to the next one, and
 * a chunk that starts inside a section (or under a parent heading) begins
 * with the heading trail, e.g. "Expenses › Using your own car".
 *
 * Chunks never cross a page boundary, so every chunk has a single page to cite.
 */
class TextChunker
{
    /** @var list<string> */
    private const SEPARATORS = ["\n\n", "\n", '. ', '? ', '! ', '; ', ', ', ' '];

    /** A Markdown ATX heading line: its level and its title. */
    private const HEADING = '/^(#{1,6})[ \t]+(.+?)[ \t#]*$/m';

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

        $chunks = [];
        $packed = '';

        foreach ($this->sections($text) as $section) {
            if ($packed !== '' && mb_strlen($packed) + 2 + mb_strlen($section['text']) <= $this->size) {
                $packed .= "\n\n".$section['text'];

                continue;
            }

            if ($packed !== '') {
                $chunks[] = $packed;
                $packed = '';
            }

            $opening = self::withTrail($section['context'], $section['text']);

            if (mb_strlen($opening) <= $this->size) {
                $packed = $opening;

                continue;
            }

            array_push($chunks, ...$this->splitSection($section));
        }

        if ($packed !== '') {
            $chunks[] = $packed;
        }

        return array_values(array_unique($chunks));
    }

    /**
     * Rough token estimate (~4 characters per token for English prose).
     */
    public static function estimateTokens(string $text): int
    {
        return (int) ceil(mb_strlen($text) / 4);
    }

    /**
     * Cut text at Markdown headings. Each section keeps the trail of headings
     * above it (`context`) and, for pieces cut from its middle, that trail plus
     * its own heading (`continuation`). A heading with no text of its own is
     * joined to the section after it. Text without headings is one section.
     *
     * @return list<array{text: string, context: list<string>, continuation: list<string>}>
     */
    private function sections(string $text): array
    {
        preg_match_all(self::HEADING, $text, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $bounds = array_map(fn (array $match): array => [
            'offset' => $match[0][1],
            'level' => strlen($match[1][0]),
            'title' => trim($match[2][0]),
        ], $matches);

        if ($bounds === [] || $bounds[0]['offset'] > 0) {
            array_unshift($bounds, ['offset' => 0, 'level' => null, 'title' => null]);
        }

        $sections = [];
        $open = [];
        $pending = null;

        foreach ($bounds as $index => $bound) {
            $end = $bounds[$index + 1]['offset'] ?? strlen($text);
            $body = trim(substr($text, $bound['offset'], $end - $bound['offset']));

            if ($bound['level'] !== null) {
                $open = array_filter($open, fn (int $level): bool => $level < $bound['level'], ARRAY_FILTER_USE_KEY);
            }

            $section = ['text' => $body, 'context' => array_values($open), 'continuation' => array_values($open)];

            if ($bound['level'] !== null) {
                $open[$bound['level']] = $bound['title'];
                $section['continuation'] = array_values($open);
            }

            if ($pending !== null) {
                $section['text'] = $pending['text']."\n\n".$section['text'];
                $section['context'] = $pending['context'];
                $pending = null;
            }

            if ($bound['level'] !== null && ! str_contains($body, "\n") && isset($bounds[$index + 1])) {
                $pending = $section;

                continue;
            }

            if ($section['text'] !== '') {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * Split a section too long for one chunk, with overlap between its pieces.
     * The first piece opens with the trail above the section; later pieces,
     * which have lost the section's heading, open with the trail including it.
     *
     * @param  array{text: string, context: list<string>, continuation: list<string>}  $section
     * @return list<string>
     */
    private function splitSection(array $section): array
    {
        $trailLength = max(
            mb_strlen(self::withTrail($section['context'], '')),
            mb_strlen(self::withTrail($section['continuation'], '')),
        );

        // A trail too long to leave room for real text is dropped rather than squeezing the chunk.
        if ($this->size - $trailLength <= $this->overlap) {
            $section['context'] = $section['continuation'] = [];
            $trailLength = 0;
        }

        $size = $this->size - $trailLength;
        $pieces = $this->merge($this->split($section['text'], self::SEPARATORS, $size), $size);

        return array_map(
            fn (string $piece, int $index): string => self::withTrail($index === 0 ? $section['context'] : $section['continuation'], $piece),
            $pieces,
            array_keys($pieces),
        );
    }

    /**
     * @param  list<string>  $trail
     */
    private static function withTrail(array $trail, string $text): string
    {
        return $trail === [] ? $text : implode(' › ', $trail)."\n\n".$text;
    }

    /**
     * Break text into pieces no longer than `size`. Separators are kept
     * attached to the preceding piece so merging restores the original text.
     *
     * @param  list<string>  $separators
     * @return list<string>
     */
    private function split(string $text, array $separators, int $size): array
    {
        if (mb_strlen($text) <= $size) {
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

                array_push($pieces, ...$this->split($piece, $finer, $size));
            }

            return $pieces;
        }

        return mb_str_split($text, $size);
    }

    /**
     * Greedily pack pieces into chunks of up to `size` characters, carrying a
     * tail of the previous chunk forward as overlap.
     *
     * @param  list<string>  $pieces
     * @return list<string>
     */
    private function merge(array $pieces, int $size): array
    {
        $chunks = [];
        $window = [];
        $windowLength = 0;

        foreach ($pieces as $piece) {
            $pieceLength = mb_strlen($piece);

            if ($windowLength + $pieceLength > $size && $window !== []) {
                $chunks[] = trim(implode('', $window));

                while ($window !== [] && ($windowLength > $this->overlap || $windowLength + $pieceLength > $size)) {
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
