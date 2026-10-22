<?php

namespace Tests\Feature;

use App\Services\Rag\Extraction\TextExtractor;
use App\Services\Rag\Extraction\UnprocessableDocumentException;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;

class TextExtractorTest extends TestCase
{
    /** @var list<string> */
    private array $tempFiles = [];

    protected function tearDown(): void
    {
        array_map('unlink', array_filter($this->tempFiles, 'file_exists'));

        parent::tearDown();
    }

    public function test_it_extracts_pdf_text_page_by_page(): void
    {
        $path = $this->tempFile('pdf', $this->minimalPdf(['Refund policy overview', 'Shipping terms apply']));

        $document = (new TextExtractor)->extract($path, 'pdf');

        $this->assertSame(2, $document->pageCount);
        $this->assertSame(1, $document->segments[0]['page']);
        $this->assertStringContainsString('Refund policy overview', $document->segments[0]['text']);
        $this->assertSame(2, $document->segments[1]['page']);
        $this->assertStringContainsString('Shipping terms apply', $document->segments[1]['text']);
    }

    public function test_it_extracts_docx_paragraphs_and_tables(): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('Employee Handbook');
        $section->addText('Vacation accrues monthly.');
        $table = $section->addTable();
        $table->addRow();
        $table->addCell()->addText('Tier');
        $table->addCell()->addText('Days');
        $table->addRow();
        $table->addCell()->addText('Senior');
        $table->addCell()->addText('30');

        $path = $this->tempFile('docx');
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        $text = (new TextExtractor)->extract($path, 'docx')->segments[0]['text'];

        $this->assertStringContainsString('Employee Handbook', $text);
        $this->assertStringContainsString('Vacation accrues monthly.', $text);
        $this->assertStringContainsString('Senior | 30', $text);
    }

    public function test_it_normalises_whitespace_and_rejoins_hyphenated_words(): void
    {
        $path = $this->tempFile('txt', "\xEF\xBB\xBFThe   embed-\nding model\r\n\r\n\r\n\r\nNext   paragraph");

        $text = (new TextExtractor)->extract($path, 'txt')->segments[0]['text'];

        $this->assertSame("The embedding model\n\nNext paragraph", $text);
    }

    public function test_corrupt_files_raise_a_permanent_failure(): void
    {
        $path = $this->tempFile('pdf', 'definitely not a pdf');

        $this->expectException(UnprocessableDocumentException::class);

        (new TextExtractor)->extract($path, 'pdf');
    }

    private function tempFile(string $extension, ?string $contents = null): string
    {
        $base = tempnam(sys_get_temp_dir(), 'docqa');
        $path = $base.'.'.$extension;
        array_push($this->tempFiles, $base, $path);

        if ($contents !== null) {
            file_put_contents($path, $contents);
        }

        return $path;
    }

    /**
     * Build a valid multi-page PDF with one line of Helvetica text per page.
     *
     * @param  list<string>  $pages
     */
    private function minimalPdf(array $pages): string
    {
        $pageCount = count($pages);
        $fontId = 3 + 2 * $pageCount;
        $objects = [
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => sprintf(
                '<< /Type /Pages /Kids [%s] /Count %d >>',
                implode(' ', array_map(fn (int $i): string => (3 + 2 * $i).' 0 R', array_keys($pages))),
                $pageCount,
            ),
        ];

        foreach ($pages as $i => $text) {
            $pageId = 3 + 2 * $i;
            $stream = "BT /F1 18 Tf 72 720 Td ({$text}) Tj ET";
            $objects[$pageId] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents '.($pageId + 1)." 0 R /Resources << /Font << /F1 {$fontId} 0 R >> >> >>";
            $objects[$pageId + 1] = '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $objects[$fontId] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];

        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref
0 '.(count($objects) + 1)."\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
    }
}
