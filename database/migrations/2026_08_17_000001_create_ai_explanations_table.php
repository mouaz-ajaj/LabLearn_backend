<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4E: a versioned cache of AI-generated explanations, kept entirely separate
 * from the deterministic `analyses`/`analysis_conclusions`/`rule_traces` tables - this
 * migration never touches those. `task_type` keeps this table safely shared with any
 * future AI presentation feature without mixing data (only RESULT_EXPLANATION is used
 * today). The unique index is the cache identity: the same analysis/language/role/
 * prompt/schema combination is generated at most once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_explanations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->string('task_type', 32);
            $table->string('language', 5);
            $table->string('role', 16);
            $table->string('schema_version', 8);
            $table->string('prompt_version', 16);
            $table->string('model', 64)->nullable();
            $table->json('content_json');
            // Always 'AVAILABLE' today - a fallback explanation is intentionally never
            // persisted here (see docs/phase-4e-result-explanation.md), so this column
            // exists mainly as forward-compatible schema documentation.
            $table->string('status', 16)->default('AVAILABLE');
            $table->timestamps();

            $table->unique(
                ['analysis_id', 'task_type', 'language', 'role', 'prompt_version', 'schema_version'],
                'ai_explanations_cache_identity',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_explanations');
    }
};
