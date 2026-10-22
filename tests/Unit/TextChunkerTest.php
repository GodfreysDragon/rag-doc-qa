<?php

namespace Tests\Unit;

use App\Services\Rag\Chunking\TextChunker;
use App\Services\Rag\Extraction\ExtractedDocument;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class TextChunkerTest extends TestCase
{
    public function test_short_text_becomes_a_single_chunk(): void
    {
        $chunker = new TextChunker(size: 200, overlap: 40);

        $this->assertSame(['A short paragraph.'], $chunker->splitText('  A short paragraph.  '));
    }

    public function test_empty_text_produces_no_chunks(): void
    {
        $this->assertSame([], (new TextChunker(200, 40))->splitText("  \n\n "));
    }

    public function test_chunks_respect_the_size_limit(): void
    {
        $chunker = new TextChunker(size: 300, overlap: 60);
        $text = implode("\n\n", array_map(
            fn (int $i): string => "Paragraph {$i}. ".str_repeat("Sentence number {$i} talks about something. ", 6),
            range(1, 12),
        ));

        $chunks = $chunker->splitText($text);

        $this->assertGreaterThan(5, count($chunks));

        foreach ($chunks as $chunk) {
            $this->assertLessThanOrEqual(300, mb_strlen($chunk));
        }
    }

    public function test_consecutive_chunks_overlap(): void
    {
        $chunker = new TextChunker(size: 200, overlap: 80);
        $sentences = array_map(fn (int $i): string => "Fact {$i} is recorded here.", range(1, 40));

        $chunks = $chunker->splitText(implode(' ', $sentences));

        for ($i = 1; $i < count($chunks); $i++) {
            $previousTail = mb_substr($chunks[$i - 1], -20);
            $this->assertStringContainsString(
                trim($previousTail),
                $chunks[$i],
                "Chunk {$i} should start with the tail of chunk ".($i - 1),
            );
        }
    }

    public function test_no_content_is_lost(): void
    {
        $chunker = new TextChunker(size: 150, overlap: 30);
        $words = array_map(fn (int $i): string => "word{$i}", range(1, 300));

        $joined = implode(' ', $chunker->splitText(implode(' ', $words)));

        foreach ($words as $word) {
            $this->assertMatchesRegularExpression("/\\b{$word}\\b/", $joined);
        }
    }

    public function test_unbroken_text_is_hard_split(): void
    {
        $unbroken = implode('', range(1, 200));

        $chunks = (new TextChunker(size: 100, overlap: 10))->splitText($unbroken);

        $this->assertCount((int) ceil(mb_strlen($unbroken) / 100), $chunks);
        $this->assertSame(100, mb_strlen($chunks[0]));
        $this->assertSame($unbroken, implode('', $chunks));
    }

    public function test_chunks_keep_their_page_number_and_never_cross_pages(): void
    {
        $document = new ExtractedDocument([
            ['page' => 1, 'text' => str_repeat('Alpha page content. ', 30)],
            ['page' => 2, 'text' => 'Beta page content.'],
        ], pageCount: 2);

        $chunks = (new TextChunker(size: 200, overlap: 40))->chunk($document);

        $pageTwo = array_values(array_filter($chunks, fn ($chunk) => $chunk->pageNumber === 2));
        $this->assertCount(1, $pageTwo);
        $this->assertSame('Beta page content.', $pageTwo[0]->content);

        foreach ($chunks as $chunk) {
            if ($chunk->pageNumber === 1) {
                $this->assertStringNotContainsString('Beta', $chunk->content);
            }
            $this->assertSame((int) ceil(mb_strlen($chunk->content) / 4), $chunk->tokenCount);
        }
    }

    public function test_overlap_must_be_smaller_than_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TextChunker(size: 100, overlap: 100);
    }
}
