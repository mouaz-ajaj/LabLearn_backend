<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verified_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('verified_result_set_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_extracted_result_id')->nullable()->constrained('extracted_results')->nullOnDelete();
            $table->text('label');
            $table->text('value');
            $table->text('unit')->nullable();
            $table->text('reference_range')->nullable();
            $table->unsignedSmallInteger('page')->nullable();
            $table->decimal('original_confidence', 6, 5)->nullable();
            $table->boolean('was_added_manually')->default(false);
            $table->boolean('was_modified')->default(false);
            $table->unsignedSmallInteger('display_order');
            $table->timestamps();

            $table->unique(['verified_result_set_id', 'display_order'], 'verified_results_set_order_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verified_results');
    }
};
