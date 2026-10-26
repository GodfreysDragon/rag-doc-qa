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

    public function test_a_chunk_never_ends_on_a_heading(): void
    {
        $text = "# Policy\n\n".str_repeat('Intro sentence here. ', 8)."\n\n## Using your own car\n\nFines for traffic violations are never reimbursed.";

        $chunks = (new TextChunker(size: 200, overlap: 40))->splitText($text);

        foreach ($chunks as $chunk) {
            $this->assertDoesNotMatchRegularExpression('/^#+ .*\z/m', $chunk, 'A chunk ends on a heading.');
        }

        $this->assertSame("Policy\n\n## Using your own car\n\nFines for traffic violations are never reimbursed.", end($chunks));
    }

    public function test_small_sections_are_packed_together_whole(): void
    {
        $text = "# Policy\n\n## Hotels\n\nUp to 150 euros a night.\n\n## Meals\n\nUp to 40 euros a day.\n\n## Car\n\n".str_repeat('Mileage is paid per kilometre. ', 3);

        $chunks = (new TextChunker(size: 120, overlap: 20))->splitText($text);

        $this->assertSame([
            "# Policy\n\n## Hotels\n\nUp to 150 euros a night.\n\n## Meals\n\nUp to 40 euros a day.",
            "Policy\n\n## Car\n\n".trim(str_repeat('Mileage is paid per kilometre. ', 3)),
        ], $chunks);
    }

    public function test_pieces_of_a_long_section_carry_its_heading_trail(): void
    {
        $sentences = array_map(fn (int $i): string => "Rule {$i} applies to every claim.", range(1, 20));
        $text = "# Expenses\n\n## Claims\n\n".implode(' ', $sentences);

        $chunks = (new TextChunker(size: 200, overlap: 40))->splitText($text);

        $this->assertGreaterThan(2, count($chunks));
        $this->assertStringStartsWith("# Expenses\n\n## Claims\n\nRule 1 ", $chunks[0]);

        foreach (array_slice($chunks, 1) as $chunk) {
            $this->assertStringStartsWith("Expenses › Claims\n\n", $chunk);
            $this->assertLessThanOrEqual(200, mb_strlen($chunk));
        }

        $joined = implode(' ', $chunks);

        foreach ($sentences as $sentence) {
            $this->assertStringContainsString($sentence, $joined);
        }
    }

    public function test_the_trail_only_holds_headings_above_the_section(): void
    {
        $text = "# Guide\n\n## Setup\n\n### Wi-Fi\n\nHold the button.\n\n## Errors\n\n".str_repeat('E-12 means the wheel is stuck. ', 8);

        $chunks = (new TextChunker(size: 120, overlap: 20))->splitText($text);

        $errors = array_values(array_filter($chunks, fn (string $chunk): bool => str_contains($chunk, '## Errors')));
        $this->assertCount(1, $errors);
        $this->assertStringStartsWith("Guide\n\n## Errors\n\n", $errors[0]);
        $this->assertStringContainsString("Guide › Errors\n\n", $chunks[array_key_last($chunks)]);
    }

    public function test_overlap_must_be_smaller_than_size(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new TextChunker(size: 100, overlap: 100);
    }
}
