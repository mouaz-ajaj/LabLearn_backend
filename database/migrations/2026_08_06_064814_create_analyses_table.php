<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analyses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('verified_result_set_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('verified_result_set_version');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('report_category', 32);
            $table->string('status', 20)->default('QUEUED');
            $table->string('flow', 32);
            $table->string('identity_key', 64)->unique();
            $table->unsignedSmallInteger('schema_version')->default(1);
            $table->unsignedSmallInteger('input_schema_version')->default(1);
            $table->string('engine_version', 32)->nullable();
            $table->string('ruleset_version', 64);
            $table->string('catalog_version', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('error_code', 64)->nullable();
            $table->string('safe_error_message', 500)->nullable();
            $table->json('normalized_results_json')->nullable();
            $table->json('facts_json')->nullable();
            $table->json('missing_information_json')->nullable();
            $table->json('warnings_json')->nullable();
            $table->json('disclaimer_json')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('raw_kbs_response_json')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'created_at']);
            $table->index(['user_id', 'status']);
            $table->index(['verified_result_set_id', 'ruleset_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analyses');
    }
};
