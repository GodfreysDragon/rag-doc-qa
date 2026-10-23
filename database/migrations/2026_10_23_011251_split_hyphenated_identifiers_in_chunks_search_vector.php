<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Postgres' default text-search parser reads a code like "E-417" as the
     * word "e" followed by the signed integer "-417", so a query for "417"
     * never matches it. Hyphens are replaced with spaces before indexing,
     * which tokenises content the same way the query side (Keywords) does.
     */
    public function up(): void
    {
        $this->rebuildSearchVector("to_tsvector('english', replace(content, '-', ' '))");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->rebuildSearchVector("to_tsvector('english', content)");
    }

    private function rebuildSearchVector(string $expression): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE chunks DROP COLUMN search_vector');
        DB::statement("ALTER TABLE chunks ADD COLUMN search_vector tsvector GENERATED ALWAYS AS ({$expression}) STORED");
        DB::statement('CREATE INDEX chunks_search_vector_gin_idx ON chunks USING gin (search_vector)');
    }
};
