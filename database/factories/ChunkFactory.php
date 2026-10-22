<?php

namespace Database\Factories;

use App\Models\Chunk;
use App\Models\Document;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Chunk>
 */
class ChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $content = fake()->paragraphs(2, true);

        return [
            'document_id' => Document::factory()->ready(),
            'chunk_index' => fake()->unique()->numberBetween(0, 100_000),
            'content' => $content,
            'page_number' => null,
            'token_count' => (int) ceil(mb_strlen($content) / 4),
        ];
    }
}
