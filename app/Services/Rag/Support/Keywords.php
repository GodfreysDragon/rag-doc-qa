<?php

namespace App\Services\Rag\Support;

final class Keywords
{
    /** @var list<string> */
    private const STOP_WORDS = [
        'a', 'about', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'by', 'can', 'could', 'did', 'do', 'does',
        'for', 'from', 'give', 'has', 'have', 'how', 'i', 'in', 'is', 'it', 'its', 'me', 'my', 'of', 'on', 'or',
        'our', 'please', 'should', 'tell', 'that', 'the', 'their', 'there', 'these', 'this', 'those', 'to',
        'was', 'we', 'were', 'what', 'when', 'where', 'which', 'who', 'why', 'will', 'with', 'would', 'you', 'your',
    ];

    /**
     * Distinct, lower-cased alphanumeric search terms with stop words removed.
     *
     * @return list<string>
     */
    public static function extract(string $text, int $limit = 16): array
    {
        return array_slice(array_values(array_unique(self::tokens($text))), 0, $limit);
    }

    /**
     * Every term occurrence in order (duplicates kept), for term-frequency counting.
     *
     * @return list<string>
     */
    public static function tokens(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        return array_values(array_filter(
            $matches[0],
            fn (string $word): bool => mb_strlen($word) > 1 && ! in_array($word, self::STOP_WORDS, true),
        ));
    }
}
