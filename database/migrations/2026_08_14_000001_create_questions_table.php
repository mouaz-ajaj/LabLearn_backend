<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table): void {
            $table->id();
            $table->string('category', 32);
            $table->string('type', 24)->default('GENERAL');
            $table->json('question_text_json');
            $table->json('options_json');
            $table->string('correct_option_id', 64);
            $table->json('explanation_json');
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('content_version')->default(1);
            $table->string('review_status', 16)->default('DRAFT');
            $table->timestamps();

            $table->index(['category', 'type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
