<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rule_traces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->string('rule_code', 160);
            $table->string('rule_version', 32);
            $table->boolean('fired');
            $table->json('conditions_json');
            $table->json('evidence_json');
            $table->json('conclusion_codes_json');
            $table->timestamps();

            $table->unique(['analysis_id', 'rule_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_traces');
    }
};
