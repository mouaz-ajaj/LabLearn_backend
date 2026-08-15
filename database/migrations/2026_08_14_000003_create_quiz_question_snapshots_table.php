<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_question_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('sequence');
            $table->string('question_category', 24);

            // Immutable copy of exactly what was shown - later Question Bank edits,
            // KBS rule updates, or Case-Specific template changes must never alter this row.
            $table->json('question_text_json');
            $table->json('options_json');
            $table->json('option_order_json');
            $table->string('correct_option_id', 64);
            $table->json('explanation_json');

            $table->foreignId('source_question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->unsignedSmallInteger('source_question_version')->nullable();
            $table->string('case_specific_template_id', 64)->nullable();
            $table->unsignedSmallInteger('case_specific_template_version')->nullable();
            $table->json('evidence_json')->nullable();
            $table->string('rule_code', 160)->nullable();
            $table->json('analyte_refs_json')->nullable();
            $table->timestamps();

            $table->unique(['quiz_session_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_question_snapshots');
    }
};
