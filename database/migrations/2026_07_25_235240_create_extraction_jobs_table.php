<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extraction_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_file_id')->constrained()->cascadeOnDelete();
            $table->string('status', 32)->default('QUEUED');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->string('current_step', 64)->default('QUEUED');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('engine_name', 128);
            $table->string('engine_version', 64)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('safe_error_message')->nullable();
            $table->json('raw_response_json')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'status']);
            $table->index(['report_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_jobs');
    }
};
