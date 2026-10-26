<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Documents belong to a user, and duplicate detection becomes per user:
     * two people may upload the same file. The column is nullable so existing
     * installs keep their rows (they are then visible to nobody until assigned)
     * and the evaluation corpus can be indexed without an owner.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
            $table->dropUnique(['content_hash']);
            $table->unique(['user_id', 'content_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['user_id', 'content_hash']);
            $table->dropConstrainedForeignId('user_id');
            $table->unique('content_hash');
        });
    }
};
