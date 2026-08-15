<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('quiz_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('quiz_question_snapshot_id')->constrained()->cascadeOnDelete();
            $table->string('selected_option_id', 64);
            $table->boolean('is_correct');
            $table->timestamp('answered_at');
            $table->timestamps();

            // One immutable answer per question per session - see Submitted Answer Policy.
            $table->unique(['quiz_session_id', 'quiz_question_snapshot_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_answers');
    }
};
