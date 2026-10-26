<?php

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->slug(3).'.txt';

        return [
            'user_id' => User::factory(),
            'title' => str($name)->beforeLast('.')->headline()->toString(),
            'original_name' => $name,
            'path' => 'documents/'.$name,
            'mime_type' => 'text/plain',
            'size_bytes' => fake()->numberBetween(1_000, 500_000),
            'content_hash' => hash('sha256', fake()->unique()->uuid()),
            'status' => DocumentStatus::Pending,
            'chunk_count' => 0,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Ready,
            'processed_at' => now(),
        ]);
    }

    public function failed(string $error = 'Could not extract text.'): static
    {
        return $this->state(fn (): array => [
            'status' => DocumentStatus::Failed,
            'error' => $error,
        ]);
    }
}
