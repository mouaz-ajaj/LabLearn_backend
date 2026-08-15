<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('extracted_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('extraction_job_id')->constrained()->cascadeOnDelete();
            $table->foreignId('report_id')->constrained()->cascadeOnDelete();
            $table->text('raw_label')->nullable();
            $table->text('raw_value')->nullable();
            $table->text('raw_unit')->nullable();
            $table->text('raw_reference')->nullable();
            $table->unsignedSmallInteger('page')->nullable();
            $table->json('bbox_json')->nullable();
            $table->decimal('confidence', 6, 5)->nullable();
            $table->json('raw_row_json')->nullable();
            $table->timestamps();

            $table->index(['report_id', 'extraction_job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extracted_results');
    }
};
