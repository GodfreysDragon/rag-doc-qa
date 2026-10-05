<?php

namespace App\Services\Rag\Support;

use InvalidArgumentException;

/**
 * Small vector-math helpers. Every embedding is L2-normalised before it is
 * stored, so cosine similarity reduces to a plain dot product.
 */
final class Vector
{
    /**
     * @param  list<float>  $vector
     * @return list<float>
     */
    public static function normalize(array $vector): array
    {
        $norm = sqrt(array_sum(array_map(fn (float $value): float => $value * $value, $vector)));

        if ($norm == 0.0) {
            return $vector;
        }

        return array_map(fn (float $value): float => $value / $norm, $vector);
    }

    /**
     * Cosine similarity of two already-normalised vectors.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public static function dot(array $a, array $b): float
    {
        if (count($a) !== count($b)) {
            throw new InvalidArgumentException(sprintf(
                'Vector dimensions differ (%d vs %d). Did you change embedding model without re-indexing?',
                count($a),
                count($b),
            ));
        }

        $sum = 0.0;

        foreach ($a as $index => $value) {
            $sum += $value * $b[$index];
        }

        return $sum;
    }

    /**
     * Serialise a vector into pgvector's text input format: "[0.1,0.2,...]".
     *
     * @param  list<float>  $vector
     */
    public static function toPgvector(array $vector): string
    {
        return '['.implode(',', array_map(fn (float $value): string => sprintf('%.8F', $value), $vector)).']';
    }
}
