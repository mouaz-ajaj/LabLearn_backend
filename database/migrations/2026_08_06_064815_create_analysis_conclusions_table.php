<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_conclusions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->string('conclusion_code', 120);
            $table->string('level', 32);
            $table->json('title_json');
            $table->json('summary_json');
            $table->json('evidence_json');
            $table->json('rule_codes_json');
            $table->unsignedSmallInteger('display_order');
            $table->timestamps();

            $table->unique(['analysis_id', 'conclusion_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_conclusions');
    }
};
