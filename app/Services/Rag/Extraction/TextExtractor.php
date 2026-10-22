<?php

namespace App\Services\Rag\Extraction;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\Element\AbstractElement;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\Element\Text;
use PhpOffice\PhpWord\Element\TextBreak;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;
use Throwable;

class TextExtractor
{
    /**
     * @throws UnprocessableDocumentException
     */
    public function extract(string $absolutePath, string $extension): ExtractedDocument
    {
        try {
            $document = match (strtolower($extension)) {
                'pdf' => $this->extractPdf($absolutePath),
                'docx' => $this->extractDocx($absolutePath),
                'txt', 'md' => new ExtractedDocument([
                    ['page' => null, 'text' => $this->normalize($this->readUtf8($absolutePath))],
                ]),
                default => throw new UnprocessableDocumentException("Unsupported file type [{$extension}]."),
            };
        } catch (UnprocessableDocumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new UnprocessableDocumentException('The file could not be parsed: '.$exception->getMessage(), previous: $exception);
        }

        if ($document->isEmpty()) {
            throw new UnprocessableDocumentException('No extractable text found. Scanned PDFs need OCR before they can be indexed.');
        }

        return $document;
    }

    private function readUtf8(string $absolutePath): string
    {
        $contents = (string) file_get_contents($absolutePath);
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;

        return mb_check_encoding($contents, 'UTF-8')
            ? $contents
            : mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
    }

    private function extractPdf(string $absolutePath): ExtractedDocument
    {
        $pdf = (new PdfParser)->parseFile($absolutePath);
        $segments = [];

        foreach ($pdf->getPages() as $index => $page) {
            $text = $this->normalize($page->getText());

            if ($text !== '') {
                $segments[] = ['page' => $index + 1, 'text' => $text];
            }
        }

        return new ExtractedDocument($segments, count($pdf->getPages()));
    }

    private function extractDocx(string $absolutePath): ExtractedDocument
    {
        $phpWord = IOFactory::load($absolutePath, 'Word2007');
        $lines = [];

        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                $lines[] = $this->elementText($element);
            }
        }

        return new ExtractedDocument([
            ['page' => null, 'text' => $this->normalize(implode("\n", $lines))],
        ]);
    }

    /**
     * Walk PhpWord's element tree. Paragraphs become lines, table rows become
     * " | "-separated lines so tabular data stays readable to the LLM.
     */
    private function elementText(AbstractElement $element): string
    {
        if ($element instanceof Text) {
            return (string) $element->getText();
        }

        if ($element instanceof TextBreak) {
            return "\n";
        }

        if ($element instanceof Table) {
            $rows = [];

            foreach ($element->getRows() as $row) {
                $cells = [];

                foreach ($row->getCells() as $cell) {
                    $cells[] = trim($this->elementText($cell));
                }

                $rows[] = implode(' | ', $cells);
            }

            return implode("\n", $rows)."\n";
        }

        if ($element instanceof AbstractContainer) {
            $parts = array_map(
                fn (AbstractElement $child): string => $this->elementText($child),
                $element->getElements(),
            );

            return implode('', $parts)."\n";
        }

        return '';
    }

    /**
     * Collapse runs of spaces, re-join words hyphenated across line breaks, and
     * squash 3+ newlines down to a paragraph break.
     */
    private function normalize(string $text): string
    {
        $text = str_replace(["\r\n", "\r", "\f"], "\n", $text);
        $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/(\w)-\n(\w)/u', '$1$2', $text) ?? $text;
        $text = preg_replace('/ *\n */', "\n", $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }
}
