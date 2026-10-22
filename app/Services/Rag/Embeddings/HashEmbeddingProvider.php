<?php

namespace App\Services\Rag\Embeddings;

use App\Services\Rag\Support\Vector;

/**
 * Offline baseline using the "hashing trick": each word stem and adjacent word
 * pair is hashed to a bucket, with a hashed sign to reduce collision bias,
 * then the vector is log-scaled and normalised.
 *
 * It captures lexical overlap only — no synonyms, no semantics — which makes
 * it a useful control to compare real embedding models against, and lets the
 * whole pipeline run (and be tested) without any API key.
 */
class HashEmbeddingProvider implements EmbeddingProvider
{
    /** @var list<string> */
    private const STOP_WORDS = [
        'a', 'an', 'and', 'are', 'as', 'at', 'be', 'by', 'do', 'does', 'for', 'from', 'how', 'in', 'is', 'it',
        'of', 'on', 'or', 'that', 'the', 'this', 'to', 'was', 'what', 'when', 'where', 'which', 'who', 'why',
        'with', 'you', 'your', 'can', 'i', 'me', 'my', 'we', 'our',
    ];

    public function __construct(private readonly int $dimensions = 1024) {}

    public function embedDocuments(array $texts): array
    {
        return array_map(fn (string $text): array => $this->embed($text), $texts);
    }

    public function embedQuery(string $text): array
    {
        return $this->embed($text);
    }

    public function modelName(): string
    {
        return 'hash:'.$this->dimensions;
    }

    public function dimensions(): int
    {
        return $this->dimensions;
    }

    /**
     * @return list<string>
     */
    public static function terms(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $matches);

        $terms = array_filter(
            $matches[0],
            fn (string $word): bool => mb_strlen($word) > 1 && ! in_array($word, self::STOP_WORDS, true),
        );

        return array_values(array_map(self::stem(...), $terms));
    }

    /**
     * @return list<float>
     */
    private function embed(string $text): array
    {
        $vector = array_fill(0, $this->dimensions, 0.0);
        $terms = self::terms($text);
        $features = $terms;

        for ($i = 0, $last = count($terms) - 1; $i < $last; $i++) {
            $features[] = $terms[$i].'_'.$terms[$i + 1];
        }

        foreach ($features as $feature) {
            $hash = crc32($feature);
            $sign = ($hash & 1) === 1 ? 1.0 : -1.0;
            $vector[($hash >> 1) % $this->dimensions] += $sign;
        }

        $vector = array_map(
            fn (float $value): float => $value === 0.0 ? 0.0 : ($value <=> 0) * log(1 + abs($value)),
            $vector,
        );

        return Vector::normalize($vector);
    }

    /**
     * Very light suffix stripping so "embeddings" and "embedding" collide.
     */
    private static function stem(string $word): string
    {
        if (str_ends_with($word, 'ss')) {
            return $word;
        }

        foreach (['ingly', 'ings', 'edly', 'ing', 'ies', 'ed', 's'] as $suffix) {
            if (mb_strlen($word) > mb_strlen($suffix) + 3 && str_ends_with($word, $suffix)) {
                return mb_substr($word, 0, -mb_strlen($suffix)).($suffix === 'ies' ? 'y' : '');
            }
        }

        return $word;
    }
}
