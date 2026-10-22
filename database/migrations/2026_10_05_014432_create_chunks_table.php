<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * On PostgreSQL the embedding is a native pgvector column with an HNSW
     * index (cosine distance), and a generated tsvector column backs keyword
     * search. Other drivers store the embedding as JSON and fall back to an
     * exact (brute-force) cosine scan in PHP.
     */
    public function up(): void
    {
        $isPostgres = DB::connection()->getDriverName() === 'pgsql';

        if ($isPostgres) {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('chunks', function (Blueprint $table) use ($isPostgres) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('chunk_index');
            $table->text('content');
            $table->unsignedInteger('page_number')->nullable();
            $table->unsignedInteger('token_count');

            if (! $isPostgres) {
                $table->longText('embedding')->nullable();
            }

            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
        });

        if ($isPostgres) {
            $dimensions = (int) config('rag.embeddings.dimensions');

            DB::statement("ALTER TABLE chunks ADD COLUMN embedding vector({$dimensions})");
            DB::statement('CREATE INDEX chunks_embedding_hnsw_idx ON chunks USING hnsw (embedding vector_cosine_ops)');

            DB::statement("ALTER TABLE chunks ADD COLUMN search_vector tsvector GENERATED ALWAYS AS (to_tsvector('english', content)) STORED");
            DB::statement('CREATE INDEX chunks_search_vector_gin_idx ON chunks USING gin (search_vector)');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chunks');
    }
};
