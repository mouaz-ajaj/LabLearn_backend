<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quiz_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verified_result_set_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('verified_result_set_version');
            $table->foreignId('analysis_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_category', 32);
            $table->string('status', 16)->default('PREPARING');
            $table->string('identity_key', 64)->unique();

            // Preferred/target composition (policy) vs what was actually built for this
            // session - see config/quiz.php and the Dynamic Quiz Size Policy docs.
            $table->unsignedSmallInteger('target_total');
            $table->unsignedSmallInteger('target_general_count');
            $table->unsignedSmallInteger('target_case_specific_count');
            $table->unsignedSmallInteger('actual_total')->default(0);
            $table->unsignedSmallInteger('actual_general_count')->default(0);
            $table->unsignedSmallInteger('actual_case_specific_count')->default(0);

            $table->unsignedSmallInteger('score')->nullable();
            $table->string('prepare_error_code', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_sessions');
    }
};
