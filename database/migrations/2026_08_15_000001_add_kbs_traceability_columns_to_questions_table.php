<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 'GENERATED_PENDING_REVIEW' (24 chars) exceeds the original 16-char column.
        // Raw MODIFY avoids a doctrine/dbal dependency for a plain length change.
        DB::statement("ALTER TABLE questions MODIFY review_status VARCHAR(32) NOT NULL DEFAULT 'DRAFT'");

        Schema::table('questions', function (Blueprint $table): void {
            // Traceability for KBS-generated General questions (Phase 3B.4). All
            // nullable: manually authored questions (present now or added later) never
            // need to populate these. See app/Services/Quiz/GeneralQuestions/.
            $table->string('source', 32)->nullable()->after('review_status');
            $table->string('source_type', 32)->nullable()->after('source');
            $table->string('source_id', 64)->nullable()->after('source_type');
            $table->string('template_family', 48)->nullable()->after('source_type');
            $table->string('generator_version', 16)->nullable()->after('template_family');
            $table->string('stable_source_key', 191)->nullable()->unique()->after('generator_version');

            $table->index(['source', 'category']);
        });
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table): void {
            $table->dropIndex(['source', 'category']);
            $table->dropUnique(['stable_source_key']);
            $table->dropColumn(['source', 'source_type', 'source_id', 'template_family', 'generator_version', 'stable_source_key']);
        });
    }
};
